<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketingAssetVersion — Một "bản nháp" nội dung do AI (Mock / Gemini / OpenAI) sinh ra.
 *
 * Mỗi asset có nhiều version. Manager so sánh rồi tick "is_selected" trên version muốn duyệt.
 * Sau đó asset chuyển trạng thái APPROVED và có thể publish.
 */
class MarketingAssetVersion extends Model
{
    protected $table = 'marketing_asset_versions';

    /** @var list<string> */
    protected $fillable = [
        'asset_id',
        'version_number',
        'provider', 'provider_model',
        'headline', 'caption', 'body',
        'hashtags', 'meta',
        'prompt_used',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'hashtags'    => 'array',
            'meta'        => 'array',
            'is_selected' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }
}