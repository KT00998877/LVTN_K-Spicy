<?php

namespace App\Services;

use App\Events\CustomerOrderNotificationCreated;
use App\Models\{Order, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerOrderNotificationService
{
    public function refundCompleted(Order $order): void
    {
        if (!$order->customer_id) return;

        $id = (string) Str::uuid();
        $data = [
            'event' => 'ORDER_REFUND_COMPLETED',
            'order_id' => (int) $order->id,
            'order_code' => $order->code,
            'name' => 'Hoàn tiền thành công',
            'message' => "Đơn {$order->code} đã được hoàn tiền thành công qua VNPay Sandbox.",
            'cta_text' => 'Xem đơn hàng',
            'url' => '/tai-khoan/don-hang/'.$order->id,
        ];
        $createdAt = now();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id,
            'type' => 'customer.order',
            'notifiable_type' => User::class,
            'notifiable_id' => $order->customer_id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => 'ORDER_REFUND_COMPLETED:'.$order->id.':'.$order->customer_id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if ($inserted) {
            CustomerOrderNotificationCreated::dispatch((int) $order->customer_id, [
                'id' => $id,
                'data' => $data,
                'read_at' => null,
                'created_at' => $createdAt->toIso8601String(),
            ]);
        }
    }
}
