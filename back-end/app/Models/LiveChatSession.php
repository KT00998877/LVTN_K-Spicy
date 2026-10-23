<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LiveChatSession — một phiên tư vấn trực tiếp giữa khách và nhân viên.
 *
 * Cột bám theo bảng `live_chat_sessions` đã có sẵn: `subject` (chủ đề khách
 * nêu), `guest_token` và `metadata` là cột dự phòng.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $assigned_staff_id
 * @property string $status
 */
class LiveChatSession extends Model
{
    protected $table = 'live_chat_sessions';

    protected $fillable = [
        'user_id',
        'guest_token',
        'assigned_staff_id',
        'customer_name',
        'customer_phone',
        'subject',
        'status',
        'metadata',
        'accepted_at',
        'closed_at',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'accepted_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveChatMessage::class, 'session_id');
    }

    /** Phiên đang chờ nhân viên nhận. */
    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }

    /** Phiên chưa kết thúc — dùng để tái sử dụng phiên của khách. */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'accepted']);
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}