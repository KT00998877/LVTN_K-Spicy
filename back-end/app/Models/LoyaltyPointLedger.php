<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LoyaltyPointLedger — sổ cái giao dịch điểm thưởng của khách.
 *
 * Mỗi dòng đại diện cho 1 phát sinh:
 *  - EARN   : cộng điểm (vd khi khách xác nhận đã nhận hàng)
 *  - REDEEM : trừ điểm (vd khi đổi voucher)
 *  - ADJUST : điều chỉnh thủ công bởi manager
 *
 * `points` mang dấu: dương = cộng, âm = trừ.
 * `balance_after` lưu tổng điểm của user SAU giao dịch này (giúp đối soát lịch sử nhanh).
 */
class LoyaltyPointLedger extends Model
{
    protected $table = 'loyalty_point_ledger';

    /** Ledger chỉ có created_at — không cập nhật. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'reason',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'points'         => 'integer',
            'balance_after'  => 'integer',
            'created_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
