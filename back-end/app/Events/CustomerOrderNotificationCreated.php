<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CustomerOrderNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $recipientId, public array $notification)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('customer.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'customer.order.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
