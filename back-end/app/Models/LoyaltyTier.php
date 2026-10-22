<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LoyaltyTier — hạng thành viên khách hàng.
 *
 * Tiers được sắp theo `sort_order` (Đồng < Bạc < Vàng < Kim Cương).
 * Cột `min_spent` là ngưỡng TỔNG CHI TIÊU (VND) tối thiểu để thăng hạng.
 *
 * Hệ thống giữ logic tăng hạng MỘT CHIỀU (không tụt hạng khi spend giảm).
 * Hạng được "đóng băng" cao nhất đã đạt → LoyaltyTierService::evaluateAndUpgrade().
 */
class LoyaltyTier extends Model
{
    protected $table = 'loyalty_tiers';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'min_spent',
        'color',
        'icon',
        'benefits',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_spent'  => 'integer',
            'benefits'   => 'array',
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /** Quan hệ ngược — danh sách user thuộc hạng. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'loyalty_tier_id');
    }

    /** Scope chỉ lấy các hạng đang active, sắp theo sort_order. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('min_spent');
    }

    /** Hệ số nhân điểm EARN (mặc định 1.0). */
    public function pointMultiplier(): float
    {
        return (float) (($this->benefits['point_multiplier'] ?? 1.0));
    }

    /** Ngưỡng đơn (VND) để được free ship — null = không có ưu đãi free ship. */
    public function freeShipThreshold(): ?int
    {
        $v = $this->benefits['free_ship_threshold'] ?? null;
        return $v === null ? null : (int) $v;
    }

    /** % giảm thêm trên subtotal — 0 = không có. */
    public function extraDiscountPercent(): float
    {
        return (float) ($this->benefits['extra_discount_pct'] ?? 0);
    }

    /** Mô tả ngắn quyền lợi (UI phụ). */
    public function benefitsDescription(): ?string
    {
        return $this->benefits['description'] ?? null;
    }
}
