<?php

namespace App\Services;

use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpectedUsageService
{
    /** Chụp công thức đúng lần đầu bếp bắt đầu làm món; không thay đổi tồn kho. */
    public function capture(OrderItem $item, Carbon $at): void
    {
        if ($item->recipe_captured_at !== null) return;

        $lines = [];
        $parts = [['menu_item_id' => $item->menu_item_id, 'multiplier' => (int) $item->quantity]];
        foreach (DB::table('order_item_toppings')->where('order_item_id', $item->id)->get() as $topping) {
            $parts[] = ['menu_item_id' => $topping->topping_menu_item_id,
                'multiplier' => (int) $item->quantity * (int) $topping->quantity];
        }
        foreach ($parts as $part) {
            $recipe = DB::table('menu_item_ingredient as r')
                ->leftJoin('ingredients as i', 'i.id', '=', 'r.ingredient_id')
                ->where('r.menu_item_id', $part['menu_item_id'])
                ->select('r.ingredient_id', 'r.quantity_required', 'r.waste_factor', 'r.unit_override', 'i.unit')->get();
            if ($recipe->isEmpty()) {
                $lines[] = ['missing_recipe' => true];
            }
            foreach ($recipe as $row) {
                $lines[] = [
                    'ingredient_id' => (int) $row->ingredient_id,
                    'quantity' => round((float) $row->quantity_required * (float) $row->waste_factor * $part['multiplier'], 3),
                    'unit' => $row->unit,
                    'comparable' => $row->unit !== null && ($row->unit_override === null || $row->unit_override === $row->unit),
                ];
            }
        }

        $item->recipe_snapshot = $lines;
        $item->recipe_captured_at = $at;
    }

    /**
     * Lượng tiêu hao của các món ĐÃ bắt đầu nấu nhưng CHƯA được trừ kho.
     *
     * Vì sao cần hàm này:
     *  - Từ Phase F, khi bếp bắt đầu nấu, nguyên liệu đã bị trừ thật khỏi
     *    ingredients.quantity_in_stock. Vì vậy tồn hiện tại ĐÃ phản ánh lượng đã dùng.
     *  - Nhưng các món nấu trước khi triển khai Phase F (hoặc nấu bằng đường khác)
     *    có recipe_captured_at nhưng chưa có inventory_deducted_at → lượng của chúng
     *    CHƯA được trừ, vẫn phải trừ thủ công khi tính tồn khả dụng.
     *
     * @return array{by_ingredient:array<int, array{quantity:float, uncomparable_items:int}>, missing_recipe_items:int, legacy_items:int}
     */
    public function undeductedBetween(?string $since, Carbon $until, bool $lock = false): array
    {
        if ($since === null) {
            return ['by_ingredient' => [], 'missing_recipe_items' => 0, 'legacy_items' => 0];
        }

        $itemsQuery = OrderItem::where('recipe_captured_at', '>', $since)
            ->where('recipe_captured_at', '<=', $until)
            ->whereNull('inventory_deducted_at');
        if ($lock) $itemsQuery->lockForUpdate();
        $items = $itemsQuery->get(['quantity', 'recipe_snapshot']);

        $result = [];
        $missing = 0;
        foreach ($items as $item) {
            $lines = $item->recipe_snapshot ?? [];
            $hasMissingRecipe = $lines === [];
            foreach ($lines as $line) {
                if (!empty($line['missing_recipe'])) { $hasMissingRecipe = true; continue; }
                if (empty($line['ingredient_id'])) continue;
                $id = (int) $line['ingredient_id'];
                $result[$id] ??= ['quantity' => 0, 'uncomparable_items' => 0];
                if ($line['comparable'] ?? true) $result[$id]['quantity'] += (float) $line['quantity'];
                else $result[$id]['uncomparable_items']++;
            }
            if ($hasMissingRecipe) $missing++;
        }
        foreach ($result as &$row) $row['quantity'] = round($row['quantity'], 3);

        // Món đã bắt đầu nấu nhưng không có ảnh chụp công thức → không biết đã trừ bao nhiêu.
        $legacyQuery = OrderItem::whereNull('recipe_captured_at')->where('started_at', '>', $since)
            ->where('started_at', '<=', $until);
        if ($lock) $legacyQuery->lockForUpdate();
        $legacy = $legacyQuery->count();

        return [
            'by_ingredient'       => $result,
            'missing_recipe_items' => $missing,
            'legacy_items'        => $legacy,
        ];
    }

    /** Tổng dự kiến của các món bắt đầu làm trong kỳ giữa hai lần chốt. */
    public function between(?string $since, Carbon $until, bool $lock = false): array
    {
        if ($since === null) return ['by_ingredient' => [], 'missing_recipe_items' => 0, 'legacy_items' => 0];

        $itemsQuery = OrderItem::where('recipe_captured_at', '>', $since)
            ->where('recipe_captured_at', '<=', $until);
        if ($lock) $itemsQuery->lockForUpdate();
        $items = $itemsQuery->get(['quantity', 'recipe_snapshot']);
        $result = [];
        $missing = 0;
        foreach ($items as $item) {
            $lines = $item->recipe_snapshot ?? [];
            $hasMissingRecipe = $lines === [];
            foreach ($lines as $line) {
                if (!empty($line['missing_recipe'])) { $hasMissingRecipe = true; continue; }
                $id = (int) $line['ingredient_id'];
                $result[$id] ??= ['quantity' => 0, 'uncomparable_items' => 0];
                if ($line['comparable']) $result[$id]['quantity'] += (float) $line['quantity'];
                else $result[$id]['uncomparable_items']++;
            }
            if ($hasMissingRecipe) $missing++;
        }
        foreach ($result as &$row) $row['quantity'] = round($row['quantity'], 3);
        $legacyQuery = OrderItem::whereNull('recipe_captured_at')->where('started_at', '>', $since)
            ->where('started_at', '<=', $until);
        if ($lock) $legacyQuery->lockForUpdate();
        $legacy = $legacyQuery->count();
        return ['by_ingredient' => $result, 'missing_recipe_items' => $missing, 'legacy_items' => $legacy];
    }
}
