<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShipperLocation — vị trí hiện tại của shipper (Phase 2).
 *
 * App shipper cập nhật vị trị theo chu kỳ (mỗi 60s hoặc khi di chuyển > 200m).
 * Backend dùng vị trí này để tìm shipper gần nhà hàng nhất khi duyệt đơn ONLINE.
 *
 * Quy tắc BR-SHIP-*:
 *  - BR-SHIP-01: 1 shipper chỉ có 1 dòng vị trí (unique user_id) — upsert.
 *  - BR-SHIP-02: vị trí cũ hơn 30 phút coi như không còn hoạt động.
 *  - BR-SHIP-03: chỉ shipper `is_available` mới được gán đơn tự động.
 */
class ShipperLocation extends Model
{
    protected $table = 'shipper_locations';

    /** Vị trí cũ hơn số phút này được coi là offline. */
    public const STALE_MINUTES = 30;

    protected $fillable = [
        'user_id', 'latitude', 'longitude', 'accuracy', 'is_available', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude'     => 'float',
            'longitude'    => 'float',
            'accuracy'     => 'float',
            'is_available' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Vị trí còn "sống" (mới cập nhật trong 30 phút). */
    public function isFresh(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subMinutes(self::STALE_MINUTES));
    }

    /** Scope: chỉ shipper đang rảnh và vị trí còn mới. */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true)
            ->where('last_seen_at', '>=', now()->subMinutes(self::STALE_MINUTES));
    }
}
