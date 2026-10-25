<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'delivery_latitude', 'delivery_longitude', 'delivery_distance_km', 'points_redeemed',
        'recipient_name', 'recipient_phone', 'delivery_address', 'shipping_fee',
        'code',
        'customer_id',
        'waiter_id',
        'table_session_id',
        'order_type',
        'subtotal',
        'discount_amount',
        'total_amount',
        'payment_method',
        'note',
        'status',
        'placed_at',
        'completed_at',
        'customer_received_at',
        'customer_received_by',
        'points_awarded',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'inventory_processed_at',
        'inventory_processed_user_id',
        'inventory_processed_event',
        'inventory_reversed_at',
        'inventory_reversed_user_id',
        'inventory_reversal_reason',
        'delivery_rating',
        'delivery_comment',
        'delivery_rated_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'      => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount'  => 'decimal:2',
            'shipping_fee'  => 'decimal:2',
            'placed_at'     => 'datetime',
            'completed_at'  => 'datetime',
            'confirmed_at'  => 'datetime',
            'handed_over_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'arrived_at'    => 'datetime',
            'delivered_at'  => 'datetime',
            'customer_received_at' => 'datetime',
            'cancelled_at'  => 'datetime',
            'inventory_processed_at' => 'datetime',
            'inventory_reversed_at'  => 'datetime',
            'points_awarded' => 'integer',
            'delivery_rating' => 'integer',
            'delivery_rated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Phiên phục vụ chứa order này (1 order DINE_IN gắn với 1 phiên). */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /** Ảnh khách chụp kèm đánh giá dịch vụ giao. */
    public function deliveryPhotos(): HasMany
    {
        return $this->hasMany(OrderDeliveryPhoto::class)->orderBy('sort_order');
    }
}
