<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VoucherUsage — log mỗi lần khách dùng voucher cho 1 đơn.
 *
 * - `order_id` unique → 1 đơn chỉ áp 1 voucher (BR-VOUCHER-01).
 * - Dùng để:
 *   - Đếm usage_limit_per_user (đếm số dòng theo user_id).
 *   - Tra cứu lịch sử khuyến mãi cho khách / manager.
 */
class VoucherUsage extends Model
{
    protected $table = 'voucher_usages';

    public const UPDATED_AT = null;

    protected $fillable = [
        'voucher_id', 'user_id', 'order_id', 'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'created_at'      => 'datetime',
        ];
    }

    /**
     * discount_amount là cột NOT NULL — set mặc định 0 nếu caller quên.
     * (DB không có default để bắt buộc truyền rõ ràng; helper này chỉ để test/dev.)
     */
    protected $attributes = [
        'discount_amount' => 0,
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
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