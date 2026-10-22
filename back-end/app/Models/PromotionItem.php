<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PromotionItem — dòng phạm vi áp dụng của 1 promotion.
 *
 * Quan hệ N-N giữa Promotion và (Category | MenuItem):
 *  - item_type = 'CATEGORY'  → item_id là categories.id
 *  - item_type = 'MENU_ITEM' → item_id là menu_items.id
 *
 * Trường `override` (json nullable) cho phép override cấu hình promotion riêng cho item này
 * (VD: BXGY tặng đúng 1 topping cụ thể, hoặc COMBO giảm thêm Xđ cho 1 món).
 *
 * BR-PROMO-ITEM-01: xoá promotion sẽ cascade xoá dòng này (đã set cascadeOnDelete).
 */
class PromotionItem extends Model
{
    protected $table = 'promotion_items';

    public const TYPE_CATEGORY  = 'CATEGORY';
    public const TYPE_MENU_ITEM = 'MENU_ITEM';

    /** @var list<string> */
    protected $fillable = [
        'promotion_id', 'item_type', 'item_id', 'override',
    ];

    protected function casts(): array
    {
        return [
            'item_id'   => 'integer',
            'override'  => 'array',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** Trả về related model (Category hoặc MenuItem) dựa theo item_type. */
    public function target()
    {
        return match ($this->item_type) {
            self::TYPE_CATEGORY  => $this->belongsTo(Category::class, 'item_id'),
            self::TYPE_MENU_ITEM => $this->belongsTo(MenuItem::class, 'item_id'),
            default              => null,
        };
    }
}