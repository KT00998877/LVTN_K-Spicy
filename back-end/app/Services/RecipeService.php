<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecipeService — quản lý công thức món ăn (quan hệ menu_items ↔ ingredients).
 *
 * Phase này KHÔNG làm auto-deduct. Chỉ CRUD recipe và tính food cost preview.
 *
 * Quy tắc:
 *  - BR-RECIPE-01: 1 menu_item có tối đa 50 dòng recipe.
 *  - BR-RECIPE-02: 1 ingredient chỉ xuất hiện 1 lần / menu_item (cặp unique).
 *  - BR-RECIPE-03: ingredient phải is_active = true.
 *  - BR-RECIPE-04: thay thế recipe dùng DELETE + INSERT nguyên tử trong transaction
 *                  để các query song song luôn thấy dữ liệu nhất quán.
 */
class RecipeService
{
    /** Lấy recipe đầy đủ của 1 menu_item. */
    public function get(int $menuItemId): array
    {
        $mi = MenuItem::with(['recipes.ingredient'])->findOrFail($menuItemId);

        $lines = $mi->recipes->map(function (MenuItemIngredient $r) {
            $ing = $r->ingredient;
            return [
                'id'                 => (int) $r->id,
                'ingredient_id'      => (int) $r->ingredient_id,
                'ingredient_code'    => $ing?->code,
                'ingredient_name'    => $ing?->name,
                'ingredient_unit'    => $ing?->unit,
                'ingredient_active'  => $ing ? (bool) $ing->is_active : false,
                'ingredient_in_stock'=> $ing ? (float) $ing->quantity_in_stock : 0,
                'quantity_required'  => (float) $r->quantity_required,
                'waste_factor'       => (float) $r->waste_factor,
                'unit_override'      => $r->unit_override,
                'availability_required' => (bool) $r->availability_required,
                'effective_quantity' => round((float) $r->quantity_required * (float) $r->waste_factor, 3),
                'display_unit'       => (string) ($r->unit_override ?: ($ing?->unit ?? '')),
                'line_cost'          => $ing ? round((float) $r->quantity_required * (float) $ing->import_price, 2) : 0,
            ];
        })->values()->all();

        // Thực hiện group recipes theo ingredient nếu vô tình có dữ liệu cũ trùng.
        // Vì cặp (menu_item, ingredient) là unique, không thể có dòng trùng.
        return [
            'menu_item' => [
                'id'     => (int) $mi->id,
                'name'   => $mi->name,
                'price'  => (float) $mi->price,
                'slug'   => $mi->slug,
                'size'   => $mi->size,
                'is_active' => (bool) $mi->is_active,
                'is_available' => (bool) $mi->is_available,
                'auto_availability_enabled' => (bool) $mi->auto_availability_enabled,
                'sold_out_reason' => $mi->sold_out_reason,
                'sold_out_at' => $mi->sold_out_at ? $mi->sold_out_at->toIso8601String() : null,
            ],
            'lines' => $lines,
            'summary' => $this->summarize($mi, $lines),
        ];
    }

    /**
     * Thay thế TOÀN BỘ recipe của 1 menu_item bằng danh sách `lines` mới.
     *
     * @param array<int, array{ingredient_id:int, quantity_required:float|int, waste_factor?:float|int, unit_override?:string|null}> $lines
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException nếu menu_item không tồn tại
     */
    public function replace(int $menuItemId, array $lines): array
    {
        return DB::transaction(function () use ($menuItemId, $lines) {
            /** @var MenuItem $mi */
            $mi = MenuItem::lockForUpdate()->findOrFail($menuItemId);

            // Xóa sạch các dòng cũ
            MenuItemIngredient::where('menu_item_id', $menuItemId)->delete();

            $now = now();
            $rows = [];
            foreach ($lines as $line) {
                $rows[] = [
                    'menu_item_id'      => $menuItemId,
                    'ingredient_id'     => (int) $line['ingredient_id'],
                    'quantity_required' => (float) $line['quantity_required'],
                    'waste_factor'      => (float) ($line['waste_factor'] ?? 1.000),
                    'unit_override'     => isset($line['unit_override']) && $line['unit_override'] !== ''
                        ? (string) $line['unit_override']
                        : null,
                    'availability_required' => (bool) ($line['availability_required'] ?? false),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }

            if ($rows !== []) {
                MenuItemIngredient::insert($rows);
            }
            $mi->recipe_managed_at = $now;
            $mi->auto_availability_enabled = false;
            $mi->save();

            Log::info('recipe.replace', [
                'menu_item_id' => $menuItemId,
                'lines' => count($rows),
                'by'    => auth()->id(),
            ]);

            return $this->get($menuItemId);
        });
    }

    /**
     * Tính tổng quan cho 1 món: cost, profit, food cost %.
     */
    private function summarize(MenuItem $mi, array $lines): array
    {
        $ingredientCost = array_sum(array_column($lines, 'line_cost'));
        $price = (float) $mi->price;
        $profit = max(0, $price - $ingredientCost);
        $foodCostPct = $price > 0 ? round(($ingredientCost / $price) * 100, 2) : 0;
        $wastedLines = array_filter($lines, fn ($l) => (float) $l['waste_factor'] > 1.0);

        return [
            'ingredient_cost'        => round($ingredientCost, 2),
            'selling_price'          => $price,
            'gross_profit'           => round($profit, 2),
            'food_cost_pct'          => $foodCostPct,
            'waste_lines_count'      => count($wastedLines),
            'lines_count'            => count($lines),
        ];
    }
}
