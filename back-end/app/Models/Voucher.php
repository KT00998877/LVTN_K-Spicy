<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Voucher — mã giảm giá do Manager tạo, khách nhập khi checkout.
 *
 * Hai loại:
 *  - PERCENT: giảm % giá trị đơn (value ∈ [1,100]), kèm max_discount_amount là trần.
 *  - FIXED:   giảm cố định một số tiền (value ≥ 1.000đ).
 *
 * Điều kiện áp dụng:
 *  - is_active = true
 *  - Trong khoảng starts_at / ends_at (nếu có)
 *  - used_count < usage_limit (nếu có)
 *  - Đơn phải ≥ min_order_subtotal (chưa tính phí ship)
 */
class Voucher extends Model
{
    protected $table = 'vouchers';

    protected $fillable = [
        'code', 'name', 'description',
        'type', 'value', 'max_discount_amount', 'min_order_subtotal',
        'usage_limit', 'used_count', 'usage_limit_per_user',
        'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value'               => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_subtotal'  => 'decimal:2',
            'usage_limit'         => 'integer',
            'used_count'          => 'integer',
            'usage_limit_per_user'=> 'integer',
            'starts_at'           => 'datetime',
            'ends_at'             => 'datetime',
            'is_active'           => 'boolean',
        ];
    }

    public const TYPE_PERCENT = 'PERCENT';
    public const TYPE_FIXED   = 'FIXED';

    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Còn hiệu lực không (chưa xét min_order / usage_limit). */
    public function isWithinWindow(): bool
    {
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;
        return true;
    }

    /** Còn lượt dùng tổng không (NULL = vô hạn). */
    public function hasGlobalQuota(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /** Tính số tiền giảm cho 1 đơn có subtotal = $cartSubtotal (VND, không gồm ship). */
    public function calculateDiscount(float $cartSubtotal): int
    {
        $subtotal = max(0, (int) round($cartSubtotal));
        if ($subtotal < (int) round((float) $this->min_order_subtotal)) return 0;

        if ($this->type === self::TYPE_PERCENT) {
            $percent = max(0, min(100, (float) $this->value));
            $discount = (int) floor($subtotal * $percent / 100);
        } else {
            $discount = (int) round((float) $this->value);
        }

        if ($this->max_discount_amount !== null) {
            $discount = min($discount, (int) round((float) $this->max_discount_amount));
        }

        // Không giảm quá giá trị đơn.
        return max(0, min($discount, $subtotal));
    }
}