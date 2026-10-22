<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReviewPhoto — ảnh đính kèm 1 review.
 * Lưu trong disk `public` (storage/app/public/review-photos/...).
 */
class ReviewPhoto extends Model
{
    protected $table = 'review_photos';

    protected $fillable = [
        'review_id', 'path', 'url', 'mime', 'size_kb', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_kb'    => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}