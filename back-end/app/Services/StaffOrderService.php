<?php

namespace App\Services;

use App\Exceptions\ShortageDetectedException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemTopping;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StaffOrderService — nghiệp vụ đơn hàng cho phục vụ / quản lý.
 *
 * Khác với CartService (dành cho khách hàng đặt online):
 *  - Order gắn với table_session
 *  - Có thể thêm / sửa / xoá món khi đơn chưa hoàn tất
 *  - State machine cho order_items: PENDING → PREPARING → READY → SERVED
 *
 * BR:
 *  - BR-OD-01: order_items PENDING mới được phép sửa qty/note/spicy_level
 *  - BR-OD-02: order_items PREPARING/READY chỉ được cập nhật status (không sửa qty)
 *  - BR-OD-03: state machine theo BR05: PENDING → PREPARING|CANCELLED, PREPARING → READY|CANCELLED,
 *                                      READY → SERVED, SERVED → []
 *  - BR-OD-04: order.total_amount = order.subtotal - order.discount_amount
 */
class StaffOrderService
{
    /** State machine cho order_item.status */
    public const ITEM_STATUS_TRANSITIONS = [
        'PENDING'   => ['PREPARING', 'CANCELLED'],
        'PREPARING' => ['PENDING', 'READY', 'CANCELLED'],
        'READY'     => ['SERVED'],
        'SERVED'    => [],
        'CANCELLED' => [],
    ];

    /**
     * Danh sách orders (có filter status).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOrders(?string $status = null, ?int $waiterId = null): array
    {
        $query = Order::with(['items.toppings.topping', 'items.menuItem', 'tableSession.table', 'waiter', 'customer']);

        if ($status) {
            $query->where('status', $status);
        }
        if ($waiterId) {
            $query->where('waiter_id', $waiterId);
        }

        return $query->orderByDesc('placed_at')
            ->get()
            ->map(fn (Order $o) => $this->formatOrderList($o))
            ->values()
            ->all();
    }

    /**
     * Chi tiết 1 order (kèm items + toppings).
     */
    public function getOrderDetail(int $orderId): array
    {
        /** @var Order|null $order */
        $order = Order::with(['items.toppings.topping', 'tableSession', 'waiter', 'customer'])
            ->find($orderId);
        if (!$order) {
            throw ValidationException::withMessages(['order_id' => ['Không tìm thấy đơn hàng.']]);
        }
        return $this->formatOrderDetail($order);
    }

    /**
     * Tạo order mới gắn với 1 table_session đang mở.
     */
    public function createOrderForSession(int $sessionId, User $waiter): array
    {
        return DB::transaction(function () use ($sessionId, $waiter) {
            /** @var TableSession|null $session */
            $session = TableSession::with('table')->lockForUpdate()->find($sessionId);
            if (!$session) {
                throw ValidationException::withMessages(['session_id' => ['Không tìm thấy phiên phục vụ.']]);
            }
            if ($session->status !== 'OPEN') {
                throw ValidationException::withMessages(['session_id' => ['Phiên không ở trạng thái OPEN.']]);
            }
            // BR: 1 session chỉ có 1 order (lấy order mới nhất nếu đã có → trả về luôn)
            $existing = Order::where('table_session_id', $session->id)
                ->where('status', '!=', 'CANCELLED')
                ->latest('placed_at')
                ->first();
            if ($existing) {
                return $this->formatOrderDetail($existing->fresh()->load(['items.toppings.topping']));
            }

            $order = Order::create([
                'code'            => $this->generateOrderCode(),
                'customer_id'     => null,
                'waiter_id'       => $waiter->id,
                'table_session_id' => $session->id,
                'table_code'      => $session->table?->code,
                'order_type'      => 'DINE_IN',
                'subtotal'        => 0,
                'discount_amount' => 0,
                'total_amount'    => 0,
                'status'          => 'PENDING',
                'placed_at'       => now(),
            ]);

            return $this->formatOrderDetail($order->fresh()->load(['items.toppings.topping']));
        });
    }

    /**
     * Thêm 1 món vào order.
     */
    public function addItem(int $orderId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $data) {
            /** @var Order|null $order */
            $order = Order::with('items')->lockForUpdate()->find($orderId);
            if (!$order) {
                throw ValidationException::withMessages(['order_id' => ['Không tìm thấy đơn hàng.']]);
            }
            abort_if(DB::table('invoices')->where('order_id', $order->id)->exists(), 409, 'Đơn đã lập hóa đơn, không thể thay đổi món hoặc giá.');
            if (in_array($order->status, ['COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['order_id' => ["Đơn đã {$order->status} — không thể thêm món."]]);
            }

            /** @var MenuItem|null $menu */
            $menu = MenuItem::find($data['menu_item_id']);
            if (!$menu || !$menu->is_active || !$menu->is_available) {
                throw ValidationException::withMessages(['menu_item_id' => ['Món không khả dụng.']]);
            }

            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $spicyLevel = isset($data['spicy_level']) ? max(0, min(7, (int) $data['spicy_level'])) : (int) $menu->spicy_level;
            $note = isset($data['note']) ? mb_substr((string) $data['note'], 0, 200) : null;

            // Tính giá + toppings
            $unitPrice = (float) $menu->price;
            $toppingPrice = 0.0;
            $toppingData = [];
            $toppingIds = $data['topping_ids'] ?? [];
            if (!empty($toppingIds)) {
                $toppings = MenuItem::whereIn('id', $toppingIds)
                    ->whereHas('category', fn ($q) => $q->where('slug', 'topping-them'))
                    ->where('is_active', true)
                    ->where('is_available', true)
                    ->get();
                if ($toppings->count() !== count($toppingIds)) {
                    throw ValidationException::withMessages(['topping_ids' => ['Một số topping không hợp lệ.']]);
                }
                foreach ($toppings as $t) {
                    $tp = (float) $t->price;
                    $toppingPrice += $tp;
                    $toppingData[] = [
                        'topping_menu_item_id' => (int) $t->id,
                        'quantity'             => 1,
                        'unit_price'           => $tp,
                        'subtotal'             => $tp * $quantity,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }
            }

            $requested = [$menu->id => $quantity];
            foreach ($toppingData as $topping) {
                $id = $topping['topping_menu_item_id'];
                $requested[$id] = ($requested[$id] ?? 0) + $quantity;
            }
            app(MenuAvailabilityService::class)->assertCanOrder($requested);

            $perItemPrice = $unitPrice + $toppingPrice;
            $itemSubtotal = $perItemPrice * $quantity;

            $orderItem = OrderItem::create([
                'order_id'             => $order->id,
                'menu_item_id'         => $menu->id,
                'item_name'            => $menu->name,
                'unit_price'           => $unitPrice,
                'quantity'             => $quantity,
                'subtotal'             => $itemSubtotal,
                'note'                 => $note,
                'spicy_level_override' => $spicyLevel,
                'status'               => 'PENDING',
            ]);

            // Món gọi thêm cần vào hàng chờ dù đợt trước đã phục vụ xong.
            if (in_array($order->status, ['SERVED', 'CONFIRMED'], true)) {
                $order->status = 'PENDING';
                $order->save();
            }

            if (!empty($toppingData)) {
                foreach ($toppingData as &$td) {
                    $td['order_item_id'] = $orderItem->id;
                }
                unset($td);
                OrderItemTopping::insert($toppingData);
            }

            $this->recalcOrderTotals($order);
            $order->refresh();

            return $this->formatOrderDetail($order->fresh()->load(['items.toppings.topping']));
        });
    }

    /**
     * Cập nhật order_item (chỉ khi còn PENDING, theo BR-OD-01/02).
     */
    public function updateItem(int $orderId, int $itemId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $itemId, $data) {
            /** @var Order|null $order */
            $order = Order::with('items')->lockForUpdate()->find($orderId);
            if (!$order) {
                throw ValidationException::withMessages(['order_id' => ['Không tìm thấy đơn hàng.']]);
            }
            abort_if(DB::table('invoices')->where('order_id', $order->id)->exists(), 409, 'Đơn đã lập hóa đơn, không thể thay đổi món hoặc giá.');
            if (in_array($order->status, ['COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['order_id' => ["Đơn đã {$order->status}."]]);
            }

            /** @var OrderItem|null $item */
            $item = OrderItem::with('toppings')->where('order_id', $order->id)->find($itemId);
            if (!$item) {
                throw ValidationException::withMessages(['item_id' => ['Không tìm thấy món trong đơn.']]);
            }
            if ($item->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'item_id' => ["Chỉ sửa được món ở trạng thái PENDING (hiện tại: {$item->status})."],
                ]);
            }

            if (isset($data['quantity'])) {
                $item->quantity = max(1, min(99, (int) $data['quantity']));
            }
            if (isset($data['spicy_level'])) {
                $item->spicy_level_override = max(0, min(7, (int) $data['spicy_level']));
            }
            if (array_key_exists('note', $data)) {
                $item->note = $data['note'] === null || $data['note'] === ''
                    ? null
                    : mb_substr((string) $data['note'], 0, 200);
            }
            $item->save();

            foreach ($item->toppings as $topping) {
                $topping->subtotal = Money::decimal(Money::minor($topping->unit_price) * (int) $topping->quantity * (int) $item->quantity);
                $topping->save();
            }
            // Tính lại subtotal của order_item (đơn giá + topping) × quantity
            $toppingSum = (float) OrderItemTopping::where('order_item_id', $item->id)->sum('subtotal');
            $newSubtotal = ((float) $item->unit_price * (float) $item->quantity) + $toppingSum;
            $item->subtotal = $newSubtotal;
            $item->save();

            // Cập nhật topping (nếu có trong payload)
            if (isset($data['topping_ids']) && is_array($data['topping_ids'])) {
                $toppings = MenuItem::whereIn('id', $data['topping_ids'])
                    ->whereHas('category', fn ($q) => $q->where('slug', 'topping-them'))
                    ->where('is_active', true)
                    ->where('is_available', true)
                    ->get();
                if ($toppings->count() !== count($data['topping_ids'])) {
                    throw ValidationException::withMessages(['topping_ids' => ['Topping không hợp lệ.']]);
                }
                OrderItemTopping::where('order_item_id', $item->id)->delete();
                $rows = $toppings->map(fn ($t) => [
                    'order_item_id'        => $item->id,
                    'topping_menu_item_id' => (int) $t->id,
                    'quantity'             => 1,
                    'unit_price'           => (float) $t->price,
                    'subtotal'             => (float) $t->price * (int) $item->quantity,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ])->all();
                if (!empty($rows)) OrderItemTopping::insert($rows);

                // Tính lại subtotal sau khi đổi topping
                $item->refresh();
                $toppingSum = (float) OrderItemTopping::where('order_item_id', $item->id)->sum('subtotal');
                $item->subtotal = ((float) $item->unit_price * (int) $item->quantity) + $toppingSum;
                $item->save();
            }

            $requested = [$item->menu_item_id => (int) $item->quantity];
            foreach ($item->fresh('toppings')->toppings as $topping) {
                $id = (int) $topping->topping_menu_item_id;
                $requested[$id] = ($requested[$id] ?? 0) + (int) $item->quantity * (int) $topping->quantity;
            }
            app(MenuAvailabilityService::class)->assertCanOrder($requested, $item->id);

            $this->recalcOrderTotals($order);
            $order->refresh();

            return $this->formatOrderDetail($order->fresh()->load(['items.toppings.topping']));
        });
    }

    /**
     * Cập nhật status 1 order_item (theo state machine).
     *
     * @param  bool  $allowShortage  true = bếp đã xem cảnh báo thiếu nguyên liệu và chọn
     *                              "tiếp tục nấu". Chỉ áp dụng khi chuyển sang PREPARING.
     *
     * @return array{order:array, shortages:array, request_code:?string}
     */
    public function updateItemStatus(int $orderId, int $itemId, string $status, ?string $reason = null, ?User $byUser = null, bool $allowShortage = false): array
    {
        return DB::transaction(function () use ($orderId, $itemId, $status, $reason, $byUser, $allowShortage) {
            /** @var Order|null $order */
            $order = Order::with('items')->lockForUpdate()->find($orderId);
            if (!$order) {
                throw ValidationException::withMessages(['order_id' => ['Không tìm thấy đơn hàng.']]);
            }
            if (in_array($order->status, ['COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['order_id' => ['Đơn đã đóng, không thể cập nhật món.']]);
            }

            if ($order->order_type === 'ONLINE' || $order->order_type === 'TAKEAWAY') {
                abort_unless($order->confirmed_at && in_array($order->status, ['CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER']),
                    409, 'Đơn giao hàng cần được xác nhận trước khi bếp chế biến.');
                abort_unless(in_array($status, ['PREPARING', 'READY']), 409,
                    'Đơn giao hàng chỉ chuyển tiến trong bếp; hoàn tất qua shipper.');
                // COD: hóa đơn có thể chưa thanh toán → bếp vẫn nấu được
                // PREPAID: cần thanh toán trước (đã kiểm tra ở OnlineOrderService::confirm)
            }
            if ($status === 'CANCELLED') {
                abort_if(DB::table('invoices')->where('order_id', $order->id)->exists(), 409,
                    'Đơn đã lập hóa đơn; không được hủy món riêng lẻ.');
            }

            /** @var OrderItem|null $item */
            $item = OrderItem::where('order_id', $order->id)->find($itemId);
            if (!$item) {
                throw ValidationException::withMessages(['item_id' => ['Không tìm thấy món.']]);
            }

            $allowed = self::ITEM_STATUS_TRANSITIONS[$item->status] ?? [];
            if (!in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Không thể chuyển từ '{$item->status}' sang '{$status}'."],
                ]);
            }

            $now = now();
            $item->status = $status;
            if ($status === 'PENDING') $item->started_at = null;
            if ($status === 'PREPARING' && !$item->started_at) $item->started_at = $now;
            if (in_array($status, ['PREPARING', 'READY'], true) && !$item->recipe_captured_at) {
                app(ExpectedUsageService::class)->capture($item, $now);
            }
            if ($status === 'READY' && !$item->ready_at) $item->ready_at = $now;
            if ($status === 'SERVED' && !$item->served_at) $item->served_at = $now;
            if ($status === 'CANCELLED') {
                $item->cancelled_by = $byUser?->id;
                $item->cancellation_reason = $reason ?? 'Không rõ lý do';
            }
            $item->save();

            $shortages = [];
            $requestCode = null;

            if ($status === 'PREPARING') {
                // Bếp bắt đầu nấu → trừ kho theo công thức đã chụp.
                // Nếu thiếu nguyên liệu và bếp chưa đồng ý nấu tiếp → trả cảnh báo,
                // KHÔNG đổi trạng thái món (bếp sẽ chọn tiếp tục hoặc hủy).
                abort_if($byUser === null, 403, 'Cần đăng nhập để bắt đầu nấu.');
                $consumption = app(InventoryConsumptionService::class)->deductForItem(
                    $item,
                    $byUser,
                    $allowShortage,
                );
                if (!$consumption['deducted']) {
                    // Rollback toàn bộ transaction để trạng thái món không đổi.
                    throw new ShortageDetectedException($consumption['shortages']);
                }
                $shortages = $consumption['shortages'];
                $requestCode = $consumption['request_code'];
            }

            if ($status === 'CANCELLED' && $reason && str_contains($reason, 'Hết nguyên liệu')) {
                // Bếp hủy vì hết nguyên liệu (chưa từng bắt đầu nấu) → xác định
                // nguyên liệu đang thiếu để biết món có nên bị ẩn khỏi thực đơn không.
                // Món đã bắt đầu nấu thì $shortages đã có sẵn từ nhánh PREPARING.
                if ($shortages === []) {
                    $shortages = app(InventoryConsumptionService::class)->checkShortage($item)['shortages'];
                }
            }

            if ($status === 'CANCELLED' && $item->inventory_deducted_at !== null && $byUser) {
                // Món đã bắt đầu nấu (đã trừ kho) mà bị hủy → hoàn lại đúng phần đã trừ.
                app(InventoryConsumptionService::class)->restoreForItem(
                    $item,
                    $byUser,
                    $reason ?? 'Bếp hủy món',
                );
            }

            if ($status === 'CANCELLED' && $reason && str_contains($reason, 'Hết nguyên liệu')) {
                // Bếp hủy vì hết nguyên liệu → ẩn món khỏi thực đơn cho khách.
                // Chỉ ẩn khi nguyên liệu ĐANG THIẾU là trọng yếu (mì, thịt, phô mai...);
                // thiếu gia vị phụ thì bếp vẫn nấu được, không ẩn món.
                app(InventoryConsumptionService::class)->hideMenuItemForShortage(
                    (int) $item->menu_item_id,
                    $reason,
                    array_map(fn ($s) => (int) $s['ingredient_id'], $shortages),
                );
            }

            // Cập nhật status tổng của order
            $allItems = OrderItem::where('order_id', $order->id)->get();
            $statuses = $allItems->where('status', '!=', 'CANCELLED')->pluck('status')->all();

            if (empty($statuses)) {
                $order->status = $order->order_type === 'DINE_IN' ? 'PENDING' : 'CANCELLED';
            } elseif (count(array_filter($statuses, fn ($s) => $s === 'SERVED')) === count($statuses)) {
                $order->status = 'SERVED';
            } elseif (count(array_filter($statuses, fn ($s) => in_array($s, ['READY', 'SERVED']))) === count($statuses)) {
                // Tất cả món hoàn thành
                if (in_array($order->order_type, ['ONLINE', 'TAKEAWAY'])) {
                    // Chuyển sang READY_NOTIFY_SHIPPER thay vì READY
                    // Để phân biệt: READY = bếp xong nhưng chưa báo shipper,
                    //              READY_NOTIFY_SHIPPER = bếp đã bấm thông báo shipper
                    $order->status = 'READY';
                } else {
                    $order->status = 'READY';
                }
            } elseif (in_array('PREPARING', $statuses) || in_array('READY', $statuses) || in_array('SERVED', $statuses)) {
                $order->status = 'PREPARING';
            } else {
                $order->status = $order->order_type === 'ONLINE' || $order->order_type === 'TAKEAWAY' ? 'CONFIRMED' : 'PENDING';
            }
            $order->save();

            if ($status === 'CANCELLED') $this->recalcOrderTotals($order);

            return [
                'order'       => $this->formatOrderDetail($order->fresh()->load(['items.toppings.topping'])),
                'shortages'   => $shortages,
                'request_code' => $requestCode,
            ];
        });
    }

    /**
     * Tính lại order.subtotal + order.total_amount từ các items không bị huỷ.
     */
    private function recalcOrderTotals(Order $order): void
    {
        $subtotal = (float) OrderItem::where('order_id', $order->id)
            ->where('status', '!=', 'CANCELLED')
            ->sum('subtotal');
        $order->subtotal = $subtotal;
        $order->total_amount = max(0, $subtotal - (float) $order->discount_amount);
        $order->save();
    }

    /**
     * Sinh mã order: ORD-YYYYMMDD-NNNN.
     */
    private function generateOrderCode(): string
    {
        return app(OrderCodeGenerator::class)->next();
    }

    /* ====== Format helpers ====== */

    public function formatOrderList(Order $o): array
    {
        return $this->formatOrderDetail($o);
    }

    public function formatOrderDetail(Order $o): array
    {
        return [
            ...app(OnlineOrderService::class)->presentation($o),
            'id'               => (int) $o->id,
            'code'             => $o->code,
            'order_type'       => $o->order_type,
            'status'           => $o->status,
            'subtotal'         => (float) $o->subtotal,
            'discount'         => (float) $o->discount_amount,
            'total_amount'     => (float) $o->total_amount,
            'note'             => $o->note,
            'placed_at'        => $o->placed_at?->toIso8601String(),
            'completed_at'     => $o->completed_at?->toIso8601String(),
            'table_session_id' => $o->table_session_id ? (int) $o->table_session_id : null,
            'table_code'       => $o->tableSession?->table?->code,
            'waiter_id'        => $o->waiter_id ? (int) $o->waiter_id : null,
            'waiter_name'      => $o->waiter?->name,
            'customer_id'      => $o->customer_id ? (int) $o->customer_id : null,
            'customer_name'    => $o->customer?->name,
            'invoice_id'       => $o->invoice_id ? (int) $o->invoice_id : null,
            'invoice_status'   => $o->invoice_status,
            'invoice_requested_at' => $o->invoice_requested_at,
            'invoice_exported_at' => $o->invoice_exported_at,
            'item_count'       => $o->items->where('status', '!=', 'CANCELLED')->count(),
            'total_quantity'   => $o->items->where('status', '!=', 'CANCELLED')->sum('quantity'),
            'items'            => $o->items->map(fn (OrderItem $i) => $this->formatOrderItem($i))->values()->all(),
        ];
    }

    public function formatOrderItem(OrderItem $i): array
    {
        return [
            'id'                   => (int) $i->id,
            'menu_item_id'         => (int) $i->menu_item_id,
            'menu_item_name'       => $i->item_name,
            'menu_item_image'      => $i->menuItem?->image_url,
            'unit_price'           => (float) $i->unit_price,
            'quantity'             => (int) $i->quantity,
            'subtotal'             => (float) $i->subtotal,
            'note'                 => $i->note,
            'spicy_level'          => $i->effective_spicy_level,
            'status'               => $i->status,
            'started_at'           => $i->started_at?->toIso8601String(),
            'ready_at'             => $i->ready_at?->toIso8601String(),
            'served_at'            => $i->served_at?->toIso8601String(),
            'cancellation_reason'  => $i->cancellation_reason,
            'toppings'             => $i->toppings->map(fn (OrderItemTopping $t) => [
                'id'         => (int) $t->id,
                'name'       => $t->topping?->name,
                'unit_price' => (float) $t->unit_price,
                'quantity'   => (int) $t->quantity,
                'subtotal'   => (float) $t->subtotal,
            ])->values()->all(),
        ];
    }
}
