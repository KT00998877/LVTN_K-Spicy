<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryConsumptionService;
use App\Services\StaffOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * StaffKitchenController — API cho khu vực BẾP.
 *
 * Endpoints (yêu cầu auth:sanctum + role KITCHEN/MANAGER):
 *  - GET    /api/staff/kitchen/items
 *  - PATCH  /api/staff/kitchen/items/{orderId}/{itemId}/status
 *  - POST   /api/staff/kitchen/orders/{orderId}/notify-shipper
 *    (bếp bấm "Thông báo shipper" khi tất cả món đã hoàn thành → order → READY_NOTIFY_SHIPPER)
 */
class StaffKitchenController extends Controller
{
    public function __construct(private readonly StaffOrderService $service)
    {
    }

    /**
     * GET /api/staff/kitchen/items?status=PENDING,PREPARING,READY
     * Trả về danh sách order_items theo status (dùng cho bếp xem queue).
     */
    public function items(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'string']]);
        $statuses = $request->query('status', 'PENDING,PREPARING,READY');
        $statusArray = array_filter(
            array_map('trim', explode(',', $statuses)),
            fn ($s) => in_array($s, ['PENDING', 'PREPARING', 'READY'], true)
        );

        if (empty($statusArray)) {
            $statusArray = ['PENDING', 'PREPARING', 'READY'];
        }

        // Lấy order_items không bị huỷ, join order để lấy table_code
        $items = OrderItem::with(['order.tableSession.table', 'menuItem', 'toppings.topping'])
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'DELIVERING'])
                ->where(fn ($q) => $q->where('order_type', '!=', 'ONLINE')->orWhereNotNull('confirmed_at')))
            ->whereIn('status', $statusArray)
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $invoiceCodes = DB::table('invoices')->whereIn('order_id', $items->pluck('order_id')->unique())
            ->orderBy('id')->pluck('code', 'order_id');
        $shortageMap = app(InventoryConsumptionService::class)->shortagesForItems($items->all());
        $result = $items->map(fn (OrderItem $i) => [
            'order' => [
                'id'            => (int) $i->order->id,
                'code'          => $i->order->code,
                'order_type'    => $i->order->order_type,
                'invoice_code'  => $invoiceCodes[$i->order_id] ?? null,
                'table_code'    => $i->order->tableSession?->table?->code,
                'table_session_id' => $i->order->table_session_id ? (int) $i->order->table_session_id : null,
                'status'        => $i->order->status,
                'placed_at'     => $i->order->placed_at?->toIso8601String(),
            ],
            'item' => [
                'id'            => (int) $i->id,
                'menu_item_id'  => (int) $i->menu_item_id,
                'menu_item_name'=> $i->item_name,
                'menu_item_image'=> $i->menuItem?->image_url,
                'unit_price'    => (float) $i->unit_price,
                'quantity'      => (int) $i->quantity,
                'subtotal'      => (float) $i->subtotal,
                'note'          => $i->note,
                'spicy_level'   => $i->effective_spicy_level,
                'status'        => $i->status,
                'started_at'    => $i->started_at?->toIso8601String(),
                'ready_at'      => $i->ready_at?->toIso8601String(),
                'service_reminded_at' => $i->service_reminded_at?->toIso8601String(),
                'shortages'     => $shortageMap[(int) $i->id] ?? [],
                'toppings'      => $i->toppings->map(fn ($t) => [
                    'id'         => (int) $t->id,
                    'name'       => $t->topping?->name,
                    'unit_price' => (float) $t->unit_price,
                    'quantity'   => (int) $t->quantity,
                    'subtotal'   => (float) $t->subtotal,
                ])->values()->all(),
            ],
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * PATCH /api/staff/kitchen/items/{orderId}/{itemId}/status
     * Cập nhật trạng thái 1 món (bếp xác nhận bắt đầu / xong).
     *
     * Khi chuyển sang PREPARING mà thiếu nguyên liệu:
     *  - Chưa gửi allow_shortage → trả 409 kèm danh sách nguyên liệu thiếu.
     *    Món KHÔNG đổi trạng thái, bếp quyết định "Tiếp tục nấu" hoặc "Hủy món".
     *  - Gửi allow_shortage=true → bếp đã xem cảnh báo và chọn tiếp tục nấu.
     *    Hệ thống trừ tới 0, tạo yêu cầu nhập kho và báo quản lý.
     */
    public function updateStatus(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $canManage = $request->user()->roles()->where('code', 'MANAGER')->exists();
        $data = $request->validate([
            'status' => ['required', 'string', $canManage ? 'in:PENDING,PREPARING,READY,SERVED,CANCELLED' : 'in:PENDING,PREPARING,READY,CANCELLED'],
            'reason' => ['required_if:status,CANCELLED', 'nullable', 'string', 'max:500'],
            'allow_shortage' => ['sometimes', 'boolean'],
        ]);

        $result = $this->service->updateItemStatus(
            $orderId,
            $itemId,
            $data['status'],
            $data['reason'] ?? null,
            $request->user(),
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
     * POST /api/staff/kitchen/orders/{orderId}/notify-shipper
     * Bếp xác nhận tất cả món đã xong → chuyển order sang READY_NOTIFY_SHIPPER.
     * Manager/Cashier sẽ thấy đơn ở tab "Sẵn sàng giao" và bàn giao shipper.
     */
    public function notifyShipper(Request $request, int $orderId): JsonResponse
    {
        DB::transaction(function () use ($request, $orderId) {
            $order = Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->lockForUpdate()->findOrFail($orderId);

            abort_unless($order->confirmed_at, 409, 'Đơn chưa được xác nhận.');
            abort_unless($order->status === 'READY', 409,
                'Chỉ bấm thông báo shipper khi tất cả món đã hoàn thành (trạng thái READY).');
            abort_unless(
                $order->items()->whereNotIn('status', ['READY'])->where('status', '!=', 'CANCELLED')->count() === 0,
                409, 'Còn món chưa hoàn thành, không thể thông báo shipper.'
            );

            $order->forceFill(['status' => 'READY_NOTIFY_SHIPPER'])->save();

            DB::table('order_events')->insert([
                'order_id'   => $order->id,
                'actor_id'   => $request->user()->id,
                'event'      => 'KITCHEN_NOTIFY_SHIPPER',
                'status'     => 'READY_NOTIFY_SHIPPER',
                'note'       => 'Bếp đã hoàn thành tất cả món và thông báo shipper.',
                'created_at' => now(),
            ]);
            app(\App\Services\StaffNotificationService::class)->kitchenReady($order);
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã thông báo shipper. Đơn sẵn sàng để quản lý bàn giao.',
        ]);
    }
}
