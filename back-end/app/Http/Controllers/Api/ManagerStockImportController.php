<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockImportRequest;
use App\Models\{Ingredient, IngredientPackaging, StockImport, StockImportItem, Supplier};
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ManagerStockImportController — CRUD + confirm phiếu nhập kho.
 *
 *  Endpoints:
 *   GET    /api/staff/manager/stock-imports
 *   POST   /api/staff/manager/stock-imports           (tạo DRAFT)
 *   GET    /api/staff/manager/stock-imports/{id}
 *   PATCH  /api/staff/manager/stock-imports/{id}      (chỉ sửa được DRAFT)
 *   POST   /api/staff/manager/stock-imports/{id}/confirm
 *   DELETE /api/staff/manager/stock-imports/{id}      (chỉ huỷ DRAFT, không hoàn kho)
 */
class ManagerStockImportController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'                 => ['nullable', 'string', 'in:DRAFT,CONFIRMED,CANCELLED'],
            'supplier_id'            => ['nullable', 'integer'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'from'                   => ['nullable', 'date_format:Y-m-d'],
            'to'                     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q'                      => ['nullable', 'string', 'max:100'],
            'per_page'               => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = StockImport::with(['supplier', 'importer', 'confirmer', 'items.ingredient.category']);
        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        if (!empty($filters['supplier_id'])) $q->where('supplier_id', $filters['supplier_id']);
        if (!empty($filters['from']))        $q->where('imported_at', '>=', $filters['from']);
        if (!empty($filters['to']))          $q->where('imported_at', '<=', $filters['to']);
        if (!empty($filters['ingredient_category_id'])) {
            $q->whereHas('items.ingredient', fn ($sub) => $sub->where('ingredient_category_id', $filters['ingredient_category_id']));
        }
        if (!empty($filters['q']))           $q->where(function ($query) use ($filters) {
            $query->where('code', 'like', '%'.$filters['q'].'%')
                ->orWhere('supplier_document_code', 'like', '%'.$filters['q'].'%');
        });

        $page = $q->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($i) => $this->present($i))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function store(StoreStockImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['items'] = $this->normalizeItems($data['items'], $data['supplier_id'] ?? null);
        $code = $data['code'] ?? $this->nextCode();

        $import = DB::transaction(function () use ($data, $code, $request) {
            $total = 0;
            foreach ($data['items'] as $it) {
                $total += round(((float) $it['quantity']) * ((float) $it['unit_price']), 2);
            }
            $header = StockImport::create([
                'code'         => $code,
                'supplier_id'  => $data['supplier_id'] ?? null,
                'supplier_document_code' => $data['supplier_document_code'] ?? null,
                'imported_by'  => $request->user()->id,
                'imported_at'  => $data['imported_at'],
                'note'         => $data['note'] ?? null,
                'status'       => StockImport::STATUS_DRAFT,
                'total_cost'   => $total,
            ]);
            foreach ($data['items'] as $it) {
                $ingredient = Ingredient::findOrFail($it['ingredient_id']);
                StockImportItem::create([
                    'stock_import_id'   => $header->id,
                    'ingredient_id'     => $ingredient->id,
                    'ingredient_packaging_id' => $it['ingredient_packaging_id'],
                    'quantity'          => (float) $it['quantity'],
                    'purchase_quantity' => $it['purchase_quantity'],
                    'purchase_unit'     => $it['purchase_unit'],
                    'conversion_quantity' => $it['conversion_quantity'],
                    'unit'              => $ingredient->unit,
                    'unit_price'        => (float) $it['unit_price'],
                    'purchase_unit_price' => $it['purchase_unit_price'],
                    'subtotal'          => round(((float) $it['quantity']) * ((float) $it['unit_price']), 2),
                    'expiry_date'       => $it['expiry_date']       ?? null,
                    'supplier_lot_code' => $it['supplier_lot_code'] ?? null,
                    'note'              => $it['note']              ?? null,
                ]);
            }
            return $header;
        });

        return response()->json([
            'data' => $this->present($import->fresh(['items', 'supplier', 'importer'])),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $imp = StockImport::with(['supplier', 'importer', 'confirmer', 'items.ingredient'])->findOrFail($id);
        return response()->json(['data' => $this->present($imp)]);
    }

    public function update(StoreStockImportRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $data['items'] = $this->normalizeItems($data['items'], $data['supplier_id'] ?? null);
        return DB::transaction(function () use ($id, $data, $request) {
            /** @var StockImport $imp */
            $imp = StockImport::lockForUpdate()->findOrFail($id);
            if ($imp->status !== StockImport::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ phiếu DRAFT mới được chỉnh sửa.',
                ]);
            }
            $imp->update([
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_document_code' => $data['supplier_document_code'] ?? null,
                'imported_at' => $data['imported_at'],
                'note'        => $data['note'] ?? null,
            ]);
            $imp->items()->delete();
            $total = 0;
            foreach ($data['items'] as $it) {
                $ingredient = Ingredient::findOrFail($it['ingredient_id']);
                $total += round(((float) $it['quantity']) * ((float) $it['unit_price']), 2);
                StockImportItem::create([
                    'stock_import_id'   => $imp->id,
                    'ingredient_id'     => $ingredient->id,
                    'ingredient_packaging_id' => $it['ingredient_packaging_id'],
                    'quantity'          => (float) $it['quantity'],
                    'purchase_quantity' => $it['purchase_quantity'],
                    'purchase_unit'     => $it['purchase_unit'],
                    'conversion_quantity' => $it['conversion_quantity'],
                    'unit'              => $ingredient->unit,
                    'unit_price'        => (float) $it['unit_price'],
                    'purchase_unit_price' => $it['purchase_unit_price'],
                    'subtotal'          => round(((float) $it['quantity']) * ((float) $it['unit_price']), 2),
                    'expiry_date'       => $it['expiry_date']       ?? null,
                    'supplier_lot_code' => $it['supplier_lot_code'] ?? null,
                    'note'              => $it['note']              ?? null,
                ]);
            }
            $imp->update(['total_cost' => $total]);
            return response()->json(['data' => $this->present($imp->fresh(['items', 'supplier', 'importer']))]);
        });
    }

    public function confirm(int $id, Request $request): JsonResponse
    {
        /** @var StockImport $imp */
        $imp = StockImport::findOrFail($id);
        if ($imp->status === StockImport::STATUS_CONFIRMED) {
            return response()->json(['data' => $this->present($imp->fresh(['items', 'supplier', 'importer', 'confirmer']))]);
        }
        $data = $request->validate(['received_at' => ['required', 'date_format:Y-m-d H:i:s']]);
        $receivedAt = Carbon::createFromFormat('Y-m-d H:i:s', $data['received_at'], 'Asia/Ho_Chi_Minh');
        if ($receivedAt->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'Giờ nhận hàng không được ở tương lai.']);
        }
        $latestCount = DB::table('daily_stock_sheets')->where('status', 'CLOSED')->orderByDesc('closed_at')->value('counted_at');
        if ($latestCount && $receivedAt->lessThanOrEqualTo(Carbon::parse($latestCount))) {
            throw ValidationException::withMessages(['received_at' => 'Hàng nhận trước hoặc đúng lần đếm kho đã chốt. Cần đối chiếu với sổ đó trước khi ghi nhập.']);
        }
        $this->inventory->confirmImport($imp, (int) $request->user()->id, $receivedAt);
        return response()->json(['data' => $this->present($imp->fresh(['items', 'supplier', 'importer', 'confirmer']))]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        return DB::transaction(function () use ($id, $request) {
            /** @var StockImport $imp */
            $imp = StockImport::lockForUpdate()->findOrFail($id);
            if ($imp->status !== StockImport::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ có thể huỷ phiếu DRAFT. Phiếu đã CONFIRMED cần quy trình hoàn kho riêng.',
                ]);
            }
            $imp->update([
                'status'             => StockImport::STATUS_CANCELLED,
                'cancelled_by'       => $request->user()->id,
                'cancelled_at'       => now(),
                'cancellation_reason'=> 'Huỷ phiếu DRAFT trước khi xác nhận.',
            ]);
            return response()->json(['data' => $this->present($imp->fresh())]);
        });
    }

    private function nextCode(): string
    {
        $today = Carbon::now('Asia/Ho_Chi_Minh')->format('Ymd');
        $prefix = "PN-{$today}-";
        $latest = StockImport::where('code', 'like', $prefix.'%')->orderByDesc('id')->value('code');
        $next = 1;
        if ($latest && preg_match('/(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeItems(array $items, ?int $supplierId): array
    {
        return collect($items)->map(function (array $item) use ($supplierId) {
            $enteredPurchaseQuantity = $item['purchase_quantity'] ?? null;
            $enteredPurchasePrice = $item['purchase_unit_price'] ?? null;
            $item['ingredient_packaging_id'] = $item['ingredient_packaging_id'] ?? null;
            $item['purchase_quantity'] = null;
            $item['purchase_unit'] = null;
            $item['conversion_quantity'] = null;
            $item['purchase_unit_price'] = null;
            if (!$item['ingredient_packaging_id']) return $item;

            $packaging = IngredientPackaging::where('ingredient_id', $item['ingredient_id'])
                ->where('is_active', true)->findOrFail($item['ingredient_packaging_id']);
            if ($packaging->supplier_id && (int) $packaging->supplier_id !== (int) $supplierId) {
                throw ValidationException::withMessages(['items' => 'Quy cách nhập không thuộc nhà cung cấp đã chọn.']);
            }
            $purchaseQuantity = (float) ($enteredPurchaseQuantity ?? $item['quantity']);
            $purchasePrice = (float) ($enteredPurchasePrice ?? $item['unit_price']);
            $conversion = (float) $packaging->conversion_quantity;
            $item['purchase_quantity'] = $purchaseQuantity;
            $item['purchase_unit'] = $packaging->purchase_unit;
            $item['conversion_quantity'] = $conversion;
            $item['purchase_unit_price'] = $purchasePrice;
            $item['quantity'] = round($purchaseQuantity * $conversion, 3);
            $item['unit_price'] = round($purchasePrice / $conversion, 2);
            return $item;
        })->all();
    }

    private function present(StockImport $imp): array
    {
        return [
            'id'                => (int) $imp->id,
            'code'              => $imp->code,
            'supplier_id'       => $imp->supplier_id ? (int) $imp->supplier_id : null,
            'supplier_name'     => $imp->supplier?->name,
            'imported_by'       => $imp->imported_by ? (int) $imp->imported_by : null,
            'imported_by_name'  => $imp->importer?->name,
            'confirmed_by'      => $imp->confirmed_by ? (int) $imp->confirmed_by : null,
            'confirmed_by_name' => $imp->confirmer?->name,
            'confirmed_at'      => $imp->confirmed_at?->toIso8601String(),
            'cancelled_by'      => $imp->cancelled_by ? (int) $imp->cancelled_by : null,
            'cancelled_at'      => $imp->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $imp->cancellation_reason,
            'imported_at'       => $imp->imported_at?->toDateString(),
            'supplier_document_code' => $imp->supplier_document_code,
            'received_at' => $imp->received_at?->toIso8601String(),
            'status'            => $imp->status,
            'total_cost'        => (float) $imp->total_cost,
            'note'              => $imp->note,
            'items'             => $imp->items->map(fn ($it) => [
                'id'               => (int) $it->id,
                'ingredient_id'    => (int) $it->ingredient_id,
                'ingredient_packaging_id' => $it->ingredient_packaging_id ? (int) $it->ingredient_packaging_id : null,
                'ingredient_code'  => $it->ingredient?->code,
                'ingredient_name'  => $it->ingredient?->name,
                'ingredient_category_id' => $it->ingredient?->ingredient_category_id ? (int) $it->ingredient->ingredient_category_id : null,
                'category_name'    => $it->ingredient?->category?->name,
                'quantity'         => (float) $it->quantity,
                'purchase_quantity'=> $it->purchase_quantity === null ? null : (float) $it->purchase_quantity,
                'purchase_unit'    => $it->purchase_unit,
                'conversion_quantity' => $it->conversion_quantity === null ? null : (float) $it->conversion_quantity,
                'unit'             => $it->unit,
                'unit_price'       => (float) $it->unit_price,
                'purchase_unit_price' => $it->purchase_unit_price === null ? null : (float) $it->purchase_unit_price,
                'subtotal'         => (float) $it->subtotal,
                'expiry_date'      => $it->expiry_date?->toDateString(),
                'supplier_lot_code'=> $it->supplier_lot_code,
                'note'             => $it->note,
                'expiry_status'    => $this->expiryStatus($it->expiry_date),
            ])->all(),
            'created_at'        => $imp->created_at?->toIso8601String(),
            'updated_at'        => $imp->updated_at?->toIso8601String(),
        ];
    }

    private function expiryStatus(?Carbon $date): string
    {
        if (!$date) return 'NONE';
        $today = Carbon::now('Asia/Ho_Chi_Minh')->startOfDay();
        $diff = $today->diffInDays($date, false);
        if ($diff < 0)  return 'EXPIRED';
        if ($diff <= 7) return 'NEAR_EXPIRY';
        return 'OK';
    }
}
