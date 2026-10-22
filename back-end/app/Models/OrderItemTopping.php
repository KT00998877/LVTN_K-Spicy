<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderItemTopping — quan hệ topping cho 1 order_item.
 * Topping thực chất là menu_items có category = 'topping-them'.
 */
class OrderItemTopping extends Model
{
    protected $table = 'order_item_toppings';

    protected $fillable = [
        'order_item_id',
        'topping_menu_item_id',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal'   => 'decimal:2',
            'quantity'   => 'integer',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function topping(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'topping_menu_item_id');
    }
}
