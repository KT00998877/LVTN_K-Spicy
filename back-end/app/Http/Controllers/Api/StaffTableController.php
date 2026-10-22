<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StaffTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StaffTableController — API nghiệp vụ bàn + phiên + đặt bàn
 * dành cho phục vụ / quản lý.
 *
 * Endpoints (yêu cầu auth:sanctum + role WAITER/MANAGER):
 *  - GET    /api/staff/tables
 *  - PATCH  /api/staff/tables/{id}/status
 *  - PATCH  /api/staff/tables/{id}/request-payment
 *  - POST   /api/staff/table-sessions
 *  - PATCH  /api/staff/table-sessions/{id}/move
 *  - PATCH  /api/staff/table-sessions/{id}/close
 *  - PATCH  /api/staff/table-sessions/{id}/cancel
 *  - GET    /api/staff/reservations
 *  - PATCH  /api/staff/reservations/{id}/status
 */
class StaffTableController extends Controller
{
    public function __construct(private readonly StaffTableService $service)
    {
    }

    /**
     * GET /api/staff/tables
     * Trả về danh sách bàn + sessions đang mở + stats.
     */
    public function index(): JsonResponse
    {
        $data = $this->service->listTablesWithOpenSessions();
        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * PATCH /api/staff/tables/{id}/status
     * Body: {status: 'AVAILABLE'|'OCCUPIED'|'CLEANING'|'RESERVED'|'WAITING_PAYMENT'}
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);
        $updated = $this->service->updateTableStatus($id, $data['status']);
        return response()->json([
            'success' => true,
            'data'    => $updated,
        ]);
    }

    /**
     * PATCH /api/staff/tables/{id}/request-payment
     * Đánh dấu bàn sang WAITING_PAYMENT.
     */
    public function requestPayment(int $id): JsonResponse
    {
        $updated = $this->service->requestPayment($id);
        return response()->json([
            'success' => true,
            'data'    => $updated,
        ]);
    }

    /**
     * POST /api/staff/table-sessions
     * Body: {table_id, guest_count, customer_name?}
     */
    public function openSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'table_id'      => ['required', 'integer', 'exists:tables,id'],
            'guest_count'   => ['required', 'integer', 'min:1', 'max:50'],
            'customer_name' => ['nullable', 'string', 'max:120'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $session = $this->service->openSession(
            (int) $data['table_id'],
            (int) $data['guest_count'],
            $data['customer_name'] ?? null,
            $user
        );
        return response()->json([
            'success' => true,
            'data'    => $session,
        ], 201);
    }

    /**
     * PATCH /api/staff/table-sessions/{id}/move
     * Body: {new_table_id}
     */
    public function moveSession(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'new_table_id' => ['required', 'integer', 'exists:tables,id'],
        ]);
        $session = $this->service->moveSession($id, (int) $data['new_table_id']);
        return response()->json([
            'success' => true,
            'data'    => $session,
        ]);
    }

    /**
     * PATCH /api/staff/table-sessions/{id}/close
     */
    public function closeSession(int $id): JsonResponse
    {
        $session = $this->service->closeSession($id);
        return response()->json([
            'success' => true,
            'data'    => $session,
        ]);
    }

    /**
     * PATCH /api/staff/table-sessions/{id}/cancel
     * Chỉ cho phép khi phiên chưa có order_items.
     */
    public function cancelSession(int $id): JsonResponse
    {
        $session = $this->service->cancelSession($id);
        return response()->json([
            'success' => true,
            'data'    => $session,
        ]);
    }

    /**
     * GET /api/staff/reservations?date=YYYY-MM-DD
     */
    public function listReservations(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $rows = $this->service->listReservations($date);
        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }

    /**
     * PATCH /api/staff/reservations/{id}/status
     */
    public function updateReservationStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);
        $row = $this->service->updateReservationStatus($id, $data['status']);
        return response()->json([
            'success' => true,
            'data'    => $row,
        ]);
    }
}
