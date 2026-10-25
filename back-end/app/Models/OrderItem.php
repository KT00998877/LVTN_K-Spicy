<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'item_name',
        'unit_price',
        'quantity',
        'subtotal',
        'note',
        'spicy_level_override',
        'status',
        'started_at',
        'recipe_snapshot',
        'recipe_captured_at',
        'inventory_deducted_at',
        'inventory_deduction_key',
        'ready_at',
        'served_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'             => 'decimal:2',
            'subtotal'               => 'decimal:2',
            'spicy_level_override'   => 'integer',
            'started_at'             => 'datetime',
            'recipe_snapshot'        => 'array',
            'recipe_captured_at'     => 'datetime',
            'ready_at'               => 'datetime',
            'served_at'              => 'datetime',
            'service_reminded_at'    => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /**
     * Topping của order item (quan hệ với menu_items có category = topping-them).
     */
    public function toppings(): HasMany
    {
        return $this->hasMany(OrderItemTopping::class);
    }

    /**
     * Độ cay hiệu lực của order item (override nếu có, ngược lại lấy của menu_item).
     */
    public function getEffectiveSpicyLevelAttribute(): int
    {
        if ($this->spicy_level_override !== null) {
            return (int) $this->spicy_level_override;
        }
        return (int) ($this->menuItem?->spicy_level ?? 0);
    }
}
