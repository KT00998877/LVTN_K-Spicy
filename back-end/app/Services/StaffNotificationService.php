<?php

namespace App\Services;

use App\Events\StaffNotificationCreated;
use App\Models\{Order, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffNotificationService
{
    public function orderCreated(Order $order): void
    {
        if ($order->order_type !== 'ONLINE') return;
        $this->sendRole($order, 'MANAGER', 'ONLINE_ORDER_CREATED', 'Bạn có đơn hàng trực tuyến mới', '/staff/manager/online-orders');
    }

    public function orderConfirmed(Order $order): void
    {
        if ($order->order_type !== 'ONLINE') return;
        $this->sendRole($order, 'KITCHEN', 'ONLINE_ORDER_CONFIRMED', 'Bạn có đơn hàng trực tuyến mới', '/staff/kitchen/queue');
        if ($order->shipper_id) {
            $this->send($order, (int) $order->shipper_id, 'SHIPPER_ASSIGNED', 'Bạn có đơn hàng mới từ K-SPICY', '/staff/shipper');
        }
    }

    public function kitchenReady(Order $order): void
    {
        if ($order->order_type !== 'ONLINE' || !$order->shipper_id) return;
        $this->send($order, (int) $order->shipper_id, 'KITCHEN_READY', 'Đơn hàng đã được chế biến xong, vui lòng đến lấy hàng', '/staff/shipper');
    }

    private function sendRole(Order $order, string $role, string $type, string $message, string $path): void
    {
        $ids = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', $role))->pluck('id');
        foreach ($ids as $id) $this->send($order, (int) $id, $type, $message, $path);
    }

    private function send(Order $order, int $recipientId, string $type, string $message, string $path): void
    {
        $id = (string) Str::uuid();
        $data = ['event' => $type, 'order_id' => $order->id, 'order_code' => $order->code,
            'message' => $message, 'url' => $path.'?order_id='.$order->id];
        $createdAt = now();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id, 'type' => 'staff.order', 'notifiable_type' => User::class,
            'notifiable_id' => $recipientId, 'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => $type.':'.$order->id.':'.$recipientId,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        if ($inserted) {
            StaffNotificationCreated::dispatch($recipientId, ['id' => $id, 'data' => $data,
                'read_at' => null, 'created_at' => $createdAt->toIso8601String()]);
        }
    }
}
