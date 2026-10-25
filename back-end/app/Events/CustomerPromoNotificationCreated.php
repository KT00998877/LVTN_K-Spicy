<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CustomerPromoNotificationCreated — báo cho khách hàng về ưu đãi mới.
 *
 * Kênh `customer.{id}` là kênh riêng của từng khách (đăng ký trong
 * routes/channels.php), nên chỉ chính khách đó nghe được.
 */
class CustomerPromoNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'customer.promo.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
