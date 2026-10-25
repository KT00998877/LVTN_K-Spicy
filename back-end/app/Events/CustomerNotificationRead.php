<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CustomerNotificationRead — khách đã đọc 1 thông báo trong chuông.
 *
 * Dùng để đồng bộ badge "chưa đọc" giữa nhiều tab đang mở.
 */
class CustomerNotificationRead implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $recipientId, public string $notificationId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('customer.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'customer.notification.read';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId];
    }
}
