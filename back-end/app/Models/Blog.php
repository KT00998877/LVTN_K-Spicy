<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blog — bài viết cho trang public "Tin vui" (/tin-vui/...).
 *
 * Phân loại:
 *  - category: NEWS | PROMOTION | NEW_DISH | EVENT | CULINARY
 *  - news_section: NEWS | OPENING | PROMOTION (alias cho category, phục vụ URL public)
 *
 * Vòng đời:
 *  - DRAFT (manager soạn) → PUBLISHED (hiện public) → ARCHIVED (ẩn khỏi public)
 *
 * Phase 6.2: cho phép AI Marketing Studio "đẩy" version lên blog (status=DRAFT, author_id = manager).
 */
class Blog extends Model
{
    protected $table = 'blogs';

    /** @var list<string> */
    protected $fillable = [
        'title', 'slug', 'excerpt', 'content', 'cover_image', 'gallery',
        'category', 'news_section',
        'status',
        'author_id',
        'published_at',
        'reading_time',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'reading_time' => 'integer',
            'gallery'      => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /* ===== Scopes ===== */

    /** Chỉ bài đã published (và published_at <= nownownow). */
    public function scopePublished($query)
    {
        return $query->where('status', 'PUBLISHED')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** Bài đang ở DRAFT (chờ duyệt/xuất bản). */
    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    /* ===== Lifecycle hooks ===== */

    /**
     * Khi status chuyển sang PUBLISHED, tự động gán published_at = now()
     * nếu chưa có. Tránh bài published nhưng published_at=null (không hiện public).
     */
    protected static function booted(): void
    {
        static::saving(function (self $blog) {
            if ($blog->status === 'PUBLISHED' && $blog->published_at === null) {
                $blog->published_at = now();
            }
        });
    }
}