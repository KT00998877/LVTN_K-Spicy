<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ConsumptionReportService — báo cáo tiêu hao nguyên liệu theo món bán.
 *
 * Trả lời câu hỏi của quản lý: "đã bán món nào, trừ bao nhiêu nguyên liệu,
 * còn tồn bao nhiêu, và nguyên liệu nào đang thiếu".
 *
 * Nguồn dữ liệu:
 *  - inventory_transactions với reference_type = 'order_item' → lượng đã trừ thật.
 *  - order_items.recipe_snapshot → công thức tại thời điểm bếp bắt đầu nấu.
 *
 * Hai nguồn này tách biệt có chủ đích:
 *  - "Đã trừ" là số tiền-mặt của kho (có thể thiếu, đã clamp về 0).
 *  - "Theo công thức" là lượng lý thuyết món cần (không bị thiếu ảnh hưởng).
 * Chênh lệch giữa hai con số = hao hụt thực tế (ví dụ bếp thêm bột ớt cho khách).
 */
class ConsumptionReportService
{
    /**
     * Tiêu hao theo món: mỗi món đã bán trừ bao nhiêu nguyên liệu nào.
     *
     * @return array<int, array{
     *   menu_item_id:int, menu_item_name:string, order_count:int, total_quantity:int,
     *   ingredients:array<int, array{ingredient_id:int, ingredient_name:string, unit:string,
     *     quantity_required:float, quantity_deducted:float, shortage_qty:float}>
     * }>
     */
    public function byMenuItem(string $from, string $to): array
    {
        $rows = $this->deductionRows($from, $to);

        $grouped = [];
        foreach ($rows as $row) {
            $menuId = (int) $row['menu_item_id'];
            $grouped[$menuId] ??= [
                'menu_item_id'    => $menuId,
                'menu_item_name'  => $row['menu_item_name'],
                'order_count'     => 0,
                'total_quantity'  => 0,
                'ingredients'     => [],
            ];
            $grouped[$menuId]['order_count']++;
            $grouped[$menuId]['total_quantity'] += (int) $row['quantity'];

            foreach ($row['ingredients'] as $ingredientId => $line) {
                $grouped[$menuId]['ingredients'][$ingredientId] ??= [
                    'ingredient_id'      => (int) $ingredientId,
                    'ingredient_name'    => $line['name'],
                    'unit'               => $line['unit'],
                    'quantity_required'  => 0.0,
                    'quantity_deducted'  => 0.0,
                    'shortage_qty'       => 0.0,
                ];
                $grouped[$menuId]['ingredients'][$ingredientId]['quantity_required'] += $line['required'];
                $grouped[$menuId]['ingredients'][$ingredientId]['quantity_deducted'] += $line['deducted'];
                $grouped[$menuId]['ingredients'][$ingredientId]['shortage_qty'] += $line['required'] - $line['deducted'];
            }
        }

        foreach ($grouped as &$menu) {
            $menu['ingredients'] = array_values($menu['ingredients']);
            usort($menu['ingredients'], fn ($a, $b) => $b['quantity_required'] <=> $a['quantity_required']);
            foreach ($menu['ingredients'] as &$line) {
                $line['quantity_required'] = round($line['quantity_required'], 3);
                $line['quantity_deducted'] = round($line['quantity_deducted'], 3);
                $line['shortage_qty'] = round($line['shortage_qty'], 3);
            }
        }

        usort($grouped, fn ($a, $b) => $b['total_quantity'] <=> $a['total_quantity']);

        return array_values($grouped);
    }

    /**
     * Tiêu hao theo nguyên liệu: tồn hiện tại + đã dùng trong kỳ + còn thiếu.
     *
     * @return array<int, array{
     *   ingredient_id:int, code:string, name:string, unit:string,
     *   current_stock:float, min_stock_level:float, is_low:bool,
     *   consumed_qty:float, required_qty:float, shortage_qty:float,
     *   estimated_days_left:?float
     * }>
     */
    public function byIngredient(string $from, string $to): array
    {
        $rows = $this->deductionRows($from, $to);

        $totals = [];
        foreach ($rows as $row) {
            foreach ($row['ingredients'] as $ingredientId => $line) {
                $totals[$ingredientId] ??= ['required' => 0.0, 'deducted' => 0.0];
                $totals[$ingredientId]['required'] += $line['required'];
                $totals[$ingredientId]['deducted'] += $line['deducted'];
            }
        }

        $ingredients = Ingredient::where('is_active', true)->orderBy('name')->get();
        if ($totals === []) {
            return $ingredients->map(fn ($i) => $this->ingredientRow($i, ['required' => 0.0, 'deducted' => 0.0], $from, $to))->all();
        }

        return $ingredients->map(fn ($i) => $this->ingredientRow(
            $i,
            $totals[$i->id] ?? ['required' => 0.0, 'deducted' => 0.0],
            $from,
            $to,
        ))->all();
    }

    private function ingredientRow(Ingredient $ingredient, array $total, string $from, string $to): array
    {
        $required = round($total['required'], 3);
        $deducted = round($total['deducted'], 3);
        $stock = (float) $ingredient->quantity_in_stock;
        $min = (float) $ingredient->min_stock_level;

        // Ước lượng số ngày còn đủ: dựa trên lượng tiêu hao trung bình mỗi ngày trong kỳ.
        $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
        $dailyUsed = $deducted / $days;
        $estimatedDaysLeft = $dailyUsed > 0 ? round($stock / $dailyUsed, 1) : null;

        return [
            'ingredient_id'     => (int) $ingredient->id,
            'code'              => (string) $ingredient->code,
            'name'              => (string) $ingredient->name,
            'unit'              => (string) $ingredient->unit,
            'current_stock'     => round($stock, 3),
            'min_stock_level'   => round($min, 3),
            'is_low'            => $stock <= $min,
            'consumed_qty'      => $deducted,
            'required_qty'      => $required,
            'shortage_qty'      => round($required - $deducted, 3),
            'estimated_days_left' => $estimatedDaysLeft,
        ];
    }

    /**
     * Gom tất cả giao dịch trừ kho do món bán trong kỳ, kèm công thức tương ứng.
     *
     * @return array<int, array{
     *   order_item_id:int, menu_item_id:int, menu_item_name:string, quantity:int,
     *   ingredients:array<int, array{name:string, unit:string, required:float, deducted:float}>
     * }>
     */
    private function deductionRows(string $from, string $to): array
    {
        $transactions = InventoryTransaction::where('reference_type', 'order_item')
            ->whereBetween('transacted_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['reference_id', 'ingredient_id', 'quantity'])
            ->groupBy('reference_id');

        if ($transactions->isEmpty()) return [];

        $orderItemIds = $transactions->keys()->map(fn ($id) => (int) $id)->all();
        $items = OrderItem::whereIn('id', $orderItemIds)->get([
            'id', 'menu_item_id', 'item_name', 'quantity', 'recipe_snapshot',
        ])->keyBy('id');

        $menuIds = $items->pluck('menu_item_id')->map(fn ($id) => (int) $id)->unique()->all();
        $menuNames = MenuItem::whereIn('id', $menuIds)->pluck('name', 'id');

        $ingredientMeta = Ingredient::whereIn('id',
            $transactions->flatten(1)->pluck('ingredient_id')->unique()
        )->get(['id', 'name', 'unit'])->keyBy('id');

        $result = [];
        foreach ($transactions as $orderItemId => $txs) {
            $item = $items->get((int) $orderItemId);
            if (!$item) continue;

            $deductedByIngredient = [];
            foreach ($txs as $tx) {
                $ingredientId = (int) $tx->ingredient_id;
                $deductedByIngredient[$ingredientId] =
                    ($deductedByIngredient[$ingredientId] ?? 0) + abs((float) $tx->quantity);
            }

            $requiredByIngredient = $this->requiredFromSnapshot($item);

            $ingredients = [];
            foreach (array_unique(array_merge(array_keys($deductedByIngredient), array_keys($requiredByIngredient))) as $ingredientId) {
                $meta = $ingredientMeta->get($ingredientId);
                $ingredients[$ingredientId] = [
                    'name'     => (string) ($meta->name ?? 'Nguyên liệu #'.$ingredientId),
                    'unit'     => (string) ($meta->unit ?? ''),
                    'required' => (float) ($requiredByIngredient[$ingredientId] ?? 0),
                    'deducted' => (float) ($deductedByIngredient[$ingredientId] ?? 0),
                ];
            }

            $result[] = [
                'order_item_id'   => (int) $orderItemId,
                'menu_item_id'    => (int) $item->menu_item_id,
                'menu_item_name'  => (string) ($menuNames[$item->menu_item_id] ?? $item->item_name),
                'quantity'        => (int) $item->quantity,
                'ingredients'     => $ingredients,
            ];
        }

        return $result;
    }

    /** Lượng cần theo công thức tại lúc bếp bắt đầu nấu (recipe_snapshot). */
    private function requiredFromSnapshot(OrderItem $item): array
    {
        $snapshot = $item->recipe_snapshot;
        if (!is_array($snapshot) || $snapshot === []) return [];

        $required = [];
        foreach ($snapshot as $line) {
            if (empty($line['ingredient_id'])) continue;
            $id = (int) $line['ingredient_id'];
            $required[$id] = ($required[$id] ?? 0) + (float) ($line['quantity'] ?? 0);
        }
        return $required;
    }
}
