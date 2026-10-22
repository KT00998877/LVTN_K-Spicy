<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Review — đánh giá của khách cho 1 món ăn.
 *
 * Quy tắc nghiệp vụ (BR-REVIEW-*):
 *  - BR-REVIEW-01: khách đã mua món (order COMPLETED) mới review được.
 *  - BR-REVIEW-02: 1 user + 1 menu_item chỉ review được 1 lần (đảm bảo bằng unique index).
 *  - BR-REVIEW-03: rating ∈ [1, 5], comment ≤ 1000 ký tự.
 *  - BR-REVIEW-05: review.is_approved mặc định true (auto-approve), manager có thể ẩn qua `is_hidden`.
 */
class Review extends Model
{
    protected $table = 'reviews';

    protected $fillable = [
        'user_id', 'menu_item_id', 'order_id',
        'rating', 'comment',
        'is_approved', 'is_hidden',
        'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'rating'      => 'integer',
            'is_approved' => 'boolean',
            'is_hidden'   => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}