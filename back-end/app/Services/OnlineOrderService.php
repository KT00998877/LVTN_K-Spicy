<?php

namespace App\Services;

use App\Models\{Order, OrderCancellationRequest, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OnlineOrderService — quản lý luồng đơn giao hàng (ONLINE) và mang đi (TAKEAWAY).
 *
 * Luồng trạng thái:
 *   PENDING
 *     → confirm() → CONFIRMED
 *         → PREPARING (bếp nấu)
 *             → READY (bếp xong 1 món)
 *                 → READY_NOTIFY_SHIPPER (bếp bấm "Thông báo shipper" cho tất cả món)
 *                     → handover() → DELIVERING (manager/cashier bàn giao shipper)
 *                         → pickedUp() → DELIVERING (shipper xác nhận đã lấy)
 *                             → arrived() → DELIVERING (shipper xác nhận đã tới)
 *                                 → delivered() → DELIVERED (shipper xác nhận giao xong —
 *                                                       CHỜ khách xác nhận để tích điểm)
 *                                     → customerConfirmReceipt() → COMPLETED + cộng điểm
 *
 * Điểm thưởng:
 *   - Chỉ cộng khi KHÁCH chủ động bấm "Tôi đã nhận hàng".
 *   - Nếu khách không bấm: đơn vẫn ở DELIVERED, KHÔNG cộng điểm.
 *   - Quy tắc cộng: 1 điểm cho mỗi 10.000đ tổng đơn (làm tròn xuống, tối thiểu 1).
 *
 * Thanh toán:
 *   - ONLINE + COD: mặc định, hóa đơn UNPAID → shipper thu tiền → ghi nhận PAID
 *   - ONLINE + PREPAID: khách thanh toán online trước → hóa đơn PAID
 *   - TAKEAWAY: tương tự ONLINE (COD hoặc PREPAID)
 */
class OnlineOrderService
{
    /**
     * Quy tắc tích điểm khi khách xác nhận đã nhận hàng.
     * 1 điểm / 10.000đ tổng đơn (làm tròn xuống), tối thiểu 1 điểm nếu đơn > 0.
     * Cho phép cấu hình dễ qua override hàm.
     */
    public const POINTS_PER_VND = 10000;

    public static function calculatePoints(float $totalAmount): int
    {
        if ($totalAmount <= 0) return 0;
        return max(1, (int) floor($totalAmount / self::POINTS_PER_VND));
    }

    /* ------------------------------------------------------------------ */
    /* Presentation (serialization)                                        */
    /* ------------------------------------------------------------------ */

    public function presentation(Order $order): array
    {
        $invoice = DB::table('invoices')->where('order_id', $order->id)->first();
        $cancellation = $order->relationLoaded('cancellationRequest')
            ? $order->cancellationRequest
            : $order->cancellationRequest()->first();
        return [
            'recipient_name'   => $order->recipient_name,
            'recipient_phone' => $order->recipient_phone,
            'delivery_address'=> $order->delivery_address,
            'delivery_latitude' => $order->delivery_latitude,
            'delivery_longitude' => $order->delivery_longitude,
            'delivery_distance_km' => $order->delivery_distance_km,
            'points_redeemed' => (int) $order->points_redeemed,
            'shipping_fee'    => (float) $order->shipping_fee,
            'payment_method'  => $order->payment_method, // 'COD' | 'PREPAID'
            'confirmed_at'     => $order->confirmed_at,
            'handed_over_at'  => $order->handed_over_at,
            'picked_up_at'    => $order->picked_up_at,
            'arrived_at'      => $order->arrived_at,
            'delivered_at'    => $order->delivered_at,
            'customer_received_at' => $order->customer_received_at,
            'shipper_id'      => $order->shipper_id,
            'shipper_name'    => $order->shipper_id ? User::whereKey($order->shipper_id)->value('name') : null,
            'delivery_issue'  => $order->delivery_issue,
            'points_awarded'  => (int) ($order->points_awarded ?? 0),
            'delivery_rating' => $order->delivery_rating !== null ? (int) $order->delivery_rating : null,
            'delivery_comment' => $order->delivery_comment,
            'delivery_rated_at' => $order->delivery_rated_at?->toIso8601String(),
            'delivery_photos' => $order->relationLoaded('deliveryPhotos')
                ? $order->deliveryPhotos->map(fn ($p) => [
                    'id'  => (int) $p->id,
                    'url' => $p->url,
                ])->values()->all()
                : [],
            'payment' => $invoice ? [
                'invoice_id'   => $invoice->id,
                'invoice_code' => $invoice->code,
                'status'       => $invoice->status,
                'total'        => (float) $invoice->total_amount,
                'paid'         => (float) $invoice->paid_amount,
                'is_simulated' => DB::table('payments')->where('invoice_id', $invoice->id)->where('is_simulated', true)->exists(),
            ] : null,
            'cancellation_request' => $cancellation ? [
                'status' => $cancellation->status,
                'reason' => $cancellation->reason,
                'requested_at' => $cancellation->requested_at?->toIso8601String(),
                'approved_at' => $cancellation->approved_at?->toIso8601String(),
                'refunded_at' => $cancellation->refunded_at?->toIso8601String(),
            ] : null,
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('id')
                ->get(['event', 'status', 'note', 'created_at'])->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Invoice helpers                                                      */
    /* ------------------------------------------------------------------ */

    public function invoice(Order $order): object
    {
        $rows = DB::table('invoices')->where('order_id', $order->id)->lockForUpdate()->get();
        abort_unless($rows->count() === 1, 409, 'Đơn phải có đúng một hóa đơn.');
        $invoice = $rows->first();
        abort_unless(Money::minor($invoice->total_amount) === Money::minor($order->total_amount), 409,
            'Tổng hóa đơn không khớp đơn hàng. Cần đối soát trước khi tiếp tục.');
        return $invoice;
    }

    public function createInvoice(Order $order): void
    {
        if (DB::table('invoices')->where('order_id', $order->id)->exists()) return;
        DB::table('invoices')->insert([
            'code'         => 'HD-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'order_id'     => $order->id,
            'customer_id'  => $order->customer_id,
            'subtotal'     => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'total_amount' => $order->total_amount,
            'status'       => 'UNPAID',
            'issued_at'    => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Caller holds order/invoice locks; fulfillment remains independent. */
    public function settleInvoice(Order $order, object $invoice): void
    {
        abort_unless(Money::minor($invoice->total_amount) === Money::minor($order->total_amount), 409);
        DB::table('invoices')->where('id', $invoice->id)->update([
            'paid_amount' => $invoice->total_amount, 'status' => 'PAID', 'paid_at' => now(), 'updated_at' => now(),
        ]);
        \App\Events\StaffOrderUpdated::dispatch((int) $order->id, $order->shipper_id ? (int) $order->shipper_id : null);
    }

    public function event(Order $order, ?User $actor, string $event, ?string $note = null): void
    {
        DB::table('order_events')->insert([
            'order_id'   => $order->id,
            'actor_id'   => $actor?->id,
            'event'      => $event,
            'status'     => $order->status,
            'note'       => $note,
            'created_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Thanh toán mô phỏng (chỉ dùng khi khách chọn PREPAID)              */
    /* ------------------------------------------------------------------ */

    public function simulatePayment(User $user, int $id): void
    {
        // DEMO fixtures only: never enable this path on a persistent database.
        $evaluation = app()->environment('testing')
            && config('evaluation.demo_payment_simulation', false) === true;
        if ($evaluation) {
            $connection = DB::connection();
            $evaluation = $connection->getDriverName() === 'sqlite'
                && $connection->getDatabaseName() === ':memory:';
        }
        abort_unless($evaluation, 410, 'Thanh toán mô phỏng đã ngừng. Vui lòng sử dụng VNPay hoặc COD.');

        DB::transaction(function () use ($user, $id) {
            $order = Order::whereKey($id)->where('customer_id', $user->id)
                ->whereIn('order_type', ['ONLINE', 'TAKEAWAY'])->lockForUpdate()->firstOrFail();
            abort_unless($order->payment_method === 'PREPAID' && $order->status === 'PENDING', 409,
                'DEMO requires an owned pending prepaid order.');
            $invoice = $this->invoice($order);
            $payments = DB::table('payments')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
            abort_if($payments->contains(fn ($payment) => !$payment->is_simulated), 409,
                'DEMO cannot modify or coexist with real payment attempts.');
            if ($payments->isNotEmpty()) {
                abort_unless($payments->count() === 1 && $payments->first()->method === 'OTHER'
                    && $payments->first()->transaction_ref === 'EVAL-DEMO-'.$order->id
                    && $payments->first()->status === 'SUCCESS'
                    && Money::minor($payments->first()->amount) === Money::minor($invoice->total_amount)
                    && $invoice->status === 'PAID'
                    && Money::minor($invoice->paid_amount) === Money::minor($invoice->total_amount), 409);
                return;
            }
            abort_unless($invoice->status === 'UNPAID' && Money::minor($invoice->paid_amount) === 0, 409);
            DB::table('payments')->insert([
                'invoice_id' => $invoice->id, 'method' => 'OTHER', 'amount' => $invoice->total_amount,
                'transaction_ref' => 'EVAL-DEMO-'.$order->id, 'status' => 'SUCCESS', 'is_simulated' => true,
                'processed_by' => $user->id, 'processed_at' => now(),
                'note' => 'DEMO_ONLY: isolated evaluation; no external payment confirmation.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->settleInvoice($order, $invoice);
            $this->event($order, $user, 'EVALUATION_DEMO_PAID', 'DEMO_ONLY; no gateway or real payment.');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Xác nhận đơn (manager / cashier)                                   */
    /*   - PREPAID: cần hóa đơn PAID                                      */
    /*   - COD:    chỉ cần PENDING + thông tin đủ → đơn chuyển CONFIRMED   */
    /* ------------------------------------------------------------------ */

    public function confirm(?User $actor, int $id, ?int $shipperId = null): void
    {
        DB::transaction(function () use ($actor, $id, $shipperId) {
            $order = $this->lockDelivery($id);
            abort_if($order->cancellationRequest()->whereIn('status', [
                OrderCancellationRequest::STATUS_PENDING,
                OrderCancellationRequest::STATUS_APPROVED,
            ])->exists(), 409, 'Đơn đang trong quy trình hủy và hoàn tiền.');
            // A null actor is reserved for verified gateway settlement inside its transaction.
            abort_if($actor === null && $order->payment_method !== 'PREPAID', 409);
            if ($order->confirmed_at) {
                abort_if($order->order_type === 'ONLINE' && $shipperId !== null && $order->shipper_id !== $shipperId,
                    409, 'Đơn đã được duyệt với shipper khác.');
                return;
            }
            if ($order->order_type === 'ONLINE') {
                // Cho phép duyệt đơn mà không chọn shipper thủ công → hệ thống tự
                // gán shipper gần nhà hàng nhất (Phase 2). Nếu không có shipper
                // nào rảnh thì bắt buộc phải chọn tay.
                $resolvedShipperId = $shipperId;

                if ($resolvedShipperId === null) {
                    $resolvedShipperId = app(ShipperDispatchService::class)->autoAssign($order);
                    abort_unless($resolvedShipperId !== null, 422,
                        'Chưa có shipper đang rảnh để tự phân công. Vui lòng chọn shipper thủ công.');
                }

                abort_unless(User::whereKey($resolvedShipperId)->where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->where('code', 'SHIPPER'))->exists(),
                    422, 'Shipper không hoạt động hoặc không hợp lệ.');

                $shipperId = $resolvedShipperId;
            }
            abort_unless($order->status === 'PENDING', 409, 'Đơn không ở trạng thái chờ xác nhận.');

            // Validate recipient info theo loại
            if ($order->order_type === 'ONLINE') {
                abort_unless($order->recipient_name && $order->recipient_phone && $order->delivery_address,
                    422, 'Đơn ONLINE thiếu thông tin giao hàng.');
            } else {
                abort_unless($order->recipient_name && $order->recipient_phone,
                    422, 'Đơn MANG ĐI thiếu tên hoặc SĐT người nhận.');
            }

            // Nếu PREPAID → cần hóa đơn PAID; COD → không cần
            if ($order->payment_method === 'PREPAID') {
                $invoice = $this->invoice($order);
                abort_unless($invoice->status === 'PAID' && Money::minor($invoice->paid_amount) === Money::minor($invoice->total_amount),
                    409, 'Đơn chưa thanh toán. Vui lòng thanh toán trước khi xác nhận.');
            }

            $changes = ['status' => 'CONFIRMED', 'confirmed_at' => now(), 'confirmed_by' => $actor?->id];
            if ($order->order_type === 'ONLINE') $changes['shipper_id'] = $shipperId;
            $order->forceFill($changes)->save();
            $this->event($order, $actor, 'CONFIRMED');
            if ($order->order_type === 'ONLINE') {
                $this->event($order, $actor, 'SHIPPER_ASSIGNED', 'Phân công shipper #'.$shipperId);
                app(StaffNotificationService::class)->orderConfirmed($order);
            }
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Bàn giao cho shipper (manager / cashier)                             */
    /*   Chỉ bàn giao khi order.status = READY_NOTIFY_SHIPPER            */
    /*   (bếp đã bấm "Thông báo shipper" sau khi hoàn thành tất cả món)  */
    /* ------------------------------------------------------------------ */

    public function handover(User $actor, int $id, int $shipperId): void
    {
        DB::transaction(function () use ($actor, $id, $shipperId) {
            $order = $this->lockDelivery($id);
            abort_unless($order->status === 'READY_NOTIFY_SHIPPER',
                409, 'Chỉ bàn giao khi bếp đã hoàn thành tất cả món và thông báo shipper.');
            abort_unless($order->confirmed_at, 409, 'Đơn chưa được xác nhận.');
            abort_unless($order->items()->exists()
                && !$order->items()->whereNotIn('status', ['READY'])->exists(),
                409, 'Còn món chưa hoàn thành.');
            abort_if($order->order_type === 'ONLINE' && $order->shipper_id !== null && $order->shipper_id !== $shipperId,
                409, 'Vui lòng bàn giao cho shipper đã được phân công.');
            User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'SHIPPER'))
                ->findOrFail($shipperId);
            $order->forceFill([
                'status'          => 'DELIVERING',
                'shipper_id'      => $shipperId,
                'handed_over_at'  => now(),
                'delivery_issue'  => null,
            ])->save();
            $this->event($order, $actor, 'HANDED_OVER',
                'Shipper #'.$shipperId.' ('.($order->order_type === 'TAKEAWAY' ? 'mang đi' : 'giao tận nơi').')');
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* 3 bước của shipper                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Bước 1: Shipper xác nhận đã lấy món từ bếp.
     */
    public function pickedUp(User $shipper, int $id): void
    {
        DB::transaction(function () use ($shipper, $id) {
            $order = $this->lockDeliveryForShipper($id, $shipper->id);
            abort_unless($order->status === 'DELIVERING', 409, 'Đơn chưa được bàn giao.');
            abort_if($order->picked_up_at, 409, 'Shipper đã xác nhận lấy hàng rồi.');
            $order->forceFill(['picked_up_at' => now()])->save();
            $this->event($order, $shipper, 'PICKED_UP',
                $order->order_type === 'TAKEAWAY' ? 'Shipper đã nhận hàng mang đi' : 'Shipper đã lấy hàng để giao');
        }, 3);
    }

    /**
     * Bước 2: Shipper xác nhận đã tới điểm giao (chỉ ONLINE).
     */
    public function arrived(User $shipper, int $id): void
    {
        DB::transaction(function () use ($shipper, $id) {
            $order = $this->lockDeliveryForShipper($id, $shipper->id);
            abort_unless($order->status === 'DELIVERING', 409, 'Đơn chưa được bàn giao.');
            abort_if(!$order->picked_up_at, 409, 'Cần xác nhận đã lấy hàng trước.');
            abort_if($order->order_type !== 'ONLINE', 409, 'Bước này chỉ áp dụng cho đơn giao tận nơi.');
            abort_if($order->arrived_at, 409, 'Shipper đã xác nhận đã tới rồi.');
            $order->forceFill(['arrived_at' => now()])->save();
            $this->event($order, $shipper, 'ARRIVED', 'Shipper đã tới điểm giao');
        }, 3);
    }

    /**
     * Bước 3: Shipper xác nhận đã giao xong (giao tận nơi / khách đã nhận tại quán).
     *   - COD: ghi nhận thu tiền → hóa đơn chuyển PAID.
     *   - PREPAID: không cần xử lý thanh toán.
     *
     * Lưu ý: trạng thái chuyển sang **DELIVERED** (CHƯA COMPLETED).
     *   Đơn sẽ chính thức COMPLETED + cộng điểm khi khách bấm "Tôi đã nhận hàng"
     *   (gọi customerConfirmReceipt). Nếu khách không bấm, đơn vẫn ở DELIVERED.
     */
    public function delivered(User $shipper, int $id): void
    {
        DB::transaction(function () use ($shipper, $id) {
            $order = $this->lockDeliveryForShipper($id, $shipper->id);
            abort_unless($order->status === 'DELIVERING', 409, 'Đơn chưa được bàn giao để giao hàng.');
            abort_if($order->delivered_at, 409, 'Shipper đã xác nhận giao xong.');
            abort_if(!$order->picked_up_at, 409, 'Cần xác nhận đã lấy hàng trước.');
            abort_if($order->order_type === 'ONLINE' && !$order->arrived_at,
                409, 'Cần xác nhận đã tới điểm giao trước.');

            // COD: thu tiền mặt từ khách → ghi nhận thanh toán
            if ($order->payment_method === 'COD') {
                $invoice = $this->invoice($order);
                if ($invoice->status !== 'PAID') {
                    DB::table('payments')->insert([
                        'invoice_id'      => $invoice->id,
                        'method'          => 'CASH',
                        'amount'          => $invoice->total_amount,
                        'transaction_ref' => 'COD-'.$order->id.'-'.now()->format('His'),
                        'status'          => 'SUCCESS',
                        'is_simulated'    => false,
                        'processed_by'    => $shipper->id,
                        'processed_at'    => now(),
                        'note'            => 'Thu tiền mặt khi giao hàng (COD)',
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                    $this->settleInvoice($order, $invoice);
                    $this->event($order, $shipper, 'COD_COLLECTED', 'Shipper thu COD: '.number_format($invoice->total_amount).'đ');
                }
            }

            // Đánh dấu món đã bàn giao cho khách
            $order->items()->update(['status' => 'SERVED', 'served_at' => now()]);

            // Chuyển sang DELIVERED — chờ khách xác nhận để tích điểm.
            $order->forceFill([
                'status'       => 'DELIVERED',
                'delivered_at' => now(),
            ])->save();

            $this->event($order, $shipper, 'DELIVERED',
                $order->order_type === 'TAKEAWAY'
                    ? 'Khách đã nhận hàng mang đi (chờ xác nhận để tích điểm)'
                    : 'Đã giao tận nơi (chờ khách xác nhận để tích điểm)'
            );
        }, 3);
    }

    /**
     * Khách hàng xác nhận đã nhận được hàng → COMPLETED + tích điểm thưởng.
     *
     * Đây là bước cuối cùng của luồng giao hàng. Chỉ áp dụng khi:
     *  - Đơn thuộc customer hiện tại
     *  - Đơn đang ở trạng thái DELIVERED (shipper đã xác nhận giao xong)
     *  - Đơn thuộc loại ONLINE / TAKEAWAY (DINE_IN dùng waiter xác nhận tại bàn)
     *
     * Quy tắc tích điểm:
     *  - 1 điểm cho mỗi 10.000đ tổng đơn (làm tròn xuống), tối thiểu 1.
     *  - Ghi vào loyalty_point_ledger + cập nhật users.loyalty_points.
     *  - Idempotent: nếu đã xác nhận trước đó thì trả về OK, không cộng thêm điểm.
     */
    public function customerConfirmReceipt(User $customer, int $orderId): void
    {
        DB::transaction(function () use ($customer, $orderId) {
            $order = Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($orderId);

            abort_unless(in_array($order->status, ['DELIVERED', 'COMPLETED'], true),
                409, 'Đơn chưa được shipper xác nhận giao xong.');

            // Đã xác nhận rồi → idempotent, không cộng điểm lại.
            if ($order->status === 'COMPLETED' && $order->customer_received_at) {
                return;
            }

            $now = now();
            $order->forceFill([
                'status'                => 'COMPLETED',
                'completed_at'          => $now,
                'customer_received_at'  => $now,
                'customer_received_by'  => $customer->id,
            ])->save();

            $this->event($order, $customer, 'CUSTOMER_RECEIVED', 'Khách xác nhận đã nhận hàng');

            // Tích điểm: 1 điểm / 10.000đ, tối thiểu 1. Có áp dụng hệ số nhân theo hạng.
            $basePoints = self::calculatePoints((float) $order->total_amount);
            if ($basePoints > 0) {
                $customer = User::whereKey($customer->id)->lockForUpdate()->firstOrFail();

                // Hệ số nhân theo hạng thành viên (loyalty_tiers.benefits.point_multiplier).
                // Đồng = x1, Bạc = x1.2, Vàng = x1.5, Kim Cương = x2.
                $tierService = app(\App\Services\LoyaltyTierService::class);
                $points = $tierService->applyPointMultiplier($customer, $basePoints);

                if ($points > 0) {
                    $newBalance = ((int) ($customer->loyalty_points ?? 0)) + $points;
                    $customer->forceFill(['loyalty_points' => $newBalance])->save();

                    DB::table('loyalty_point_ledger')->insert([
                        'user_id'        => $customer->id,
                        'order_id'       => $order->id,
                        'type'           => 'EARN',
                        'points'         => $points,
                        'balance_after'  => $newBalance,
                        'reason'         => $basePoints !== $points
                            ? sprintf('Tích điểm đơn #%s (%d → %d điểm, áp hệ số %s)',
                                $order->code, $basePoints, $points, $customer->tier?->name ?? 'Đồng')
                            : 'Tích điểm khi xác nhận đã nhận hàng đơn #'.$order->code,
                        'actor_id'       => $customer->id,
                        'created_at'     => $now,
                    ]);

                    $order->forceFill(['points_awarded' => $points])->save();
                    $this->event($order, $customer, 'POINTS_AWARDED',
                        "Cộng {$points} điểm thưởng (tổng điểm: {$newBalance}).");
                }

                // ---- Loyalty tier upgrade ----
                // Cộng dồn total_spent dựa trên SUBTOTAL (chưa ship, chưa voucher) — tức
                // là giá trị thực của đơn hàng, đã trừ discount. Phí ship là "vận chuyển",
                // không phải "chi tiêu cho món ăn" nên không tính vào hạng.
                $subtotal = (float) ($order->subtotal ?? 0);
                $discount = (float) ($order->discount_amount ?? 0);
                $spentNow = (int) max(0, round($subtotal - $discount));
                $tierService->recordSpend($customer, $spentNow);
                $newTier = $tierService->evaluateAndUpgrade($customer);
                if ($newTier !== null) {
                    $this->event($order, $customer, 'LOYALTY_TIER_UPGRADED',
                        sprintf('Chúc mừng! Bạn đã thăng hạng %s — tổng chi tiêu đạt %sđ.',
                            $newTier->name, number_format((int) $customer->total_spent)));
                }
            }
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Khách hàng tự huỷ đơn                                                */
    /* ------------------------------------------------------------------ */
    /**
     * Cho phép customer huỷ đơn ONLINE/TAKEAWAY của chính mình khi:
     *  - status = PENDING (nhà hàng chưa xác nhận)
     *  - PREPAID + VNPay chưa SUCCESS → tự động REFUND
     *  - PREPAID + VNPay đang PENDING → chặn, cần chờ IPN xác minh
     *  - PREPAID + VNPay SUCCESS (tiền thực) → tạo yêu cầu để MANAGER duyệt
     *  - COD → huỷ thẳng
     *
     * Idempotent: nếu đã CANCELLED thì trả về OK.
     */
    public function customerCancel(User $customer, int $orderId, string $reason): void
    {
        DB::transaction(function () use ($customer, $orderId, $reason) {
            $order = Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->status === 'CANCELLED') return;
            abort_unless($order->status === 'PENDING', 409,
                'Chỉ có thể huỷ đơn khi nhà hàng chưa xác nhận.');

            $invoice = $this->invoice($order);

            // Nếu PREPAID → kiểm tra payment để xử lý tiền
            if ($order->payment_method === 'PREPAID' && $invoice) {
                // Có giao dịch VNPay đang chờ → chặn, cần xác minh qua IPN trước
                abort_if(
                    DB::table('payments')->where('invoice_id', $invoice->id)
                        ->where('provider', 'vnpay')->where('status', 'PENDING')->exists(),
                    409,
                    'Giao dịch VNPay đang chờ xác minh. Vui lòng chờ hoặc liên hệ nhà hàng.'
                );
                $paidVnpay = DB::table('payments')->where('invoice_id', $invoice->id)
                    ->where('provider', 'vnpay')
                    ->where('status', 'SUCCESS')
                    ->where('is_simulated', false)
                    ->exists();

                if ($paidVnpay) {
                    $request = OrderCancellationRequest::firstOrCreate(
                        ['order_id' => $order->id],
                        [
                            'requested_by' => $customer->id,
                            'reason' => $reason,
                            'status' => OrderCancellationRequest::STATUS_PENDING,
                            'requested_at' => now(),
                        ],
                    );
                    if ($request->wasRecentlyCreated) {
                        $this->event($order, $customer, 'CANCEL_REQUESTED', '[Khách yêu cầu hủy] '.$reason);
                        app(StaffNotificationService::class)->orderCancellationRequested($order);
                    }
                    return;
                }

                abort_if(
                    DB::table('payments')->where('invoice_id', $invoice->id)
                        ->where('status', 'SUCCESS')
                        ->where('is_simulated', false)->exists(),
                    409,
                    'Phương thức thanh toán này cần nhà hàng hỗ trợ hủy riêng.'
                );
                // Đã SUCCESS nhưng là simulated (COD → PREPAID đổi) → refund
                DB::table('payments')->where('invoice_id', $invoice->id)
                    ->where('status', 'SUCCESS')->update([
                        'status' => 'REFUNDED',
                        'updated_at' => now(),
                    ]);
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'status' => 'REFUNDED', 'paid_amount' => 0, 'updated_at' => now(),
                ]);
            }

            $order->items()->update([
                'status' => 'CANCELLED',
                'cancelled_by' => $customer->id,
                'cancellation_reason' => '[Khách huỷ] '.$reason,
            ]);
            $order->update([
                'status' => 'CANCELLED',
                'cancelled_by_user_id' => $customer->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            // Hoàn lại voucher + delivery voucher nếu có
            app(DeliveryPricingService::class)->refund($order);
            app(\App\Services\VoucherService::class)->refund($order);
            $this->event($order, $customer, 'CANCELLED', '[Khách huỷ] '.$reason);
        }, 3);
    }

    public function approveCancellation(User $manager, int $orderId): void
    {
        DB::transaction(function () use ($manager, $orderId) {
            $order = $this->lockDelivery($orderId);
            $request = OrderCancellationRequest::where('order_id', $order->id)
                ->lockForUpdate()->firstOrFail();

            if (in_array($request->status, [
                OrderCancellationRequest::STATUS_APPROVED,
                OrderCancellationRequest::STATUS_REFUNDED,
            ], true)) return;

            abort_unless($request->status === OrderCancellationRequest::STATUS_PENDING, 409,
                'Yêu cầu hủy không còn chờ duyệt.');
            abort_unless($order->status === 'PENDING', 409,
                'Chỉ có thể duyệt hủy khi đơn chưa được nhà hàng xác nhận.');

            $order->items()->update([
                'status' => 'CANCELLED',
                'cancelled_by' => $manager->id,
                'cancellation_reason' => '[Quản lý duyệt yêu cầu khách] '.$request->reason,
            ]);
            $order->update([
                'status' => 'CANCELLED',
                'cancelled_by_user_id' => $manager->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $request->reason,
            ]);
            $request->update([
                'status' => OrderCancellationRequest::STATUS_APPROVED,
                'approved_by' => $manager->id,
                'approved_at' => now(),
            ]);

            app(DeliveryPricingService::class)->refund($order);
            app(\App\Services\VoucherService::class)->refund($order);
            $this->event($order, $manager, 'CANCEL_APPROVED', '[Quản lý duyệt hủy] '.$request->reason);
        }, 3);
    }

    public function confirmSandboxRefund(User $manager, int $orderId): void
    {
        DB::transaction(function () use ($manager, $orderId) {
            $order = $this->lockDelivery($orderId);
            $request = OrderCancellationRequest::where('order_id', $order->id)
                ->lockForUpdate()->firstOrFail();

            if ($request->status === OrderCancellationRequest::STATUS_REFUNDED) return;
            abort_unless($request->status === OrderCancellationRequest::STATUS_APPROVED, 409,
                'Yêu cầu hủy phải được duyệt trước khi xác nhận hoàn tiền.');
            abort_unless($order->status === 'CANCELLED', 409, 'Đơn chưa được hủy.');

            $invoice = $this->invoice($order);
            $payment = DB::table('payments')->where('invoice_id', $invoice->id)
                ->where('provider', 'vnpay')->where('status', 'SUCCESS')
                ->where('is_simulated', false)->lockForUpdate()->first();
            abort_unless($payment, 409, 'Không tìm thấy giao dịch VNPay đã thanh toán để hoàn tiền.');

            DB::table('payments')->where('id', $payment->id)->update([
                'status' => 'REFUNDED',
                'note' => 'VNPay Sandbox: quản lý xác nhận đã hoàn tiền.',
                'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update([
                'status' => 'REFUNDED',
                'paid_amount' => 0,
                'updated_at' => now(),
            ]);
            $request->update([
                'status' => OrderCancellationRequest::STATUS_REFUNDED,
                'refunded_by' => $manager->id,
                'refunded_at' => now(),
            ]);

            $this->event($order, $manager, 'REFUND_CONFIRMED', 'Đã xác nhận hoàn tiền VNPay Sandbox.');
            app(CustomerOrderNotificationService::class)->refundCompleted($order);
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Sự cố & từ chối                                                     */
    /* ------------------------------------------------------------------ */

    public function deliveryIssue(User $shipper, int $id, string $reason): void
    {
        DB::transaction(function () use ($shipper, $id, $reason) {
            $order = $this->lockDeliveryForShipper($id, $shipper->id);
            abort_unless($order->status === 'DELIVERING', 409, 'Đơn không đang giao.');
            $order->forceFill(['delivery_issue' => $reason])->save();
            $this->event($order, $shipper, 'DELIVERY_ISSUE', $reason);
        });
    }

    public function reject(User $actor, int $id, string $reason): void
    {
        DB::transaction(function () use ($actor, $id, $reason) {
            $order = $this->lockDelivery($id);
            if ($order->status === 'CANCELLED') return;
            abort_if($order->cancellationRequest()->whereIn('status', [
                OrderCancellationRequest::STATUS_PENDING,
                OrderCancellationRequest::STATUS_APPROVED,
            ])->exists(), 409, 'Đơn đang trong quy trình hủy và hoàn tiền.');
            abort_unless($order->status === 'PENDING', 409, 'Chỉ từ chối đơn chưa xác nhận.');
            $invoice = $this->invoice($order);
            $payments = DB::table('payments')->where('invoice_id', $invoice->id)->where('status', 'SUCCESS')->get();
            abort_if(DB::table('payments')->where('invoice_id', $invoice->id)->where('provider', 'vnpay')->where('status', 'PENDING')->exists(),
                409, 'Cần xác minh giao dịch VNPay đang chờ trước khi hủy đơn.');
            abort_if($payments->contains(fn ($p) => !$p->is_simulated), 409,
                'Đơn có tiền thực cần quy trình hoàn tiền riêng.');
            DB::table('payments')->where('invoice_id', $invoice->id)->where('status', 'SUCCESS')
                ->update(['status' => 'REFUNDED', 'updated_at' => now()]);
            DB::table('invoices')->where('id', $invoice->id)->update([
                'status' => $payments->isEmpty() ? 'FAILED' : 'REFUNDED',
                'paid_amount' => 0,
                'updated_at' => now(),
            ]);
            $order->items()->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            $order->update(['status' => 'CANCELLED']);
            app(DeliveryPricingService::class)->refund($order);
            app(\App\Services\VoucherService::class)->refund($order);
            $this->event($order, $actor, 'REJECTED', $reason);
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Khóa đơn ONLINE / TAKEAWAY (dùng cho thao tác của manager/cashier/khách).
     */
    private function lockDelivery(int $id, ?int $customerId = null): Order
    {
        $q = Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])->lockForUpdate();
        if ($customerId !== null) $q->where('customer_id', $customerId);
        return $q->findOrFail($id);
    }

    /**
     * Khóa đơn cho shipper (dùng cho pickedUp / arrived / delivered).
     * Shipper chỉ thấy đơn mình được bàn giao.
     */
    private function lockDeliveryForShipper(int $id, int $shipperId): Order
    {
        return Order::whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
            ->where('shipper_id', $shipperId)
            ->lockForUpdate()
            ->findOrFail($id);
    }
}
