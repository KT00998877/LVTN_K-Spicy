<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChatMessage - Một tin nhắn trong chatbot session.
 *
 * metadata lưu intent, tool_calls, confirmation_required, pending_action_ids,
 * citations (RAG) và các metadata phục vụ audit/debug.
 *
 * @property int $id
 * @property int $chat_session_id
 * @property string $role
 * @property string $content
 * @property array|null $metadata
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    /**
     * The existing schema (migration 2026_10_18_000002) names the FK `session_id`,
     * stores no `updated_at`, and allows the 'tool' role.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'metadata',
        'request_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Session chứa message này.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /**
     * Pending action được tạo từ message này (nếu có).
     */
    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(ChatPendingAction::class, 'message_id');
    }

    /**
     * Message này có yêu cầu xác nhận không.
     */
    public function requiresConfirmation(): bool
    {
        return (bool) ($this->metadata['confirmation_required'] ?? false);
    }

    /**
     * Danh sách pending action id liên kết với message.
     */
    public function pendingActionIds(): array
    {
        return $this->metadata['pending_action_ids'] ?? [];
    }
}