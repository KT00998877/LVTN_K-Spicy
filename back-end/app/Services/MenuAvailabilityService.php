<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuAvailabilityService
{
    private ?array $balance = null;
    private bool $balanceKnown = false;

    /** Khả dụng hiệu lực: bếp báo hết luôn ưu tiên hơn dự kiến kho. */
    public function status(object $menu, int $quantity = 1): array
    {
        if (!(bool) $menu->is_available) return ['is_available' => false, 'reason' => 'manual'];
        if (!(bool) $menu->auto_availability_enabled) return ['is_available' => true, 'reason' => null];
        $required = $this->required((int) $menu->id, $quantity);
        if ($required === null || !$this->loadBalance()) {
            return ['is_available' => true, 'reason' => 'insufficient_data'];
        }
        foreach ($required as $ingredientId => $amount) {
            if (($this->balance[$ingredientId] ?? -1) + 0.0001 < $amount) {
                return ['is_available' => false, 'reason' => 'ingredient'];
            }
        }
        return ['is_available' => true, 'reason' => null];
    }

    /** Kiểm tra tổng món và topping trong một đơn trước khi ghi đơn. */
    public function assertCanOrder(array $parts, ?int $excludeOrderItemId = null): void
    {
        if (!$parts) return;
        // Các checkout đồng thời cùng khóa mốc tồn. Chỉ kiểm tra, không trừ tồn thực tế.
        DB::table('daily_stock_sheets')->where('status', 'CLOSED')->orderByDesc('closed_at')
            ->lockForUpdate()->first();
        $menus = DB::table('menu_items')->whereIn('id', array_keys($parts))->get()->keyBy('id');
        $requiredTotal = [];
        foreach ($parts as $id => $quantity) {
            $menu = $menus->get($id);
            if (!$menu || !(bool) $menu->is_available) {
                throw ValidationException::withMessages(['cart' => 'Món hoặc topping đã hết. Vui lòng kiểm tra lại.']);
            }
            if (!(bool) $menu->auto_availability_enabled) continue;
            $required = $this->required((int) $id, (int) $quantity);
            if ($required === null) continue;
            foreach ($required as $ingredientId => $amount) {
                $requiredTotal[$ingredientId] = ($requiredTotal[$ingredientId] ?? 0) + $amount;
            }
        }
        if (!$requiredTotal || !$this->loadBalance($excludeOrderItemId)) return;
        foreach ($requiredTotal as $ingredientId => $amount) {
            if (($this->balance[$ingredientId] ?? -1) + 0.0001 < $amount) {
                throw ValidationException::withMessages(['cart' => 'Không đủ nguyên liệu cho số phần đã chọn. Vui lòng cập nhật giỏ hàng.']);
            }
        }
    }

    private function required(int $menuId, int $quantity): ?array
    {
        $menu = DB::table('menu_items')->where('id', $menuId)->first();
        if (!$menu?->recipe_managed_at) return null;
        $rows = DB::table('menu_item_ingredient as r')->join('ingredients as i', 'i.id', '=', 'r.ingredient_id')
            ->where('r.menu_item_id', $menuId)->where('r.availability_required', true)
            ->select('r.ingredient_id', 'r.quantity_required', 'r.waste_factor', 'r.unit_override', 'i.unit', 'i.is_active')->get();
        if ($rows->isEmpty()) return null;
        $required = [];
        foreach ($rows as $row) {
            if (!$row->is_active || ($row->unit_override !== null && $row->unit_override !== $row->unit)) return null;
            $required[(int) $row->ingredient_id] = (float) $row->quantity_required * (float) $row->waste_factor * $quantity;
        }
        return $required;
    }

    private function loadBalance(?int $excludeOrderItemId = null): bool
    {
        if ($this->balance !== null) return $this->balanceKnown;
        $this->balance = [];
        $last = DB::table('daily_stock_sheets')->where('status', 'CLOSED')->orderByDesc('closed_at')->first();
        if (!$last) return false;
        $counted = DB::table('daily_stock_sheet_items')->where('daily_stock_sheet_id', $last->id)
            ->whereNotNull('counted_qty')->pluck('ingredient_id')->map(fn ($id) => (int) $id)->all();
        $ingredientQuery = DB::table('ingredients')->where('is_active', true);
        if (DB::transactionLevel() > 0) $ingredientQuery->lockForUpdate();
        $ingredients = $ingredientQuery->get(['id', 'quantity_in_stock']);
        if ($ingredients->contains(fn ($ingredient) => !in_array((int) $ingredient->id, $counted, true))) return false;
        $used = app(ExpectedUsageService::class)->between($last->closed_at, now(), DB::transactionLevel() > 0);
        if ($used['missing_recipe_items'] || $used['legacy_items']
            || collect($used['by_ingredient'])->contains(fn ($row) => $row['uncomparable_items'] > 0)) return false;
        foreach ($ingredients as $ingredient) {
            $this->balance[(int) $ingredient->id] = (float) $ingredient->quantity_in_stock
                - (float) ($used['by_ingredient'][$ingredient->id]['quantity'] ?? 0);
        }
        $reserved = $this->pendingUsage($excludeOrderItemId);
        if ($reserved === null) return false;
        foreach ($reserved as $ingredientId => $quantity) {
            $this->balance[$ingredientId] = ($this->balance[$ingredientId] ?? 0) - $quantity;
        }
        $this->balanceKnown = true;
        return true;
    }

    /** Lượng giữ chỗ của đơn chưa bắt đầu nấu; null nếu thiếu công thức hoặc lệch đơn vị. */
    public function pendingUsage(?int $excludeOrderItemId = null): ?array
    {
        $pendingQuery = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.status', 'PENDING')->whereNotIn('o.status', ['CANCELLED', 'COMPLETED'])
            ->when($excludeOrderItemId, fn ($query) => $query->where('oi.id', '!=', $excludeOrderItemId));
        if (DB::transactionLevel() > 0) $pendingQuery->lockForUpdate();
        $pending = $pendingQuery->get(['oi.id', 'oi.menu_item_id', 'oi.quantity']);
        $reserved = [];
        if ($pending->isNotEmpty()) {
            $toppings = DB::table('order_item_toppings')->whereIn('order_item_id', $pending->pluck('id'))
                ->get()->groupBy('order_item_id');
            $parts = [];
            foreach ($pending as $item) {
                $parts[$item->menu_item_id] = ($parts[$item->menu_item_id] ?? 0) + (int) $item->quantity;
                foreach ($toppings->get($item->id, collect()) as $topping) {
                    $id = (int) $topping->topping_menu_item_id;
                    $parts[$id] = ($parts[$id] ?? 0) + (int) $item->quantity * (int) $topping->quantity;
                }
            }
            $recipes = DB::table('menu_item_ingredient as r')->join('ingredients as i', 'i.id', '=', 'r.ingredient_id')
                ->whereIn('r.menu_item_id', array_keys($parts))
                ->get(['r.menu_item_id', 'r.ingredient_id', 'r.quantity_required', 'r.waste_factor', 'r.unit_override', 'i.unit'])
                ->groupBy('menu_item_id');
            foreach ($parts as $id => $quantity) {
                $lines = $recipes->get($id);
                if (!$lines || $lines->isEmpty()) return null;
                foreach ($lines as $line) {
                    if ($line->unit_override !== null && $line->unit_override !== $line->unit) return null;
                    $ingredientId = (int) $line->ingredient_id;
                    $reserved[$ingredientId] = ($reserved[$ingredientId] ?? 0)
                        + (float) $line->quantity_required * (float) $line->waste_factor * $quantity;
                }
            }
        }
        return $reserved;
    }
}
