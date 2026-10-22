<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StaffTableService — nghiệp vụ bàn + phiên phục vụ + đặt bàn trước
 * (dùng cho phục vụ / quản lý).
 *
 * Quy tắc BR:
 *  - BR-BAN-01: chỉ mở phiên khi bàn AVAILABLE.
 *  - BR-BAN-02: đóng phiên → bàn sang AVAILABLE.
 *  - BR-BAN-03: bàn OCCUPIED → WAITING_PAYMENT khi khách yêu cầu thanh toán.
 *  - BR-BAN-04: chuyển sang bàn AVAILABLE đủ chỗ; bàn cũ trở về AVAILABLE.
 *  - BR-BAN-05: thanh toán xong tự động đóng phiên và trả bàn trống.
 */
class StaffTableService
{
    /** Mảng status hợp lệ cho PATCH /tables/{id}/status (phục vụ set trực tiếp). */
    public const TABLE_STATUS_TRANSITIONS = [
        // from => [allowed to]
        'AVAILABLE'        => ['OCCUPIED', 'CLEANING', 'RESERVED'],
        'OCCUPIED'         => ['WAITING_PAYMENT', 'CLEANING', 'AVAILABLE'],
        'RESERVED'         => ['AVAILABLE', 'OCCUPIED'],
        'WAITING_PAYMENT'  => ['CLEANING', 'OCCUPIED'],
        'CLEANING'         => ['AVAILABLE'],
    ];

    /**
     * Lấy danh sách bàn kèm phiên đang mở + summary.
     *
     * @return array{
     *   tables: array<int, array<string, mixed>>,
     *   sessions: array<int, array<string, mixed>>,
     *   stats: array<string, int>
     * }
     */
    public function listTablesWithOpenSessions(): array
    {
        $tables = Table::where('is_active', true)
            ->orderBy('code')
            ->get();

        $sessions = TableSession::with('order')
            ->where('status', 'OPEN')
            ->get();

        // Phiên còn mở là nguồn xác định bàn đang phục vụ, kể cả dữ liệu cũ bị lệch.
        $openTableIds = $sessions->pluck('table_id');
        foreach ($tables as $table) {
            if ($openTableIds->contains($table->id) && $table->status !== 'WAITING_PAYMENT') {
                $table->status = 'OCCUPIED';
            }
        }

        $tablesData = $tables->map(fn (Table $t) => $this->formatTable($t))->values()->all();
        $sessionsData = $sessions->map(fn (TableSession $s) => $this->formatSession($s))->values()->all();

        $byStatus = [
            'AVAILABLE'       => 0,
            'OCCUPIED'        => 0,
            'RESERVED'        => 0,
            'WAITING_PAYMENT' => 0,
            'CLEANING'        => 0,
        ];
        foreach ($tables as $t) {
            $byStatus[$t->status] = ($byStatus[$t->status] ?? 0) + 1;
        }

        return [
            'tables'   => $tablesData,
            'sessions' => $sessionsData,
            'stats'    => [
                'total'           => $tables->count(),
                'available'       => $byStatus['AVAILABLE'],
                'occupied'        => $byStatus['OCCUPIED'],
                'reserved'        => $byStatus['RESERVED'],
                'waiting_payment' => $byStatus['WAITING_PAYMENT'],
                'cleaning'        => $byStatus['CLEANING'],
            ],
        ];
    }

    /**
     * Validate + đổi trạng thái bàn trực tiếp (phục vụ set AVAILABLE / OCCUPIED / CLEANING).
     */
    public function updateTableStatus(int $tableId, string $newStatus): array
    {
        return DB::transaction(function () use ($tableId, $newStatus) {
        $allowed = ['AVAILABLE', 'OCCUPIED', 'RESERVED', 'WAITING_PAYMENT'];
        if (!in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['Trạng thái không hợp lệ.'],
            ]);
        }

        $table = Table::lockForUpdate()->find($tableId);
        if (!$table) {
            throw ValidationException::withMessages([
                'table_id' => ['Không tìm thấy bàn.'],
            ]);
        }

        $hasOpenSession = TableSession::where('table_id', $tableId)->where('status', 'OPEN')->exists();
        if ($hasOpenSession && !in_array($newStatus, ['OCCUPIED', 'WAITING_PAYMENT'], true)) {
            throw ValidationException::withMessages(['status' => ['Bàn còn phiên phục vụ. Vui lòng thanh toán hoặc hủy phiên trước.']]);
        }
        if (!$hasOpenSession && in_array($newStatus, ['OCCUPIED', 'WAITING_PAYMENT'], true)) {
            throw ValidationException::withMessages(['status' => ['Cần mở phiên phục vụ trước.']]);
        }
        if ($hasOpenSession && $table->status !== 'WAITING_PAYMENT') $table->status = 'OCCUPIED';
        $allowedFrom = self::TABLE_STATUS_TRANSITIONS[$table->status] ?? [];
        if (!in_array($newStatus, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => ["Không thể chuyển bàn từ '{$table->status}' sang '{$newStatus}'."],
            ]);
        }

        $table->status = $newStatus;
        $table->save();

        return $this->formatTable($table->fresh());
        });
    }

    /**
     * Mở phiên phục vụ cho 1 bàn.
     * Validate: bàn phải AVAILABLE, guest_count > 0 và <= capacity.
     */
    public function openSession(int $tableId, int $guestCount, ?string $customerName, User $waiter): array
    {
        return DB::transaction(function () use ($tableId, $guestCount, $customerName, $waiter) {
            /** @var Table|null $table */
            $table = Table::lockForUpdate()->find($tableId);
            if (!$table) {
                throw ValidationException::withMessages([
                    'table_id' => ['Không tìm thấy bàn.'],
                ]);
            }
            if (!$table->is_active || TableSession::where('table_id', $tableId)->where('status', 'OPEN')->exists() || $table->status !== 'AVAILABLE') {
                throw ValidationException::withMessages([
                    'table_id' => ["Bàn đang ở trạng thái '{$table->status}', không thể mở phiên."],
                ]);
            }
            if ($guestCount < 1 || $guestCount > $table->capacity) {
                throw ValidationException::withMessages([
                    'guest_count' => ["Số khách phải từ 1 đến {$table->capacity}."],
                ]);
            }

            /** @var TableSession $session */
            $session = TableSession::create([
                'table_id'    => $table->id,
                'waiter_id'   => $waiter->id,
                'customer_id' => null,
                'guest_count' => $guestCount,
                'opened_at'   => now(),
                'status'      => 'OPEN',
            ]);

            $table->status = 'OCCUPIED';
            $table->save();

            app(StaffOrderService::class)->createOrderForSession($session->id, $waiter);
            return $this->formatSession($session->fresh()->load('order'));
        });
    }

    /**
     * Đóng phiên phục vụ (sau khi thanh toán xong, hoặc hủy).
     * - Bàn → AVAILABLE
     * - session → CLOSED
     */
    public function closeSession(int $sessionId): array
    {
        return DB::transaction(function () use ($sessionId) {
            /** @var TableSession|null $session */
            $session = TableSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw ValidationException::withMessages([
                    'session_id' => ['Không tìm thấy phiên phục vụ.'],
                ]);
            }
            if ($session->status !== 'OPEN') {
                throw ValidationException::withMessages([
                    'session_id' => ["Phiên đã ở trạng thái '{$session->status}'."],
                ]);
            }

            if (Order::where('table_session_id', $session->id)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->exists()) {
                throw ValidationException::withMessages(['session_id' => ['Đơn chưa hoàn tất. Vui lòng thanh toán trước khi đóng phiên.']]);
            }
            $session->status = 'CLOSED';
            $session->closed_at = now();
            $session->save();

            /** @var Table $table */
            $table = Table::find($session->table_id);
            if ($table) {
                $table->status = 'AVAILABLE';
                $table->save();
            }

            return $this->formatSession($session->fresh()->load('order'));
        });
    }

    /**
     * Hủy phiên phục vụ (chỉ cho phép khi chưa có order_items).
     */
    public function cancelSession(int $sessionId): array
    {
        return DB::transaction(function () use ($sessionId) {
            /** @var TableSession|null $session */
            $session = TableSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw ValidationException::withMessages([
                    'session_id' => ['Không tìm thấy phiên phục vụ.'],
                ]);
            }
            if ($session->status !== 'OPEN') {
                throw ValidationException::withMessages([
                    'session_id' => ["Phiên không ở trạng thái OPEN."],
                ]);
            }

            // Kiểm tra đã có món chưa
            $orderItemsCount = Order::where('table_session_id', $session->id)
                ->where('status', '!=', 'CANCELLED')
                ->withCount('items')
                ->get()
                ->sum('items_count');
            if ((int) $orderItemsCount > 0) {
                throw ValidationException::withMessages([
                    'session_id' => ['Phiên đã có món — không thể hủy. Vui lòng xử lý đơn trước.'],
                ]);
            }

            Order::where('table_session_id', $session->id)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->update(['status' => 'CANCELLED']);
            $session->status = 'CANCELLED';
            $session->closed_at = now();
            $session->save();

            $table = Table::find($session->table_id);
            if ($table) {
                $table->status = 'AVAILABLE';
                $table->save();
            }

            return $this->formatSession($session->fresh()->load('order'));
        });
    }

    /**
     * Chuyển phiên sang bàn mới.
     * Validate: bàn mới AVAILABLE, đang hoạt động, không có phiên mở, đủ chỗ.
     */
    public function moveSession(int $sessionId, int $newTableId): array
    {
        return DB::transaction(function () use ($sessionId, $newTableId) {
            /** @var TableSession|null $session */
            $session = TableSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw ValidationException::withMessages([
                    'session_id' => ['Không tìm thấy phiên phục vụ.'],
                ]);
            }
            if ($session->status !== 'OPEN') {
                throw ValidationException::withMessages([
                    'session_id' => ["Phiên không ở trạng thái OPEN."],
                ]);
            }

            /** @var Table|null $newTable */
            $newTable = Table::lockForUpdate()->find($newTableId);
            if (!$newTable) {
                throw ValidationException::withMessages([
                    'new_table_id' => ['Không tìm thấy bàn mới.'],
                ]);
            }
            if ($session->table_id === $newTable->id) {
                throw ValidationException::withMessages([
                    'new_table_id' => ['Bàn mới phải khác bàn hiện tại.'],
                ]);
            }
            if (!$newTable->is_active || $newTable->status !== 'AVAILABLE' || TableSession::where('table_id', $newTable->id)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages([
                    'new_table_id' => ["Bàn '{$newTable->code}' đang ở trạng thái '{$newTable->status}'."],
                ]);
            }
            if ($newTable->capacity < $session->guest_count) {
                throw ValidationException::withMessages([
                    'new_table_id' => ["Bàn '{$newTable->code}' chỉ chứa {$newTable->capacity} người — không đủ cho {$session->guest_count} khách."],
                ]);
            }

            $oldTable = Table::lockForUpdate()->find($session->table_id);

            // Chuyển session sang bàn mới
            $session->table_id = $newTable->id;
            $session->save();

            // Đơn giữ nguyên phiên; mã bàn được đọc qua quan hệ tableSession.table.
            $newTable->status = $oldTable?->status === 'WAITING_PAYMENT' ? 'WAITING_PAYMENT' : 'OCCUPIED';
            $newTable->save();

            if ($oldTable) {
                $oldTable->status = 'AVAILABLE';
                $oldTable->save();
            }

            return $this->formatSession($session->fresh()->load('order'));
        });
    }

    /**
     * Đánh dấu bàn sang WAITING_PAYMENT (gọi từ phục vụ khi khách yêu cầu thanh toán).
     */
    public function requestPayment(int $tableId): array
    {
        return $this->updateTableStatus($tableId, 'WAITING_PAYMENT');
    }

    /**
     * Phục vụ đánh dấu bàn đã dọn xong → AVAILABLE.
     */
    public function markAvailable(int $tableId): array
    {
        return $this->updateTableStatus($tableId, 'AVAILABLE');
    }

    /**
     * Lấy danh sách reservations (đặt bàn trước).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listReservations(?string $date = null): array
    {
        $query = Reservation::with('table');
        if ($date) {
            $query->whereDate('reserved_date', $date);
        }
        return $query->orderBy('reserved_date')
            ->orderBy('reserved_time')
            ->get()
            ->map(fn (Reservation $r) => $this->formatReservation($r))
            ->values()
            ->all();
    }

    /**
     * Cập nhật trạng thái reservation.
     */
    public function updateReservationStatus(int $id, string $status): array
    {
        $allowed = ['PENDING', 'CONFIRMED', 'ARRIVED', 'CANCELLED', 'COMPLETED'];
        if (!in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['Trạng thái reservation không hợp lệ.'],
            ]);
        }
        return DB::transaction(function () use ($id, $status) {
            // Same lock order as allocation; cancelled/completed bookings cannot reclaim a table.
            Table::orderBy('id')->lockForUpdate()->get();
            $r = Reservation::lockForUpdate()->find($id);
            if (!$r) {
                throw ValidationException::withMessages(['id' => ['Không tìm thấy đặt bàn.']]);
            }
            $transitions = [
                'PENDING' => ['CONFIRMED', 'CANCELLED'],
                'CONFIRMED' => ['ARRIVED', 'CANCELLED'],
                'ARRIVED' => ['COMPLETED'],
                'CANCELLED' => [], 'COMPLETED' => [],
            ];
            if ($r->status !== $status && !in_array($status, $transitions[$r->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['Không thể chuyển trạng thái đặt bàn này.']]);
            }
            if ($r->status !== $status && $status === 'CONFIRMED') {
                $at = \Carbon\CarbonImmutable::parse($r->reserved_date->format('Y-m-d').' '.$r->reserved_time, config('reservations.timezone'));
                if ($at->lessThanOrEqualTo(\Carbon\CarbonImmutable::now(config('reservations.timezone')))) {
                    throw ValidationException::withMessages(['status' => ['Không thể xác nhận đặt bàn đã quá giờ.']]);
                }
                $table = $r->table;
                $conflict = Reservation::where('id', '!=', $r->id)->where('table_id', $r->table_id)
                    ->whereDate('reserved_date', $r->reserved_date->format('Y-m-d'))->where('reserved_time', $r->reserved_time)
                    ->whereIn('status', ReservationService::HOLDING_STATUSES)->exists();
                $busyToday = $table && $r->reserved_date->format('Y-m-d') === \Carbon\CarbonImmutable::now(config('reservations.timezone'))->toDateString()
                    && ($table->status !== 'AVAILABLE' || $table->currentSession()->exists());
                if (!$table || !$table->is_active || $table->capacity < $r->guest_count || $conflict || $busyToday) {
                    throw ValidationException::withMessages(['status' => ['Bàn không còn phù hợp hoặc đã có đặt bàn trùng giờ.']]);
                }
            }
            $r->status = $status;
            $r->save();
            return $this->formatReservation($r->fresh()->load('table'));
        }, 3);
    }

    /* ====== Format helpers (chuẩn hoá response cho FE) ====== */

    public function formatTable(Table $t): array
    {
        return [
            'id'        => (int) $t->id,
            'code'      => $t->code,
            'name'      => $t->name,
            'capacity'  => (int) $t->capacity,
            'location'  => $t->location,
            'status'    => $t->status,
            'is_active' => (bool) $t->is_active,
        ];
    }

    public function formatSession(TableSession $s): array
    {
        $s->loadMissing('table', 'waiter');

        // Tính lại items_count + subtotal từ orders để phản ánh realtime.
        $orderIds = Order::where('table_session_id', $s->id)
            ->where('status', '!=', 'CANCELLED')
            ->pluck('id');
        $itemsCount = 0;
        $subtotal = 0.0;
        if ($orderIds->isNotEmpty()) {
            $itemsCount = (int) \DB::table('order_items')
                ->whereIn('order_id', $orderIds)
                ->where('status', '!=', 'CANCELLED')
                ->count();
            $subtotal = (float) \DB::table('order_items')
                ->whereIn('order_id', $orderIds)
                ->where('status', '!=', 'CANCELLED')
                ->sum('subtotal');
        }

        $order = $s->order;

        return [
            'id'            => (int) $s->id,
            'table_id'      => (int) $s->table_id,
            'table_code'    => $s->table?->code,
            'table_name'    => $s->table?->name,
            'waiter_id'     => $s->waiter_id ? (int) $s->waiter_id : null,
            'waiter_name'   => $s->waiter?->name,
            'customer_id'   => $s->customer_id ? (int) $s->customer_id : null,
            'customer_name' => null,
            'guest_count'   => (int) $s->guest_count,
            'opened_at'     => $s->opened_at?->toIso8601String(),
            'closed_at'     => $s->closed_at?->toIso8601String(),
            'status'        => $s->status,
            'items_count'   => $itemsCount,
            'subtotal'      => round($subtotal, 2),
            'order_id'      => $order?->id ? (int) $order->id : null,
        ];
    }

    public function formatReservation(Reservation $r): array
    {
        return [
            'id'              => (int) $r->id,
            'customer_name'   => $r->customer_name,
            'customer_phone'  => $r->customer_phone,
            'customer_id'     => $r->customer_id ? (int) $r->customer_id : null,
            'table_id'        => $r->table_id ? (int) $r->table_id : null,
            'table_code'      => $r->table?->code,
            'guest_count'     => (int) $r->guest_count,
            'reserved_date'   => $r->reserved_date?->format('Y-m-d'),
            'reserved_time'   => substr((string) $r->reserved_time, 0, 5),
            'note'            => $r->note,
            'status'          => $r->status,
            'handled_by'      => $r->handled_by ? (int) $r->handled_by : null,
            'handled_by_name' => $r->handledBy?->name,
            'created_at'      => $r->created_at?->toIso8601String(),
        ];
    }
}
