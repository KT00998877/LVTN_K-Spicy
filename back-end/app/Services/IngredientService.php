<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IngredientService — CRUD nguyên liệu.
 *
 * Quy tắc BR-ING-01..04:
 *  - Không cho phép client set quantity_in_stock / import_price trực tiếp.
 *    Hai trường này chỉ thay đổi qua ManagerInventoryController::transact().
 *  - Không cho đổi `unit` khi đã có tồn kho (>0), transaction hoặc recipe.
 *  - Bỏ qua các field đã bị request lọc (UpsertIngredientRequest đã làm).
 *  - Khi disable (is_active = false) mà nguyên liệu còn đang dùng trong recipe
 *    active → vẫn cho phép, nhưng sẽ cảnh báo (UI hiển thị).
 */
class IngredientService
{
    /**
     * Danh sách ingredient.
     *
     * @param  array<string, mixed>  $filters
     *   - q           : tìm theo code/name
     *   - is_active   : bool|null
     *   - low_stock   : bool — true = chỉ những nguyên liệu đang ở mức thấp
     *   - supplier_id : int|null
     *   - page / per_page
     */
    public function paginate(array $filters = []): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min(100, $perPage));

        $q = Ingredient::query()->with(['supplier', 'category', 'packagings.supplier']);

        if (!empty($filters['q'])) {
            $kw = trim((string) $filters['q']);
            $q->where(function ($q) use ($kw) {
                $q->where('code', 'like', $kw.'%')
                  ->orWhere('name', 'like', '%'.$kw.'%');
            });
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $q->where('is_active', (bool) $filters['is_active']);
        }
        if (!empty($filters['supplier_id'])) {
            $q->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (!empty($filters['ingredient_category_id'])) {
            $q->where('ingredient_category_id', (int) $filters['ingredient_category_id']);
        }
        if (!empty($filters['low_stock'])) {
            $q->whereColumn('quantity_in_stock', '<=', 'min_stock_level');
        }

        $rows = $q->orderBy('name')->paginate($perPage);

        return [
            'data' => collect($rows->items())
                ->map(fn (Ingredient $i) => $this->present($i))
                ->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
                'last_page'    => $rows->lastPage(),
            ],
        ];
    }

    /** Lấy 1 ingredient theo id. */
    public function find(int $id): array
    {
        $i = Ingredient::with(['supplier', 'category', 'packagings.supplier'])->findOrFail($id);
        return $this->present($i);
    }

    /**
     * Tạo ingredient mới.
     *
     * @param  array<string, mixed>  $data  Đã validate
     */
    public function create(array $data): array
    {
        $i = DB::transaction(function () use ($data) {
            return Ingredient::create([
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'unit' => $data['unit'],
                'min_stock_level' => (float) $data['min_stock_level'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'ingredient_category_id' => $data['ingredient_category_id'] ?? null,
                'is_active' => (bool) $data['is_active'],
                // quantity_in_stock + import_price bắt buộc = 0 khi mới tạo.
                'quantity_in_stock' => 0,
                'import_price' => 0,
            ]);
        });

        return $this->present($i->fresh()->load(['supplier', 'category', 'packagings.supplier']));
    }

    /**
     * Cập nhật ingredient. KHÔNG thay đổi quantity_in_stock / import_price ở đây.
     *
     * @throws ValidationException khi đổi unit vi phạm BR-ING-03
     */
    public function update(int $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data) {
            /** @var Ingredient $i */
            $i = Ingredient::lockForUpdate()->findOrFail($id);

            // BR-ING-03: cấm đổi unit khi đã có dấu vết sử dụng
            if (isset($data['unit']) && $data['unit'] !== $i->unit) {
                $hasStock    = (float) $i->quantity_in_stock > 0;
                $hasTx       = InventoryTransaction::where('ingredient_id', $id)->exists();
                $hasRecipe   = \App\Models\MenuItemIngredient::where('ingredient_id', $id)->exists();
                if ($hasStock || $hasTx || $hasRecipe) {
                    throw ValidationException::withMessages([
                        'unit' => 'Không đổi đơn vị khi đã có tồn kho, lịch sử giao dịch hoặc công thức. Hãy tạo nguyên liệu riêng.',
                    ]);
                }
            }

            $i->fill([
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'unit' => $data['unit'],
                'min_stock_level' => (float) $data['min_stock_level'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'ingredient_category_id' => $data['ingredient_category_id'] ?? null,
                'is_active' => (bool) $data['is_active'],
            ])->save();

            return $this->present($i->fresh()->load(['supplier', 'category', 'packagings.supplier']));
        });
    }

    /**
     * Kiểm tra có thể xóa (deactivate) nguyên liệu hay không.
     * Trả về danh sách các món đang dùng nguyên liệu này.
     *
     * @return array<int, array{id:int, name:string, code:string|null}>
     */
    public function blockingReferences(int $id): array
    {
        $rows = DB::table('menu_item_ingredient as mi')
            ->join('menu_items as m', 'm.id', '=', 'mi.menu_item_id')
            ->where('mi.ingredient_id', $id)
            ->where('m.is_active', true)
            ->orderBy('m.name')
            ->get(['m.id', 'm.name', 'm.slug']);
        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'code' => $r->slug])->all();
    }

    /**
     * Format response 1 ingredient.
     *
     * @return array<string, mixed>
     */
    public function present(Ingredient $i): array
    {
        return [
            'id'                  => (int) $i->id,
            'code'                => $i->code,
            'name'                => $i->name,
            'unit'                => $i->unit,
            'quantity_in_stock'   => (float) $i->quantity_in_stock,
            'min_stock_level'     => (float) $i->min_stock_level,
            'import_price'        => (float) $i->import_price,
            'import_date'         => $i->import_date?->toDateString(),
            'expiry_date'         => $i->expiry_date?->toDateString(),
            'supplier_id'         => $i->supplier_id ? (int) $i->supplier_id : null,
            'supplier_name'       => $i->supplier?->name,
            'ingredient_category_id' => $i->ingredient_category_id ? (int) $i->ingredient_category_id : null,
            'category_name'       => $i->category?->name,
            'packagings'          => $i->packagings->map(fn ($p) => [
                'id' => (int) $p->id, 'supplier_id' => $p->supplier_id ? (int) $p->supplier_id : null,
                'supplier_name' => $p->supplier?->name, 'purchase_unit' => $p->purchase_unit,
                'conversion_quantity' => (float) $p->conversion_quantity,
                'is_default' => (bool) $p->is_default, 'is_active' => (bool) $p->is_active,
            ])->all(),
            'is_active'           => (bool) $i->is_active,
            'is_low_stock'        => $i->is_low_stock,
            'estimated_value'     => round((float) $i->quantity_in_stock * (float) $i->import_price, 2),
            'created_at'          => $i->created_at?->toIso8601String(),
            'updated_at'          => $i->updated_at?->toIso8601String(),
        ];
    }
}
