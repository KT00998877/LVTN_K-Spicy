<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CartItem — 1 dòng món trong cart.
 *
 * Lưu ý:
 *  - menu_item_id : món chính
 *  - toppings()   : quan hệ tới các topping (cũng là menu_items có category=topping-them)
 *  - subtotal     : (unit_price + sum(topping_price)) × quantity
 */
class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id',
        'menu_item_id',
        'quantity',
        'spicy_level',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'spicy_level' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function toppingRelations(): HasMany
    {
        return $this->hasMany(CartItemTopping::class);
    }

    public function toppings()
    {
        return $this->toppingRelations()->with('topping');
    }
}
