<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\StaffOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StaffOrderController — API đơn hàng + order_items cho phục vụ / bếp / quản lý.
 *
 * Endpoints (yêu cầu auth:sanctum + role WAITER/KITCHEN/MANAGER):
 *  - GET    /api/staff/orders?status=
 *  - POST   /api/staff/orders               (tạo order từ table_session)
 *  - GET    /api/staff/orders/{id}
 *  - POST   /api/staff/orders/{id}/items    (thêm món)
 *  - PATCH  /api/staff/orders/{oid}/items/{iid}    (sửa qty/note/spicy)
 *  - PATCH  /api/staff/orders/{oid}/items/{iid}/status  (chuyển trạng thái)
 */
class StaffOrderController extends Controller
{
    public function requestInvoice(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $order = \App\Models\Order::lockForUpdate()->findOrFail($id);
            if ($order->status === 'CANCELLED' || !$order->table_session_id || !$order->items()->where('status', '!=', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['order' => ['Đơn chưa có món hoặc đã hủy, không thể yêu cầu xuất hóa đơn.']]);
            }
            if (!$order->invoice_requested_at) {
                $order->invoice_requested_at = now();
                $order->save();
            }
            return response()->json(['success' => true, 'data' => $this->service->getOrderDetail($id)]);
        });
    }

    public function submitItems(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.spicy_level' => ['sometimes', 'integer', 'min:0', 'max:7'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
        ]);
        $order = DB::transaction(function () use ($id, $data) {
            foreach ($data['items'] as $item) {
                $this->service->addItem($id, $item);
            }
            return $this->service->getOrderDetail($id);
        });
        return response()->json(['success' => true, 'data' => $order]);
    }

    /** Temporary successful payment flow, without a payment gateway. */
    public function demoPay(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $order = \App\Models\Order::lockForUpdate()->findOrFail($id);
            if ($order->status === 'COMPLETED') {
                return response()->json(['success' => true, 'data' => $this->service->getOrderDetail($id)]);
            }
            if ($order->status === 'CANCELLED' || !$order->table_session_id || !$order->items()->where('status', '!=', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['order' => ['Đơn không đủ điều kiện thanh toán.']]);
            }
            $session = \App\Models\TableSession::lockForUpdate()->findOrFail($order->table_session_id);
            if ($session->status !== 'OPEN') {
                throw ValidationException::withMessages(['order' => ['Phiên bàn đã đóng.']]);
            }
            if (DB::table('invoices')->where('order_id', $id)->exists()) {
                throw ValidationException::withMessages(['order' => ['Đơn đã có hóa đơn, vui lòng xử lý tại thu ngân.']]);
            }
            $now = now();
            $invoiceId = DB::table('invoices')->insertGetId([
                'code' => 'HD-' . $now->format('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'order_id' => $id,
                'waiter_id' => $request->user()->id,
                'subtotal' => $order->subtotal, 'discount_amount' => $order->discount_amount,
                'total_amount' => $order->total_amount, 'paid_amount' => $order->total_amount,
                'status' => 'PAID', 'issued_at' => $now, 'paid_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payments')->insert([
                'invoice_id' => $invoiceId, 'method' => 'OTHER', 'amount' => $order->total_amount,
                'status' => 'SUCCESS', 'processed_by' => $request->user()->id,
                'processed_at' => $now, 'note' => 'Thanh toán mô phỏng, chưa tích hợp phương thức thanh toán.',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $order->update(['status' => 'COMPLETED', 'completed_at' => $now]);
            $session->update(['status' => 'CLOSED', 'closed_at' => $now]);
            $session->table()->update(['status' => 'AVAILABLE']);
            return response()->json(['success' => true, 'data' => $this->service->getOrderDetail($id)]);
        });
    }

    public function __construct(private readonly StaffOrderService $service)
    {
    }

    /**
     * GET /api/staff/orders
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $orders = $this->service->listOrders($status);
        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * GET /api/staff/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $order = $this->service->getOrderDetail($id);
        return response()->json([
            'success' => true,
            'data'    => $order,
        ]);
    }

    /**
     * POST /api/staff/orders
     * Body: {table_session_id}
     * Tạo order gắn với phiên đang mở (nếu phiên đã có order active thì trả về luôn).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'table_session_id' => ['required', 'integer', 'exists:table_sessions,id'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $order = $this->service->createOrderForSession((int) $data['table_session_id'], $user);
        return response()->json([
            'success' => true,
            'data'    => $order,
        ], 201);
    }

    /**
     * POST /api/staff/orders/{id}/items
     */
    public function addItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'quantity'     => ['sometimes', 'integer', 'min:1', 'max:99'],
            'spicy_level'  => ['sometimes', 'integer', 'min:0', 'max:7'],
            'note'         => ['nullable', 'string', 'max:200'],
            'topping_ids'  => ['sometimes', 'array'],
            'topping_ids.*'=> ['integer', 'exists:menu_items,id'],
        ]);
        $order = $this->service->addItem($id, $data);
        return response()->json([
            'success' => true,
            'data'    => $order,
        ], 201);
    }

    /**
     * PATCH /api/staff/orders/{oid}/items/{iid}
     */
    public function updateItem(Request $request, int $oid, int $iid): JsonResponse
    {
        $data = $request->validate([
            'quantity'     => ['sometimes', 'integer', 'min:1', 'max:99'],
            'spicy_level'  => ['sometimes', 'integer', 'min:0', 'max:7'],
            'note'         => ['nullable', 'string', 'max:200'],
            'topping_ids'  => ['sometimes', 'array'],
            'topping_ids.*'=> ['integer', 'exists:menu_items,id'],
        ]);
        $order = $this->service->updateItem($oid, $iid, $data);
        return response()->json([
            'success' => true,
            'data'    => $order,
        ]);
    }

    /**
     * PATCH /api/staff/orders/{oid}/items/{iid}/status
     */
    public function updateItemStatus(Request $request, int $oid, int $iid): JsonResponse
    {
        $canManage = $request->user()->roles()->where('code', 'MANAGER')->exists();
        $data = $request->validate([
            'status' => ['required', 'string', $canManage ? 'in:PENDING,PREPARING,READY,SERVED,CANCELLED' : 'in:SERVED,CANCELLED'],
            'reason' => ['nullable', 'string', 'max:500'],
            'allow_shortage' => ['sometimes', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $result = $this->service->updateItemStatus(
            $oid,
            $iid,
            $data['status'],
            $data['reason'] ?? null,
            $user,
            (bool) ($data['allow_shortage'] ?? false)
        );
        return response()->json([
            'success'      => true,
            'data'         => $result['order'],
            'shortages'    => $result['shortages'],
            'request_code' => $result['request_code'],
        ]);
    }

    /**
     * PATCH /api/staff/orders/{id}/status
     * Cập nhật trạng thái tổng của đơn hàng (WAITER/CASHIER/MANAGER).
     *
     * State machine cho order (DINE_IN):
     *   PENDING ↔ CONFIRMED ↔ PREPARING ↔ READY ↔ SERVED ↔ COMPLETED
     *   PENDING/CONFIRMED/PREPARING → CANCELLED (chỉ khi chưa có hóa đơn)
     *
     * Với ONLINE/TAKEAWAY: đơn đã có flow confirm/handover/delivered riêng
     * → endpoint này chỉ hỗ trợ CONFIRMED → PREPARING → READY → CANCELLED.
     *
     * Lý do cập nhật là bắt buộc (khi CANCELLED), khuyến nghị với các
     * trạng thái khác để audit.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $canManage = $request->user()->roles()->where('code', 'MANAGER')->exists();
        $data = $request->validate([
            'status' => ['required', 'string', 'in:PENDING,CONFIRMED,PREPARING,READY,SERVED,COMPLETED,CANCELLED'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        return DB::transaction(function () use ($data, $id, $request, $canManage) {
            /** @var Order|null $order */
            $order = Order::lockForUpdate()->find($id);
            if (!$order) {
                throw ValidationException::withMessages(['order_id' => ['Không tìm thấy đơn hàng.']]);
            }

            // Không cho cancel thủ công đơn đã lập hóa đơn (tránh xung đột tiền)
            if ($data['status'] === 'CANCELLED') {
                abort_if(DB::table('invoices')->where('order_id', $id)->exists(), 409,
                    'Đơn đã lập hóa đơn, không thể huỷ thủ công. Vui lòng xử lý tại thu ngân.');
            }
            // COMPLETED chỉ được set qua thanh toán
            if ($data['status'] === 'COMPLETED' && !in_array($order->status, ['SERVED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Đơn cần SERVED trước khi COMPLETED. Hoàn tất qua thanh toán.'],
                ]);
            }

            $oldStatus = $order->status;
            $newStatus = $data['status'];

            // ONLINE/TAKEAWAY: giới hạn state machine (luồng shipper)
            if (in_array($order->order_type, ['ONLINE', 'TAKEAWAY'], true)) {
                $allowed = ['CONFIRMED' => ['PREPARING', 'CANCELLED'], 'PREPARING' => ['READY', 'CANCELLED']];
                $next = $allowed[$oldStatus] ?? [];
                if (!in_array($newStatus, $next, true)) {
                    throw ValidationException::withMessages([
                        'status' => ["Đơn {$order->order_type} đang '{$oldStatus}'. Hãy dùng luồng confirm/handover cho các bước tiếp theo."],
                    ]);
                }
            } else {
                // DINE_IN
                $allowed = [
                    'PENDING'    => ['PENDING', 'CONFIRMED', 'CANCELLED'],
                    'CONFIRMED'  => ['PENDING', 'PREPARING', 'CANCELLED'],
                    'PREPARING'  => ['CONFIRMED', 'READY', 'CANCELLED'],
                    'READY'      => ['PREPARING', 'SERVED', 'CANCELLED'],
                    'SERVED'     => ['READY', 'COMPLETED'],
                    'COMPLETED'  => ['COMPLETED'],
                    'CANCELLED'  => ['CANCELLED'],
                ];
                if (!in_array($newStatus, $allowed[$oldStatus] ?? [], true)) {
                    throw ValidationException::withMessages([
                        'status' => ["Không thể chuyển đơn từ '{$oldStatus}' sang '{$newStatus}'."],
                    ]);
                }
            }

            // Cập nhật món: các món PENDING có thể cần theo trạng thái đơn
            $now = now();

            $order->status = $newStatus;
            if ($newStatus === 'PREPARING') {
                OrderItem::where('order_id', $id)
                    ->where('status', 'PENDING')
                    ->update(['status' => 'PREPARING', 'started_at' => $now]);
            } elseif ($newStatus === 'READY') {
                OrderItem::where('order_id', $id)
                    ->whereIn('status', ['PENDING', 'PREPARING'])
                    ->update(['status' => 'READY', 'ready_at' => $now]);
            } elseif ($newStatus === 'SERVED') {
                OrderItem::where('order_id', $id)
                    ->whereIn('status', ['PENDING', 'PREPARING', 'READY'])
                    ->update(['status' => 'SERVED', 'served_at' => $now]);
            } elseif ($newStatus === 'CANCELLED') {
                OrderItem::where('order_id', $id)
                    ->where('status', '!=', 'CANCELLED')
                    ->update([
                        'status' => 'CANCELLED',
                        'cancelled_by' => $request->user()->id,
                        'cancellation_reason' => '[Quản lý huỷ] ' . $data['reason'],
                    ]);
                // Tính lại tổng tiền sau khi huỷ món
                $subtotal = (float) OrderItem::where('order_id', $id)
                    ->where('status', '!=', 'CANCELLED')->sum('subtotal');
                $order->subtotal = $subtotal;
                $order->total_amount = max(0, $subtotal - (float) $order->discount_amount);
                $order->cancelled_by_user_id = $request->user()->id;
                $order->cancelled_at = $now;
                $order->cancellation_reason = $data['reason'];
            } elseif ($newStatus === 'COMPLETED') {
                $order->completed_at = $now;
            }

            $order->save();

            // Ghi order_events
            DB::table('order_events')->insert([
                'order_id'   => $id,
                'actor_id'   => $request->user()->id,
                'event'      => 'STATUS_' . $newStatus,
                'status'     => $newStatus,
                'note'       => '[Quản lý] ' . $data['reason'],
                'created_at' => $now,
            ]);

            $detail = $this->service->getOrderDetail($id);
            return response()->json(['success' => true, 'data' => $detail]);
        });
    }
}
