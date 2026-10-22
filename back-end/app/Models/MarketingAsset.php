<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MarketingAsset — Một "chiến dịch nội dung" do manager tạo trong AI Marketing Studio.
 *
 * Vòng đời:
 *   DRAFT → IN_REVIEW → APPROVED → (SCHEDULED) → PUBLISHED
 *                ↓
 *             REJECTED / ARCHIVED
 *
 * Mỗi asset có thể có nhiều version (lưu ở marketing_asset_versions).
 * Manager chọn 1 version làm "is_selected" trước khi publish.
 */
class MarketingAsset extends Model
{
    protected $table = 'marketing_assets';

    /** @var list<string> */
    protected $fillable = [
        'created_by', 'campaign_id',
        'channel', 'content_type',
        'subject', 'tone', 'context_payload',
        'status',
        'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'context_payload' => 'array',
            'approved_at'     => 'datetime',
        ];
    }

    /* ===== Quan hệ ===== */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MarketingAssetVersion::class, 'asset_id')->orderBy('version_number');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(MarketingUpload::class, 'asset_id');
    }

    public function publishes(): HasMany
    {
        return $this->hasMany(MarketingPublish::class, 'asset_id');
    }

    /** Version được manager chọn (is_selected=true). */
    public function selectedVersion(): ?MarketingAssetVersion
    {
        return $this->versions()->where('is_selected', true)->first();
    }

    /* ===== Scopes ===== */

    /** Scope: chỉ lấy asset ở trạng thái đang trong pipeline (chưa archive). */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['ARCHIVED']);
    }
}