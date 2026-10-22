<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StaffOrderUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $orderId, public ?int $shipperId)
    {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('staff-role.MANAGER'), new PrivateChannel('staff-role.KITCHEN')];
        if ($this->shipperId) $channels[] = new PrivateChannel('staff.'.$this->shipperId);
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'staff.order.updated';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId];
    }
}
