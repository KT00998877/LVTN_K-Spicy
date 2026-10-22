<?php

namespace App\Models;

use App\Enums\PromotionKind;
use App\Enums\StackingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campaign — chiến dịch marketing gom nhiều voucher / promotion con.
 *
 * Khác với Voucher/Promotion (đơn lẻ), Campaign là "vỏ" để:
 *  - Phục vụ trang public `/tin-vui/tin-khuyen-mai/:slug`.
 *  - Phục vụ AI Marketing Studio (phase sau): lấy 1 campaign → sinh banner/copy.
 *
 * Quy tắc BR-CAMPAIGN-*:
 *  - BR-CAMPAIGN-01: slug unique, dùng làm URL thân thiện.
 *  - BR-CAMPAIGN-02: nhiều campaign/collection có thể trùng thời gian chạy.
 *  - BR-CAMPAIGN-03: is_active=false sẽ ẩn khỏi trang public (kể cả khi còn trong window).
 *  - BR-CAMPAIGN-04: marketing_meta (json) là dữ liệu cho AI — Phase 6 mới dùng.
 */
class Campaign extends Model
{
    protected $table = 'campaigns';

    /** @var list<string> */
    protected $fillable = [
        'slug', 'name', 'cover_image_url', 'description',
        'starts_at', 'ends_at', 'is_active',
        'marketing_meta', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'      => 'datetime',
            'ends_at'        => 'datetime',
            'is_active'      => 'boolean',
            'marketing_meta' => 'array',
        ];
    }

    /* ===== Quan hệ ===== */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** Hiện đang trong thời gian chạy (bỏ qua is_active). */
    public function isWithinWindow(): bool
    {
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;
        return true;
    }

    /** Còn hiệu lực (active + trong window). */
    public function isLive(): bool
    {
        return $this->is_active && $this->isWithinWindow();
    }

    /** Scope: chỉ các campaign đang chạy, sắp theo starts_at DESC. */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }
}