<?php

namespace App\Services;

use App\Events\CustomerOrderNotificationCreated;
use App\Events\StaffNotificationCreated;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationNotificationService
{
    public function created(Reservation $reservation): void
    {
        $cashierIds = User::where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('code', 'CASHIER'))
            ->pluck('id');
        foreach ($cashierIds as $cashierId) {
            $data = [
                'event' => 'RESERVATION_CREATED',
                'reservation_id' => $reservation->id,
                'order_code' => 'Đặt bàn #'.$reservation->id,
                'message' => "{$reservation->customer_name} đặt bàn {$reservation->guest_count} khách lúc ".substr($reservation->reserved_time, 0, 5).' ngày '.$reservation->reserved_date->format('d/m/Y').'.',
                'url' => '/staff/cashier/reservations?reservation_id='.$reservation->id,
            ];
            $this->send((int) $cashierId, 'staff.reservation', $data, 'RESERVATION_CREATED:'.$reservation->id.':'.$cashierId, true);
        }
    }

    public function decided(Reservation $reservation, string $previousStatus): void
    {
        if (!$reservation->customer_id || !in_array($reservation->status, ['CONFIRMED', 'CANCELLED'], true)) return;
        $confirmed = $reservation->status === 'CONFIRMED';
        $rejected = !$confirmed && $previousStatus === 'PENDING';
        $data = [
            'event' => $confirmed ? 'RESERVATION_CONFIRMED' : ($rejected ? 'RESERVATION_REJECTED' : 'RESERVATION_CANCELLED'),
            'reservation_id' => $reservation->id,
            'name' => $confirmed ? 'Đặt bàn đã được xác nhận' : ($rejected ? 'Đặt bàn đã bị từ chối' : 'Đặt bàn đã bị hủy'),
            'message' => $confirmed
                ? 'Nhà hàng đã xác nhận đặt bàn ngày '.$reservation->reserved_date->format('d/m/Y').' lúc '.substr($reservation->reserved_time, 0, 5).'.'
                : ($rejected
                    ? 'Nhà hàng không thể tiếp nhận đặt bàn ngày '.$reservation->reserved_date->format('d/m/Y').' lúc '.substr($reservation->reserved_time, 0, 5).'. Vui lòng chọn lịch khác hoặc liên hệ nhà hàng.'
                    : 'Nhà hàng đã hủy đặt bàn ngày '.$reservation->reserved_date->format('d/m/Y').' lúc '.substr($reservation->reserved_time, 0, 5).'. Vui lòng liên hệ nhà hàng.'),
            'cta_text' => 'Xem đặt bàn',
            'url' => '/tai-khoan?tab=reservations',
        ];
        $this->send((int) $reservation->customer_id, 'customer.reservation', $data, $data['event'].':'.$reservation->id.':'.$reservation->customer_id, false);
    }

    public function remind(Reservation $reservation): void
    {
        if (!$reservation->customer_id || $reservation->status !== 'CONFIRMED') return;
        $schedule = $reservation->reserved_date->format('d/m/Y').' lúc '.substr($reservation->reserved_time, 0, 5);
        $this->send((int) $reservation->customer_id, 'customer.reservation', [
            'event' => 'RESERVATION_REMINDER',
            'reservation_id' => $reservation->id,
            'name' => 'Sắp đến giờ đặt bàn',
            'message' => 'Bạn có lịch đặt bàn '.$schedule.'. Bàn được giữ tối đa '.config('reservations.hold_minutes').' phút kể từ giờ đặt.',
            'cta_text' => 'Xem đặt bàn',
            'url' => '/tai-khoan?tab=reservations',
        ], 'RESERVATION_REMINDER:'.$reservation->id.':'.$reservation->reserved_date->format('Ymd').':'.substr($reservation->reserved_time, 0, 5), false);
    }

    private function send(int $recipientId, string $type, array $data, string $key, bool $staff): void
    {
        $id = (string) Str::uuid();
        $createdAt = now();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id, 'type' => $type, 'notifiable_type' => User::class,
            'notifiable_id' => $recipientId, 'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => $key, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        if (!$inserted) return;
        $payload = ['id' => $id, 'data' => $data, 'read_at' => null, 'created_at' => $createdAt->toIso8601String()];
        if ($staff) StaffNotificationCreated::dispatch($recipientId, $payload);
        else CustomerOrderNotificationCreated::dispatch($recipientId, $payload);
    }
}
