<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\MenuItemSuggestion;

/**
 * MenuSuggestionService — gợi ý combo do quản lý cấu hình (Phase 3).
 *
 * 2 nhóm chức năng:
 *  1. Đọc: gợi ý cho menu (public) — gom 1 query cho cả trang, không N+1.
 *  2. Tính tiền: tiết kiệm combo cho 1 giỏ hàng (dùng khi quote + checkout).
 *
 * Quy tắc tính combo:
 *  - 1 combo = 1 món chính + 1 món gợi ý.
 *  - Số combo khả dụng = min(số lượng món chính, số lượng món gợi ý) còn lại.
 *  - Tiết kiệm = (giá món chính + giá món gợi ý) - combo_price, nhân với số combo.
 *  - Món gợi ý không còn khả dụng (hết hàng) → không áp combo cho món đó.
 *
 * Đơn vị tiền: VND (major units, integer) — đồng bộ CartContext/PromotionRuleEngine.
 */
class MenuSuggestionService
{
    private ?MenuAvailabilityService $availability = null;

    /**
     * Gợi ý theo món chính: [menu_item_id => list<suggestion>].
     *
     * @param  list<int>  $menuItemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forMenuItems(array $menuItemIds): array
    {
        $menuItemIds = array_values(array_unique(array_map('intval', $menuItemIds)));
        if (empty($menuItemIds)) return [];

        $rows = MenuItemSuggestion::query()
            ->with(['menuItem.category', 'suggestedItem.category'])
            ->whereIn('menu_item_id', $menuItemIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $formatted = $this->format($row);
            if ($formatted === null) continue;
            $grouped[(int) $row->menu_item_id][] = $formatted;
        }

        return $grouped;
    }

    /**
     * Gợi ý của 1 món (dùng cho chi tiết món).
     *
     * @return list<array<string, mixed>>
     */
    public function forMenuItem(int $menuItemId): array
    {
        return $this->forMenuItems([$menuItemId])[$menuItemId] ?? [];
    }

    /**
     * Tính tiết kiệm combo cho 1 giỏ hàng.
     *
     * @param  array<int, int>  $quantities  map menu_item_id => số lượng trong giỏ
     * @return array{discount_vnd: int, lines: list<array<string, mixed>>}
     */
    public function comboDiscountForCart(array $quantities): array
    {
        $quantities = array_map('intval', $quantities);
        $quantities = array_filter($quantities, fn ($q) => $q > 0);
        if (empty($quantities)) return ['discount_vnd' => 0, 'lines' => []];

        $suggestions = MenuItemSuggestion::query()
            ->with(['menuItem.category', 'suggestedItem.category'])
            ->where('is_active', true)
            ->whereNotNull('combo_price')
            ->whereIn('menu_item_id', array_keys($quantities))
            ->get();

        // Chuẩn bị dữ liệu giá + loại bỏ gợi ý không dùng được.
        $candidates = [];
        foreach ($suggestions as $row) {
            $main = $row->menuItem;
            $side = $row->suggestedItem;
            if (!$main || !$side) continue;
            if (!$this->isOrderable($side)) continue;

            $comboPrice = (int) round((float) $row->combo_price);
            $original = (int) round((float) $main->price) + (int) round((float) $side->price);
            $saving = $original - $comboPrice;
            if ($saving <= 0) continue; // Combo không ưu đãi → bỏ qua.

            $candidates[] = [
                'row' => $row,
                'main_id' => (int) $row->menu_item_id,
                'side_id' => (int) $row->suggested_item_id,
                'saving' => $saving,
                'combo_price' => $comboPrice,
            ];
        }

        // Ưu tiên combo tiết kiệm nhiều nhất để khi thiếu món gợi ý thì
        // phần giảm vẫn đúng với món khách quan tâm nhất.
        usort($candidates, fn ($a, $b) => $b['saving'] <=> $a['saving']);

        $remaining = $quantities;
        $discount = 0;
        $lines = [];

        foreach ($candidates as $candidate) {
            $count = min($remaining[$candidate['main_id']] ?? 0, $remaining[$candidate['side_id']] ?? 0);
            if ($count <= 0) continue;

            $remaining[$candidate['main_id']] -= $count;
            $remaining[$candidate['side_id']] -= $count;

            $saving = $candidate['saving'] * $count;
            $discount += $saving;

            $row = $candidate['row'];
            $lines[] = [
                'suggestion_id'   => (int) $row->id,
                'title'           => $this->titleFor($row),
                'menu_item_id'    => $candidate['main_id'],
                'suggested_item_id' => $candidate['side_id'],
                'quantity'        => $count,
                'combo_price'     => $candidate['combo_price'],
                'saving'          => $saving,
            ];
        }

        return ['discount_vnd' => $discount, 'lines' => $lines];
    }

    /**
     * Định dạng 1 gợi ý cho API. Trả null nếu món gợi ý không còn khả dụng.
     *
     * @return array<string, mixed>|null
     */
    private function format(MenuItemSuggestion $row): ?array
    {
        $main = $row->menuItem;
        $side = $row->suggestedItem;
        if (!$main || !$side) return null;

        $original = (int) round((float) $main->price) + (int) round((float) $side->price);
        $comboPrice = $row->combo_price === null ? null : (int) round((float) $row->combo_price);
        $saving = $comboPrice === null ? 0 : max(0, $original - $comboPrice);

        return [
            'id'          => (int) $row->id,
            'title'       => $this->titleFor($row),
            'message'     => $row->message,
            'combo_price' => $comboPrice,
            'combo_price_formatted' => $comboPrice === null ? null : $this->formatPrice($comboPrice),
            'original_price' => $original,
            'original_price_formatted' => $this->formatPrice($original),
            'saving'      => $saving,
            'saving_formatted' => $saving > 0 ? $this->formatPrice($saving) : null,
            'suggested_item' => [
                'id'          => (int) $side->id,
                'name'        => $side->name,
                'price'       => (float) $side->price,
                'price_formatted' => $this->formatPrice((float) $side->price),
                'image_url'   => $side->image_url,
                'is_available' => $this->isOrderable($side),
            ],
        ];
    }

    /** Món còn bán + còn khả dụng (dùng cả auto-availability). */
    private function isOrderable(MenuItem $item): bool
    {
        if (!$item->is_active || !$item->is_available) return false;
        if (!$item->category?->is_active) return false;

        // Dùng chung 1 instance để tận dụng cache tồn kho của availability service.
        $this->availability ??= app(MenuAvailabilityService::class);

        return $this->availability->status($item)['is_available'];
    }

    /** Tiêu đề hiển thị — quản lý có thể ghi đè, nếu không thì sinh tự động. */
    private function titleFor(MenuItemSuggestion $row): string
    {
        if (!empty($row->title)) return $row->title;

        $main = $row->menuItem?->name ?? 'Món';
        $side = $row->suggestedItem?->name ?? 'món đi kèm';

        return "Combo {$main} + {$side}";
    }

    private function formatPrice(float|int $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
