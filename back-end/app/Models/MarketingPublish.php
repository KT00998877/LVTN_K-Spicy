<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketingPublish — Log mỗi lần asset được publish ra kênh ngoài.
 *
 * Phase 6.1: log publish ra Blog website (internal).
 * Phase 6.3+: thêm publish ra Facebook, Instagram, Zalo, TikTok.
 */
class MarketingPublish extends Model
{
    protected $table = 'marketing_publishes';

    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'version_id',
        'channel',
        'status', 'response_payload', 'error_message',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'published_at'     => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MarketingAssetVersion::class, 'version_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}