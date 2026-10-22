<?php

namespace App\Models;

use App\Enums\PromotionKind;
use App\Enums\StackingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promotion — khuyến mãi tự động (khác voucher là KHÔNG cần khách nhập mã).
 *
 * Bốn loại (xem App\Enums\PromotionKind):
 *  - PERCENT_OFF: giảm % trên subtotal phạm vi áp dụng (value ∈ [1,100], kèm max_discount).
 *  - FIXED_OFF:   giảm cố định 1 số tiền (value ≥ 1.000đ).
 *  - BXGY:        "Mua X tặng Y" (buy_qty, get_qty, get_item_ids, discount_percent).
 *  - COMBO:       Bundle cố định giá (bundle_items[], bundle_price).
 *
 * Cấu hình lưu ở `config` (json), điều kiện ở `conditions` (json) — JSON để dễ mở rộng
 * mà không phải ALTER TABLE nhiều lần.
 *
 * Quy tắc BR-PROMO-* (Phase 2 Rule Engine sẽ enforce):
 *  - BR-PROMO-01: 1 đơn có thể áp NHIỀU promotion (theo stacking_mode + priority).
 *  - BR-PROMO-02: priority cao áp trước; cùng priority → cái giảm nhiều thắng.
 *  - BR-PROMO-03: promotion đã có usage thì KHÔNG xoá cứng — chỉ is_active=false.
 *  - BR-PROMO-05: marketing_meta (json) — dữ liệu cho AI Marketing Studio phase sau.
 */
class Promotion extends Model
{
    protected $table = 'promotions';

    /** @var list<string> */
    protected $fillable = [
        'campaign_id', 'slug', 'name', 'description',
        'kind', 'config', 'conditions',
        'stacking_mode', 'priority',
        'usage_limit', 'used_count', 'usage_limit_per_user',
        'starts_at', 'ends_at', 'is_active',
        'marketing_meta', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind'                  => PromotionKind::class,
            'stacking_mode'          => StackingMode::class,
            'config'                => 'array',
            'conditions'            => 'array',
            'marketing_meta'        => 'array',
            'priority'              => 'integer',
            'usage_limit'           => 'integer',
            'used_count'            => 'integer',
            'usage_limit_per_user'  => 'integer',
            'starts_at'             => 'datetime',
            'ends_at'               => 'datetime',
            'is_active'             => 'boolean',
        ];
    }

    /* ===== Quan hệ ===== */

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /* ===== Helpers (Phase 2 sẽ dùng cho Rule Engine) ===== */

    public function isWithinWindow(): bool
    {
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;
        return true;
    }

    public function hasGlobalQuota(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /** Scope: chỉ lấy promotion đang chạy, sort theo priority giảm dần. */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('priority')
            ->orderByDesc('id');
    }
}