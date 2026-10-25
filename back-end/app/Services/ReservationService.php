<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Table;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public const HOLDING_STATUSES = ['PENDING', 'CONFIRMED', 'ARRIVED'];

    public function create(array $data): Reservation
    {
        return DB::transaction(function () use ($data) {
            // Lock in a stable order: serializes allocation and retries across all tables.
            // No duration exists in this schema: a collision is the same date/minute.
            $tables = Table::orderBy('id')->lockForUpdate()->get();
            $payload = array_replace(array_fill_keys(['table_id', 'location', 'note'], null), $data);
            unset($payload['request_key']);
            ksort($payload);
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
            $existing = Reservation::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => ['Mã yêu cầu đã được sử dụng với thông tin khác. Vui lòng tải lại trang.']]);
                }
                return $existing;
            }
            // Time may have advanced while waiting for allocation locks.
            $errors = ReservationSchedule::errors($data['reserved_date'], $data['reserved_time']);
            if ($errors) throw ValidationException::withMessages($errors);
            $busy = Reservation::whereDate('reserved_date', $data['reserved_date'])
                ->where('reserved_time', $data['reserved_time'].':00')
                ->whereIn('status', self::HOLDING_STATUSES)->pluck('table_id')->all();
            $today = CarbonImmutable::now(config('reservations.timezone'))->toDateString();
            $candidates = $tables->filter(function (Table $table) use ($data, $busy, $today) {
                if (!empty($data['table_id']) && $table->id !== (int) $data['table_id']) return false;
                if (!empty($data['location']) && $table->location !== $data['location']) return false;
                if (!$table->is_active || $table->capacity < $data['guest_count'] || in_array($table->id, $busy)) return false;
                // Live table status cannot predict future days. For today be conservative.
                if ($data['reserved_date'] === $today && ($table->status !== 'AVAILABLE' || $table->currentSession()->exists())) return false;
                return true;
            })->sortBy('capacity');
            $table = $candidates->first();
            if (!$table) {
                throw ValidationException::withMessages([
                    !empty($data['table_id']) ? 'table_id' : 'guest_count' => ['Không còn bàn phù hợp với số khách, khu vực và thời gian đã chọn. Vui lòng chọn thời gian khác hoặc liên hệ nhà hàng.'],
                ]);
            }
            $reservation = new Reservation([
                'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'],
                'customer_id' => $data['customer_id'] ?? null,
                'guest_count' => $data['guest_count'], 'reserved_date' => $data['reserved_date'],
                'reserved_time' => $data['reserved_time'].':00', 'table_id' => $table->id,
                'note' => $data['note'] ?? null, 'status' => 'PENDING',
            ]);
            $reservation->request_key = $data['request_key'];
            $reservation->request_hash = $hash;
            $reservation->save();
            return $reservation;
        }, 3);
    }

    public function updateForCustomer(User $customer, int $reservationId, array $data): Reservation
    {
        return DB::transaction(function () use ($customer, $reservationId, $data): Reservation {
            $tables = Table::orderBy('id')->lockForUpdate()->get();
            $reservation = Reservation::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($reservationId);

            if ($reservation->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'reservation' => ['Chỉ có thể đổi lịch khi yêu cầu đang chờ xác nhận.'],
                ]);
            }

            $errors = ReservationSchedule::errors($data['reserved_date'], $data['reserved_time']);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $busy = Reservation::query()
                ->whereKeyNot($reservation->id)
                ->whereDate('reserved_date', $data['reserved_date'])
                ->where('reserved_time', $data['reserved_time'].':00')
                ->whereIn('status', self::HOLDING_STATUSES)
                ->pluck('table_id')
                ->all();
            $today = CarbonImmutable::now(config('reservations.timezone'))->toDateString();
            $table = $tables->filter(function (Table $table) use ($data, $busy, $today): bool {
                if (!empty($data['table_id']) && $table->id !== (int) $data['table_id']) return false;
                if (!empty($data['location']) && $table->location !== $data['location']) return false;
                if (!$table->is_active || $table->capacity < $data['guest_count'] || in_array($table->id, $busy, true)) return false;
                if ($data['reserved_date'] === $today && ($table->status !== 'AVAILABLE' || $table->currentSession()->exists())) return false;

                return true;
            })->sortBy('capacity')->first();

            if (!$table) {
                throw ValidationException::withMessages([
                    !empty($data['table_id']) ? 'table_id' : 'guest_count' => ['Không còn bàn phù hợp với số khách, khu vực và thời gian đã chọn.'],
                ]);
            }

            $reservation->fill([
                'guest_count' => $data['guest_count'],
                'reserved_date' => $data['reserved_date'],
                'reserved_time' => $data['reserved_time'].':00',
                'table_id' => $table->id,
                'note' => $data['note'] ?? null,
            ])->save();

            return $reservation->fresh('table');
        }, 3);
    }

    public function cancelForCustomer(User $customer, int $reservationId): Reservation
    {
        return DB::transaction(function () use ($customer, $reservationId): Reservation {
            $reservation = Reservation::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($reservationId);

            if ($reservation->status === 'CANCELLED') {
                return $reservation->load('table');
            }
            if (!in_array($reservation->status, ['PENDING', 'CONFIRMED'], true)) {
                throw ValidationException::withMessages([
                    'reservation' => ['Lịch đặt bàn này không còn có thể hủy trực tuyến.'],
                ]);
            }

            $reservation->update(['status' => 'CANCELLED']);

            return $reservation->fresh('table');
        }, 3);
    }
}
