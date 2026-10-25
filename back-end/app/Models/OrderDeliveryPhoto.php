<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderDeliveryPhoto — ảnh khách chụp kèm đánh giá dịch vụ giao.
 */
class OrderDeliveryPhoto extends Model
{
    protected $table = 'order_delivery_photos';

    protected $fillable = [
        'order_id',
        'path',
        'url',
        'mime',
        'size_kb',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_kb'    => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
