<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItemTopping extends Model
{
    protected $table = 'cart_item_toppings';

    protected $fillable = [
        'cart_item_id',
        'topping_menu_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function topping(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'topping_menu_item_id');
    }
}
