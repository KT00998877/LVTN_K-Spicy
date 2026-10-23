<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LiveChatQuickReply — mẫu câu trả lời nhanh cho nhân viên tư vấn.
 *
 * Đây là FALLBACK khi AI không gợi ý được. Nhân viên luôn có sẵn câu mẫu
 * để bấm, kể cả khi dịch vụ AI gián đoạn.
 *
 * @property int $id
 * @property string $title
 * @property string $content
 */
class LiveChatQuickReply extends Model
{
    protected $table = 'live_chat_quick_replies';

    protected $fillable = [
        'title',
        'content',
        'category',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
