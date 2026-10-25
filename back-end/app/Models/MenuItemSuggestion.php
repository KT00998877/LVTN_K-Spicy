<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MenuItemSuggestion — gợi ý combo do quản lý cấu hình (Phase 3).
 *
 * 1 dòng = 1 gợi ý: món chính `menu_item_id` nên đi kèm món `suggested_item_id`
 * (thường là đồ uống). `combo_price` là giá của cả 2 món khi mua theo combo.
 */
class MenuItemSuggestion extends Model
{
    protected $table = 'menu_item_suggestions';

    protected $fillable = [
        'menu_item_id',
        'suggested_item_id',
        'title',
        'message',
        'combo_price',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'combo_price' => 'decimal:2',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    /** Món chính. */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /** Món được gợi ý đi kèm. */
    public function suggestedItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'suggested_item_id');
    }
}
