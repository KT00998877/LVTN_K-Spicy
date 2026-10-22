<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChatPendingAction - Lưu trữ các write tool action đang chờ xác nhận từ user.
 * 
 * @property int $id
 * @property string $idempotency_key
 * @property int $session_id
 * @property int|null $message_id
 * @property int $user_id
 * @property string $action_type
 * @property string $tool_name
 * @property array $payload
 * @property string $endpoint
 * @property array $summary
 * @property string $status
 * @property array|null $result
 * @property string|null $error_message
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $confirmed_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property string|null $request_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ChatPendingAction extends Model
{
    protected $table = 'chat_pending_actions';

    protected $fillable = [
        'idempotency_key',
        'session_id',
        'message_id',
        'user_id',
        'action_type',
        'tool_name',
        'payload',
        'endpoint',
        'summary',
        'status',
        'result',
        'error_message',
        'expires_at',
        'confirmed_at',
        'cancelled_at',
        'request_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'summary' => 'array',
        'result' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Quan hệ với ChatSession.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /**
     * Quan hệ với ChatMessage.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /**
     * Quan hệ với User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Lấy các pending action còn hiệu lực.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope: Lấy các pending action hết hạn.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '<=', now());
    }

    /**
     * Kiểm tra action có còn hiệu lực không.
     */
    public function isValid(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    /**
     * Kiểm tra user có quyền confirm/cancel action này không.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }
}
