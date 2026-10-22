<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cho khách hàng đã đăng nhập xem lịch sử & chi tiết đơn hàng của mình.
 *
 * - GET /api/orders/me        : danh sách đơn của customer hiện tại
 * - GET /api/orders/me/{id}   : chi tiết 1 đơn (đảm bảo thuộc về customer)
 */
class OrderController extends Controller
{
    /**
     * GET /api/orders/me
     * Query: ?status=PENDING&page=1&per_page=10
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $request->validate([
            'status'   => ['nullable', 'string', 'in:PENDING,CONFIRMED,PREPARING,READY,SERVED,DELIVERING,DELIVERED,COMPLETED,CANCELLED'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Order::query()
            ->where('customer_id', $user->id)
            ->orderByDesc('placed_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 10);
        $orders  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $orders->getCollection()->map(fn (Order $o) => $this->formatOrderList($o))->values(),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * GET /api/orders/me/{id}
     * Trả về chi tiết đơn + danh sách món. Chỉ trả về nếu đơn thuộc về customer.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $order = Order::query()
            ->with(['items.toppings.topping'])
            ->where('customer_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn hàng hoặc bạn không có quyền truy cập.',
            ], 404);
        }

        // Recover a lost browser return when the customer reopens an unpaid order.
        if ($order->payment_method === 'PREPAID') {
            $payment = \App\Models\Payment::where('provider', 'vnpay')->where('status', 'PENDING')
                ->whereIn('invoice_id', \Illuminate\Support\Facades\DB::table('invoices')->where('order_id', $order->id)->select('id'))
                ->latest('id')->first();
            if ($payment) {
                app(\App\Services\Payment\VNPayService::class)->reconcile($payment);
                $order->refresh();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrderDetail($order),
        ]);
    }

    /**
     * POST /api/orders/me/{id}/confirm-receipt
     * Khách hàng xác nhận đã nhận được hàng → COMPLETED + tích điểm (nếu áp dụng).
     * Delegate xuống OnlineOrderService để đảm bảo luồng thống nhất giữa staff/customer.
     */
    public function confirmReceipt(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        app(\App\Services\OnlineOrderService::class)->customerConfirmReceipt($user, $id);

        $order = Order::query()
            ->with(['items.toppings.topping'])
            ->where('customer_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrderDetail($order),
            'loyalty_points' => (int) $user->fresh()->loyalty_points,
        ]);
    }

    /* -----------------------------------------------------------------
     | Format helpers
     |-----------------------------------------------------------------*/

    /**
     * Format rút gọn cho danh sách (list view).
     */
    private function formatOrderList(Order $order): array
    {
        return [
            ...app(\App\Services\OnlineOrderService::class)->presentation($order),
            'id'            => $order->id,
            'code'          => $order->code,
            'order_type'    => $order->order_type,
            'status'        => $order->status,
            'total_amount'  => (float) $order->total_amount,
            'subtotal'      => (float) $order->subtotal,
            'discount'      => (float) $order->discount_amount,
            'item_count'    => (int) $order->items()->sum('quantity'),
            'placed_at'     => $order->placed_at?->toIso8601String(),
            'completed_at'  => $order->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Format đầy đủ cho chi tiết (kèm items).
     */
    public function formatOrderDetail(Order $order): array
    {
        return [
            ...app(\App\Services\OnlineOrderService::class)->presentation($order),
            'id'             => $order->id,
            'code'           => $order->code,
            'order_type'     => $order->order_type,
            'status'         => $order->status,
            'subtotal'       => (float) $order->subtotal,
            'discount'       => (float) $order->discount_amount,
            'total_amount'   => (float) $order->total_amount,
            'note'           => $order->note,
            'placed_at'      => $order->placed_at?->toIso8601String(),
            'completed_at'   => $order->completed_at?->toIso8601String(),
            'cancelled_at'   => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'table_session_id' => $order->table_session_id,
            'waiter_id'      => $order->waiter_id,
            'items'          => $order->items->map(fn (OrderItem $i) => [
                'id'              => $i->id,
                'menu_item_id'    => $i->menu_item_id,
                'name'            => $i->item_name,
                'unit_price'      => (float) $i->unit_price,
                'quantity'        => (int) $i->quantity,
                'subtotal'        => (float) $i->subtotal,
                'note'            => $i->note,
                'spicy_level'     => $i->effective_spicy_level,
                'status'          => $i->status,
                'started_at'      => $i->started_at?->toIso8601String(),
                'ready_at'        => $i->ready_at?->toIso8601String(),
                'served_at'       => $i->served_at?->toIso8601String(),
                'cancellation_reason' => $i->cancellation_reason,
                'toppings'        => $i->toppings->map(fn ($t) => [
                    'id'        => (int) $t->id,
                    'name'      => $t->topping?->name,
                    'unit_price'=> (float) $t->unit_price,
                    'quantity'  => (int) $t->quantity,
                    'subtotal'  => (float) $t->subtotal,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Public formatter dùng cho cả OrderController::show() và
     * các API liên quan (vd customerCancel).
     */
    public function formatOrderDetailPublic(Order $order): array
    {
        return $this->formatOrderDetail($order);
    }
}
