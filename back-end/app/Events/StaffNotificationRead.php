<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class StaffNotificationRead implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $recipientId, public string $notificationId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'staff.notification.read';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId];
    }
}
