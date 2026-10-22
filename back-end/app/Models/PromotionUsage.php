<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PromotionUsage — log mỗi lần promotion được áp cho 1 đơn.
 *
 * Tương tự VoucherUsage:
 *  - `unique(order_id, promotion_id)` → 1 promotion chỉ ghi 1 dòng / đơn.
 *  - 1 đơn có thể có NHIỀU promotion (stacking) → nhiều dòng khác promotion_id.
 *
 * `user_id` nullable vì:
 *  - Đơn DINE_IN có thể không gắn user cụ thể (khách tại bàn).
 *  - Phase 5 customer UI sẽ vẫn aggregate theo user_id cho "Top khách dùng nhiều km".
 */
class PromotionUsage extends Model
{
    protected $table = 'promotion_usages';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'promotion_id', 'user_id', 'order_id', 'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'created_at'      => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}