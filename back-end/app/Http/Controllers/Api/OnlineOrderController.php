<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Order, User};
use App\Services\{OnlineOrderService, StaffOrderService, RoutingService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnlineOrderController extends Controller
{
    public function __construct(private OnlineOrderService $service) {}

    /**
     * POST /api/staff/online-orders/{id}/pay
     * Thanh toán mô phỏng (PREPAID).
     */
    public function pay(Request $request, int $id)
    {
        $this->service->simulatePayment($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * GET /api/staff/online-orders
     * Danh sách đơn giao hàng + mang đi (manager/cashier).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status'    => ['nullable', 'in:PENDING,CONFIRMED,PREPARING,READY,READY_NOTIFY_SHIPPER,DELIVERING,DELIVERED,COMPLETED,CANCELLED'],
            'order_type'=> ['nullable', 'in:ONLINE,TAKEAWAY'],
        ]);
        $query = Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
            ->with(['items.toppings.topping', 'customer', 'cancellationRequest']);
        if (!empty($data['order_type'])) $query->where('order_type', $data['order_type']);
        if (!empty($data['status']))    $query->where('status', $data['status']);
        return response()->json([
            'success' => true,
            'data'    => $query->orderByDesc('placed_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($o) => app(StaffOrderService::class)->formatOrderDetail($o)),
        ]);
    }

    /**
     * GET /api/staff/online-orders/shippers
     * Danh sách shipper đang hoạt động.
     */
    public function shippers()
    {
        return response()->json([
            'success' => true,
            'data'    => User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('code', 'SHIPPER'))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * POST /api/staff/online-orders/{id}/confirm
     * Xác nhận đơn (manager/cashier).
     */
    public function confirm(Request $request, int $id)
    {
        $data = $request->validate(['shipper_id' => ['nullable', 'integer', 'exists:users,id']]);
        $this->service->confirm($request->user(), $id, $data['shipper_id'] ?? null);
        return $this->detail($id);
    }

    /**
     * POST /api/staff/online-orders/{id}/reject
     * Từ chối đơn (manager/cashier).
     */
    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reject($request->user(), $id, $data['reason']);
        return $this->detail($id);
    }

    public function approveCancellation(Request $request, int $id)
    {
        $this->service->approveCancellation($request->user(), $id);
        return $this->detail($id);
    }

    public function confirmRefund(Request $request, int $id)
    {
        $this->service->confirmSandboxRefund($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * POST /api/staff/online-orders/{id}/handover
     * Bàn giao cho shipper (manager/cashier).
     * Chỉ thực hiện khi order.status = READY_NOTIFY_SHIPPER.
     */
    public function handover(Request $request, int $id)
    {
        $data = $request->validate(['shipper_id' => ['required', 'integer', 'exists:users,id']]);
        $this->service->handover($request->user(), $id, $data['shipper_id']);
        return $this->detail($id);
    }

    /**
     * GET /api/staff/online-orders/deliveries
     * Danh sách đơn của shipper (đang giao + đã giao).
     */
    public function deliveries(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->where('shipper_id', $request->user()->id)
                ->whereIn('status', ['CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER', 'DELIVERING', 'DELIVERED', 'COMPLETED'])
                ->with(['items.toppings.topping'])
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($o) => app(StaffOrderService::class)->formatOrderDetail($o)),
        ]);
    }

    /**
     * GET /api/staff/deliveries/stats
     * Thống kê nhanh cho shipper hiện tại:
     *  - Số đơn đang giao (DELIVERING)
     *  - Số đơn chờ khách xác nhận (DELIVERED)
     *  - Số đơn hoàn tất hôm nay (COMPLETED trong ngày)
     *  - Tổng tiền COD đã thu hôm nay (status SUCCESS, method CASH, processed_by = shipper)
     *  - Tổng số đơn đã giao hôm nay
     *
     * Middleware: staff:SHIPPER,MANAGER (manager xem được để theo dõi shipper).
     */
    public function deliveryStats(Request $request)
    {
        $shipperId = $request->user()->id;
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        $delivering = (int) DB::table('orders')
            ->where('shipper_id', $shipperId)
            ->where('status', 'DELIVERING')
            ->count();
        $awaiting = (int) DB::table('orders')
            ->where('shipper_id', $shipperId)
            ->where('status', 'DELIVERED')
            ->count();
        $completedToday = (int) DB::table('orders')
            ->where('shipper_id', $shipperId)
            ->where('status', 'COMPLETED')
            ->whereBetween('delivered_at', [$todayStart, $todayEnd])
            ->count();

        // COD thu hôm nay: payments.processed_by = shipper, status = SUCCESS, method = CASH, processed_at trong hôm nay
        $codToday = (float) DB::table('payments')
            ->where('processed_by', $shipperId)
            ->where('status', 'SUCCESS')
            ->where('method', 'CASH')
            ->whereBetween('processed_at', [$todayStart, $todayEnd])
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'delivering' => $delivering,
                'awaiting' => $awaiting,
                'completed_today' => $completedToday,
                'cod_collected_today' => $codToday,
            ],
        ]);
    }

    /**
     * POST /api/staff/online-orders/{id}/pickup
     * Shipper xác nhận đã lấy món.
     */
    public function pickedUp(Request $request, int $id)
    {
        $this->service->pickedUp($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * POST /api/staff/online-orders/{id}/arrived
     * Shipper xác nhận đã tới điểm giao (chỉ ONLINE).
     */
    public function arrived(Request $request, int $id)
    {
        $this->service->arrived($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * POST /api/staff/online-orders/{id}/complete
     * Shipper xác nhận giao thành công (bước cuối).
     */
    public function delivered(Request $request, int $id)
    {
        $this->service->delivered($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * POST /api/staff/online-orders/{id}/issue
     * Shipper báo sự cố.
     */
    public function issue(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->deliveryIssue($request->user(), $id, $data['reason']);
        return $this->detail($id);
    }

    /**
     * POST /api/orders/me/{id}/confirm-receipt
     * Khách hàng xác nhận đã nhận được hàng.
     *   - DELIVERED  → COMPLETED + tích điểm thưởng (nếu có)
     *   - COMPLETED  → idempotent, không cộng thêm điểm
     */
    public function confirmReceipt(Request $request, int $id)
    {
        $this->service->customerConfirmReceipt($request->user(), $id);
        return $this->detail($id);
    }

    /**
     * GET /api/staff/deliveries/{id}/route
     * Lấy hướng dẫn đường đi bộ (ô tô) từ nhà hàng đến điểm giao hàng của khách.
     * Shipper dùng để xem chỉ dẫn tiếng Việt + polyline vẽ lên bản đồ.
     *
     * Chỉ shipper được bàn giao đơn này (hoặc manager) mới được xem.
     */
    public function deliveryRoute(Request $request, int $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Phân quyền: shipper được giao đơn này, hoặc manager.
        $isManager = $user->roles->contains(fn ($r) => $r->code === 'MANAGER');
        $isAssignedShipper = $order->shipper_id === $user->id;
        abort_unless($isManager || $isAssignedShipper, 403, 'Bạn không có quyền xem đường đi của đơn này.');

        return $this->buildRouteResponse($order);
    }

    /**
     * GET /api/orders/me/{id}/route
     * Khách hàng xem đường đi của shipper đến đơn của chính mình (chỉ ONLINE).
     */
    public function customerRoute(Request $request, int $id)
    {
        $order = Order::where('id', $id)
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();
        abort_unless(
            $order->order_type === 'ONLINE'
            && $order->delivery_latitude !== null
            && $order->delivery_longitude !== null,
            422, 'Đơn này không có toạ độ giao hàng.',
        );
        return $this->buildRouteResponse($order);
    }

    private function buildRouteResponse(Order $order)
    {
        abort_unless(
            $order->order_type === 'ONLINE'
            && $order->delivery_latitude !== null
            && $order->delivery_longitude !== null,
            422, 'Đơn này không có toạ độ giao hàng.',
        );
        $config = config('checkout');
        $route = app(RoutingService::class)->route(
            (float) $config['latitude'], (float) $config['longitude'],
            (float) $order->delivery_latitude, (float) $order->delivery_longitude,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'restaurant' => [
                    'name' => 'Mì Cay Đại học Cần Thơ',
                    'address' => $config['restaurant_address'] ?? '',
                    'latitude' => (float) $config['latitude'],
                    'longitude' => (float) $config['longitude'],
                ],
                'customer' => [
                    'name' => $order->recipient_name,
                    'phone' => $order->recipient_phone,
                    'address' => $order->delivery_address,
                    'latitude' => (float) $order->delivery_latitude,
                    'longitude' => (float) $order->delivery_longitude,
                    'note' => $order->note,
                ],
                'route' => $route,
            ],
        ]);
    }

    /**
     * POST /api/orders/me/{id}/cancel
     * Khách hàng tự huỷ đơn ONLINE/TAKEAWAY của chính mình.
     * Chỉ cho phép khi đơn đang PENDING (chưa nhà hàng xác nhận).
     */
    public function customerCancel(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        app(OnlineOrderService::class)->customerCancel(
            $request->user(),
            $id,
            $data['reason'],
        );

        // Trả về chi tiết đơn (kèm status mới) để client refresh UI ngay.
        $order = \App\Models\Order::where('customer_id', $request->user()->id)
            ->with(['items.toppings.topping', 'cancellationRequest'])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => app(\App\Http\Controllers\Api\OrderController::class)
                ->formatOrderDetailPublic($order),
        ]);
    }

    private function detail(int $id)
    {
        return response()->json([
            'success' => true,
            'data'    => app(StaffOrderService::class)->getOrderDetail($id),
        ]);
    }
}
