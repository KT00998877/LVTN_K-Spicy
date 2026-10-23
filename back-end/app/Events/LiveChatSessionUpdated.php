<?php

namespace App\Events;

use App\Models\LiveChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Trạng thái phiên tư vấn thay đổi (khách vào hàng chờ, nhân viên nhận, đóng).
 *
 * Phát tới kênh hàng chờ CHUNG để mọi nhân viên thấy khách mới ngay — nếu
 * chỉ phát tới kênh phiên thì nhân viên chưa mở phiên đó sẽ không biết có
 * khách đang chờ.
 */
class LiveChatSessionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveChatSession $session,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live-chat-queue'),
            new PrivateChannel('live-chat-session.' . $this->session->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live-chat.session.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'status' => $this->session->status,
            'assigned_staff_id' => $this->session->assigned_staff_id,
            'assigned_staff_name' => $this->session->assignedStaff?->name,
            'customer_name' => $this->session->customer_name,
            'last_message_at' => $this->session->last_message_at?->toIso8601String(),
            'updated_at' => $this->session->updated_at?->toIso8601String(),
        ];
    }
}
