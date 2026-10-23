<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LiveChatMessage — một tin nhắn trong phiên tư vấn trực tiếp.
 *
 * `from_suggestion` đánh dấu tin nhắn nhân viên bấm từ gợi ý nhanh, dùng để
 * đo tỉ lệ gợi ý AI thực sự được dùng.
 *
 * @property int $id
 * @property int $session_id
 * @property string $sender_type  customer|staff|system
 */
class LiveChatMessage extends Model
{
    protected $table = 'live_chat_messages';

    protected $fillable = [
        'session_id',
        'sender_type',
        'sender_id',
        'content',
        'from_suggestion',
    ];

    protected $casts = [
        'from_suggestion' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveChatSession::class, 'session_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}