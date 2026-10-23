<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LiveChatMessage — một tin nhắn trong phiên tư vấn trực tiếp.
 *
 * @property int $id
 * @property int $session_id
 * @property string $sender_type  customer|staff|system
 */
class LiveChatMessage extends Model
{
    protected $table = 'live_chat_messages';

    /** Tin nhắn không dùng updated_at — bảng chỉ có created_at. */
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'sender_type',
        'sender_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
