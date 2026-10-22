<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketingUpload — Media (ảnh/video/tệp) đính kèm cho một MarketingAsset.
 *
 * Chỉ lưu đường dẫn + metadata, không lưu binary.
 * File thực tế nằm trên disk (mặc định 'public').
 */
class MarketingUpload extends Model
{
    protected $table = 'marketing_uploads';

    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'uploaded_by',
        'disk', 'path',
        'original_name', 'mime_type', 'size_bytes',
        'width', 'height',
        'role', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width'      => 'integer',
            'height'     => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** URL truy cập file qua asset() (ký tự ổn định theo disk). */
    public function getUrlAttribute(): string
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->url($this->path);
    }
}