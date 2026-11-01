<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierReturnRequest;
use App\Models\PurchaseOrder;
use App\Models\StockImport;
use App\Models\StockImportItem;
use App\Models\SupplierReturnItem;
use App\Models\SupplierReturnRequest;
use App\Services\PurchaseOrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierReturnRequestController extends Controller
{
    public function __construct(private readonly PurchaseOrderReturnService $returns) {}

    public function managerIndex(): JsonResponse
    {
        return response()->json(['data' => SupplierReturnRequest::with(['purchaseOrder', 'items.stockImportItem.ingredient'])
            ->orderByDesc('id')->get()->map(fn ($request) => $this->present($request))]);
    }

    public function supplierIndex(Request $request): JsonResponse
    {
        $supplierId = $this->supplierId($request);
        return response()->json(['data' => SupplierReturnRequest::with(['purchaseOrder', 'items.stockImportItem.ingredient'])
            ->whereHas('purchaseOrder', fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderByDesc('id')->get()->map(fn ($return) => $this->present($return))]);
    }

    public function store(StoreSupplierReturnRequest $request, int $orderId): JsonResponse
    {
        $data = $request->validated();
        [$created, $isNew] = DB::transaction(function () use ($data, $orderId, $request) {
            $existing = SupplierReturnRequest::with('items')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->purchase_order_id === $orderId, 409, 'Mã yêu cầu đã được sử dụng.');
                $submitted = collect($data['items'])->mapWithKeys(fn ($item) => [(int) $item['stock_import_item_id'] => (int) $item['quantity']])->sortKeys()->all();
                $recorded = $existing->items->mapWithKeys(fn ($item) => [(int) $item->stock_import_item_id => (int) $item->quantity])->sortKeys()->all();
                abort_unless(trim($existing->reason) === trim($data['reason']) && $submitted === $recorded, 409, 'Mã yêu cầu đã được dùng với nội dung khác.');
                return [$existing, false];
            }
            $order = PurchaseOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === PurchaseOrder::STATUS_RECEIVED, 422, 'Đơn chưa được nhập kho.');
            $import = StockImport::where('purchase_order_id', $orderId)->where('status', StockImport::STATUS_CONFIRMED)->firstOrFail();
            $submitted = collect($data['items'])->keyBy('stock_import_item_id');
            $lines = StockImportItem::where('stock_import_id', $import->id)->whereIn('id', $submitted->keys())->get();
            if ($lines->count() !== $submitted->count()) {
                throw ValidationException::withMessages(['items' => 'Dòng trả không thuộc phiếu nhập gốc.']);
            }
            foreach ($lines as $line) {
                $reserved = DB::table('supplier_return_request_items as ri')
                    ->join('supplier_return_requests as r', 'r.id', '=', 'ri.supplier_return_request_id')
                    ->where('ri.stock_import_item_id', $line->id)
                    ->whereIn('r.status', ['REQUESTED', 'ACCEPTED'])
                    ->sum('ri.quantity');
                $available = (int) $line->quantity
                    - (int) SupplierReturnItem::where('stock_import_item_id', $line->id)->sum('quantity')
                    - (int) $reserved;
                if ((int) $submitted[$line->id]['quantity'] > $available) {
                    throw ValidationException::withMessages(['items' => "Số lượng yêu cầu trả vượt mức còn có thể trả ({$available} {$line->unit})."]);
                }
            }
            $return = SupplierReturnRequest::create([
                'code' => 'TMP-'.substr(str_replace('-', '', $data['request_key']), 0, 20),
                'purchase_order_id' => $orderId,
                'stock_import_id' => $import->id,
                'request_key' => $data['request_key'],
                'status' => 'REQUESTED',
                'reason' => trim($data['reason']),
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
            ]);
            $return->update(['code' => sprintf('YCT-%s-%05d', now()->format('Ymd'), $return->id)]);
            foreach ($lines as $line) {
                $return->items()->create([
                    'stock_import_item_id' => $line->id,
                    'quantity' => $submitted[$line->id]['quantity'],
                ]);
            }
            return [$return, true];
        });

        return response()->json(['data' => $this->present($created->load(['purchaseOrder', 'items.stockImportItem.ingredient']))], $isNew ? 201 : 200);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        return $this->transition($id, $request, 'REQUESTED', 'ACCEPTED', true);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        return $this->transition($id, $request, 'REQUESTED', 'REJECTED', true, ['rejection_reason' => trim($data['rejection_reason'])]);
    }

    public function dispatch(Request $request, int $id): JsonResponse
    {
        $return = DB::transaction(function () use ($id, $request) {
            $return = SupplierReturnRequest::with('items')->lockForUpdate()->findOrFail($id);
            abort_unless($return->status === 'ACCEPTED', 409, 'NCC chưa chấp nhận yêu cầu trả hàng.');
            $this->returns->create(PurchaseOrder::findOrFail($return->purchase_order_id), [
                'request_key' => $return->request_key,
                'reason' => $return->reason,
                'items' => $return->items->map(fn ($item) => [
                    'stock_import_item_id' => $item->stock_import_item_id,
                    'quantity' => $item->quantity,
                ])->all(),
            ], $request->user());
            $return->update([
                'status' => 'DISPATCHED',
                'supplier_return_id' => DB::table('supplier_returns')->where('request_key', $return->request_key)->value('id'),
                'dispatched_by' => $request->user()->id,
                'dispatched_at' => now(),
            ]);
            return $return;
        });
        return response()->json(['data' => $this->present($return->load(['purchaseOrder', 'items.stockImportItem.ingredient']))]);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        return $this->transition($id, $request, 'DISPATCHED', 'RECEIVED', true);
    }

    private function transition(int $id, Request $actorRequest, string $from, string $to, bool $supplier, array $extra = []): JsonResponse
    {
        $return = DB::transaction(function () use ($id, $actorRequest, $from, $to, $supplier, $extra) {
            $return = SupplierReturnRequest::with('purchaseOrder')->lockForUpdate()->findOrFail($id);
            if ($supplier) abort_unless((int) $return->purchaseOrder->supplier_id === $this->supplierId($actorRequest), 404);
            abort_unless($return->status === $from, 409, 'Yêu cầu không còn ở trạng thái phù hợp.');
            $at = $to === 'RECEIVED' ? ['received_by' => $actorRequest->user()->id, 'received_at' => now()]
                : ['supplier_responded_by' => $actorRequest->user()->id, 'supplier_responded_at' => now()];
            $return->update(['status' => $to] + $at + $extra);
            return $return;
        });
        return response()->json(['data' => $this->present($return->load(['purchaseOrder', 'items.stockImportItem.ingredient']))]);
    }

    private function supplierId(Request $request): int
    {
        $supplierId = $request->user()->supplier_id;
        abort_unless($supplierId, 403, 'Tài khoản chưa liên kết nhà cung cấp.');
        return (int) $supplierId;
    }

    private function present(SupplierReturnRequest $request): array
    {
        return [
            'id' => $request->id, 'code' => $request->code,
            'purchase_order_id' => $request->purchase_order_id,
            'purchase_order_code' => $request->purchaseOrder?->code,
            'status' => $request->status, 'reason' => $request->reason,
            'rejection_reason' => $request->rejection_reason,
            'supplier_return_id' => $request->supplier_return_id,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'supplier_responded_at' => $request->supplier_responded_at?->toIso8601String(),
            'dispatched_at' => $request->dispatched_at?->toIso8601String(),
            'received_at' => $request->received_at?->toIso8601String(),
            'items' => $request->items->map(fn ($item) => [
                'stock_import_item_id' => $item->stock_import_item_id,
                'ingredient_name' => $item->stockImportItem?->ingredient?->name,
                'quantity' => (int) $item->quantity,
                'unit' => $item->stockImportItem?->unit,
            ])->all(),
        ];
    }
}
