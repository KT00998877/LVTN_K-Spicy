<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptPurchaseOrderRequest;
use App\Http\Requests\ShipPurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierPurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $purchaseOrders) {}

    public function index(Request $request): JsonResponse
    {
        $supplierId = $this->supplierId($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_SUPPLIER_ACCEPTED,
                PurchaseOrder::STATUS_SUPPLIER_REJECTED,
                PurchaseOrder::STATUS_SHIPPED,
                PurchaseOrder::STATUS_RECEIVED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PurchaseOrder::with(['supplier', 'creator', 'items.ingredient', 'items.packaging', 'stockImport.supplierReturns'])
            ->where('supplier_id', $supplierId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_SUPPLIER_ACCEPTED,
                PurchaseOrder::STATUS_SUPPLIER_REJECTED,
                PurchaseOrder::STATUS_SHIPPED,
                PurchaseOrder::STATUS_RECEIVED,
            ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(function (PurchaseOrder $order) {
                return $this->purchaseOrders->present($order) + [
                    'return_count' => $order->stockImport?->supplierReturns->count() ?? 0,
                ];
            })->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->supplierOrder($request, $id);
        $order->load('stockImport.supplierReturns.items.ingredient', 'stockImport.supplierReturns.items.stockImportItem');
        $returns = $order->stockImport?->supplierReturns
            ->sortByDesc('id')
            ->map(fn ($return) => [
                'code' => $return->code,
                'reason' => $return->reason,
                'returned_at' => $return->returned_at?->toIso8601String(),
                'items' => $return->items->map(fn ($item) => [
                    'ingredient_name' => $item->ingredient?->name,
                    'quantity' => (int) $item->quantity,
                    'unit' => $item->stockImportItem?->unit ?? $item->ingredient?->unit,
                ])->values()->all(),
            ])->values()->all() ?? [];

        return response()->json(['data' => $this->purchaseOrders->present($order) + [
            'return_count' => count($returns),
            'return_notices' => $returns,
        ]]);
    }

    public function accept(AcceptPurchaseOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->purchaseOrders->accept(
            $this->supplierOrder($request, $id),
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->purchaseOrders->present($order)]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);
        $order = $this->purchaseOrders->reject(
            $this->supplierOrder($request, $id),
            $data['rejection_reason'],
            $request->user(),
        );

        return response()->json(['data' => $this->purchaseOrders->present($order)]);
    }

    public function ship(ShipPurchaseOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->purchaseOrders->ship(
            $this->supplierOrder($request, $id),
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->purchaseOrders->present($order)]);
    }

    private function supplierOrder(Request $request, int $id): PurchaseOrder
    {
        return PurchaseOrder::where('supplier_id', $this->supplierId($request))
            ->whereIn('status', [
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_SUPPLIER_ACCEPTED,
                PurchaseOrder::STATUS_SUPPLIER_REJECTED,
                PurchaseOrder::STATUS_SHIPPED,
                PurchaseOrder::STATUS_RECEIVED,
            ])
            ->findOrFail($id);
    }

    private function supplierId(Request $request): int
    {
        $supplierId = $request->user()->supplier_id;
        abort_unless($supplierId, 403, 'Tài khoản chưa được liên kết với nhà cung cấp.');

        return (int) $supplierId;
    }
}
