<?php

namespace App\Enums;

/**
 * Loại khuyến mãi tự động (Promotion).
 *
 * Mapping với cột `promotions.kind` (string) và cấu trúc `promotions.config` (json).
 *
 *  - PERCENT_OFF: Giảm theo % trên subtotal phạm vi áp dụng.
 *                 config: { value: 1..100, max_discount: int|null, applies_to, item_ids?[] }
 *  - FIXED_OFF:   Giảm cố định 1 số tiền.
 *                 config: { value: int≥1000, applies_to, item_ids?[] }
 *  - BXGY:        "Mua X tặng Y" — mua đủ `buy_qty` phần món A, được giảm `discount_percent`%
 *                 trên `get_qty` phần món B (thường 100% = miễn phí).
 *                 config: { buy_qty:int, get_qty:int, get_item_ids:int[], discount_percent:0..100 }
 *  - COMBO:       Combo bundle cố định giá.
 *                 config: { bundle_items: [{item_id:int, qty:int}], bundle_price:int }
 *
 * Tham khảo: back-end/PROMOTION_VOUCHER_PLAN.md — Module B.
 */
enum PromotionKind: string
{
    case PERCENT_OFF = 'PERCENT_OFF';
    case FIXED_OFF   = 'FIXED_OFF';
    case BXGY        = 'BXGY';
    case COMBO       = 'COMBO';

    /** Nhãn tiếng Việt cho UI Manager. */
    public function label(): string
    {
        return match ($this) {
            self::PERCENT_OFF => 'Giảm theo %',
            self::FIXED_OFF   => 'Giảm số tiền cố định',
            self::BXGY        => 'Mua X tặng Y',
            self::COMBO       => 'Combo giá cố định',
        };
    }
}