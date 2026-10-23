<?php

namespace App\Events;

use App\Models\LiveChatMessage;
use App\Models\LiveChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tin nhắn mới trong một phiên tư vấn trực tiếp.
 *
 * ShouldBroadcastNow: nhân viên đang nhìn khung chat cần thấy tin nhắn ngay,
 * không chấp nhận độ trễ vài giây của hàng đợi.
 */
class LiveChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveChatSession $session,
        public LiveChatMessage $message,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live-chat-session.' . $this->session->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live-chat.message.sent';
    }

    /**
     * Dữ liệu gửi đi.
     *
     * `session` đi kèm để client cập nhật trạng thái (waiting → accepted,
     * tên nhân viên đã nhận) mà không phải gọi thêm API.
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'message' => [
                'id' => $this->message->id,
                'session_id' => $this->message->session_id,
                'sender_type' => $this->message->sender_type,
                'sender_id' => $this->message->sender_id,
                'sender_name' => $this->message->sender_type === 'staff'
                    ? ($this->session->assigned_staff_id ? $this->message->sender?->name : null)
                    : ($this->message->sender_type === 'customer' ? $this->session->customer_name : null),
                'content' => $this->message->content,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
            'session' => [
                'id' => $this->session->id,
                'status' => $this->session->status,
                'assigned_staff_id' => $this->session->assigned_staff_id,
                'assigned_staff_name' => $this->session->assignedStaff?->name,
                'customer_name' => $this->session->customer_name,
                'last_message_at' => $this->session->last_message_at?->toIso8601String(),
            ],
        ];
    }
}
