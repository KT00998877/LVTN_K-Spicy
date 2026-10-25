<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MarketingCampaign — chiến dịch ưu đãi gửi tới khách hàng theo khung giờ vàng.
 *
 * Khác với `Campaign` (trang public / AI Marketing Studio), bảng này mô tả
 * một lần gửi ưu đãi có kiểm soát:
 *  - Gắn 1 mã voucher (`voucher_code` → vouchers.code) để khách copy.
 *  - Có "khung giờ vàng": `send_at` là mốc mong muốn, `window_start`/`window_end`
 *    là cửa sổ cho phép gửi (nếu quá cửa sổ thì đánh dấu SKIPPED).
 *  - `target_tier_ids` giới hạn hạng thành viên được nhận (null = tất cả).
 *
 * Quy tắc BR-MKT-*:
 *  - BR-MKT-01: chỉ campaign SCHEDULED mới được gửi; SENT/SKIPPED/CANCELLED là kết thúc.
 *  - BR-MKT-02: ưu đãi có mã voucher bắt buộc (không gửi mà không có mã để dùng).
 *  - BR-MKT-03: khung giờ vàng luôn nằm trong 00:00–23:59, window_start < window_end.
 */
class MarketingCampaign extends Model
{
    protected $table = 'marketing_campaigns';

    /** @var list<string> */
    protected $fillable = [
        'name', 'message', 'voucher_code',
        'banner_image_url', 'accent_color', 'cta_text', 'landing_url',
        'target_tier_ids', 'send_at', 'window_start', 'window_end',
        'status', 'sent_at', 'recipient_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_tier_ids' => 'array',
            'send_at'         => 'datetime',
            'window_start'    => 'string:H:i',
            'window_end'      => 'string:H:i',
            'sent_at'         => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_code', 'code');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(MarketingCampaignClaim::class);
    }

    /* ===== Trạng thái ===== */

    public const STATUS_SCHEDULED = 'SCHEDULED';
    public const STATUS_SENT      = 'SENT';
    public const STATUS_SKIPPED   = 'SKIPPED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Còn chờ gửi không. */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** Đã tới mốc gửi chưa. */
    public function isDue(): bool
    {
        return $this->send_at !== null && $this->send_at->lte(now());
    }

    /**
     * Hiện tại có nằm trong khung giờ vàng không.
     *
     * Window được hiểu theo giờ trong ngày: nếu window_start <= window_end thì
     * so trực tiếp; nếu window_start > window_end (vd gửi qua đêm) thì
     * "nằm trong cửa sổ" = đã qua window_start HOẶC chưa tới window_end.
     */
    public function isWithinWindow(): bool
    {
        $current = now()->format('H:i');
        $start   = substr((string) $this->window_start, 0, 5);
        $end     = substr((string) $this->window_end, 0, 5);

        if ($start <= $end) return $current >= $start && $current <= $end;
        return $current >= $start || $current <= $end;
    }

    /** Đã quá cửa sổ gửi mà vẫn chưa gửi (đánh dấu SKIPPED). */
    public function isPastWindow(): bool
    {
        return $this->send_at !== null
            && $this->send_at->lt(now()->subDay())
            && ! $this->isWithinWindow();
    }

    /* ===== Scope ===== */

    /** Campaign đang chờ gửi. */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /** Campaign còn hiệu lực để hiển thị banner cho khách. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_SENT])
            ->where('send_at', '<=', now())
            ->orderByDesc('send_at')
            ->orderByDesc('id');
    }
}
