<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ChatSession - Một phiên hội thoại chatbot.
 *
 * Guest được hỗ trợ: user_id có thể null (chat chỉ đọc).
 * Write tool (cart_add_item) yêu cầu đăng nhập → pending action luôn có user_id.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $status
 */
class ChatSession extends Model
{
    protected $table = 'chat_sessions';

    protected $fillable = [
        'user_id',
        'guest_token',
        'title',
        'status',
        'metadata',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * User sở hữu session (null nếu guest).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Messages trong session.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    /**
     * Pending actions đang chờ xác nhận.
     */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(ChatPendingAction::class, 'session_id');
    }

    /**
     * Scope: session còn hoạt động.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}