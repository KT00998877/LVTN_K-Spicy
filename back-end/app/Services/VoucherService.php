<?php

namespace App\Services;

use App\Models\{Order, User, Voucher, VoucherUsage};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * VoucherService — áp dụng voucher vào đơn hàng.
 *
 * Hợp đồng:
 *  - `preview($user, $code, $subtotal)`:
 *      Trả về preview (không ghi DB) cho FE hiển thị trước khi đặt hàng.
 *      Ném ValidationException nếu không hợp lệ.
 *
 *  - `apply($user, $order, $code)`:
 *      Áp dụng voucher cho order (đã có subtotal), trả về số tiền giảm.
 *      Được gọi bên trong transaction ở CartService::checkout().
 *      Ghi voucher_usages + tăng used_count.
 *
 *  - `refund($order)`:
 *      Hoàn lượt dùng nếu đơn bị huỷ (gọi khi order.status chuyển CANCELLED).
 *
 * Quy tắc nghiệp vụ (BR-VOUCHER-*):
 *  - BR-VOUCHER-01: 1 đơn chỉ áp 1 voucher (đảm bảo bằng unique index trên voucher_usages.order_id).
 *  - BR-VOUCHER-02: voucher PERCENT/FIXED được tính trên subtotal (chưa gồm ship).
 *  - BR-VOUCHER-03: giảm tối đa bằng subtotal (không âm).
 *  - BR-VOUCHER-04: voucher PERCENT có trần max_discount_amount.
 *  - BR-VOUCHER-05: chỉ áp dụng khi subtotal ≥ min_order_subtotal.
 *  - BR-VOUCHER-06: không dùng khi !is_active / ngoài cửa sổ / hết quota / quá per-user.
 */
class VoucherService
{
    /**
     * Preview cho FE — không ghi DB.
     *
     * @return array{code:string,name:string,type:string,value:float,discount:int,min_order_subtotal:int}
     */
    public function preview(User $user, string $code, float $subtotal): array
    {
        $voucher = $this->findUsable($user, $code, $subtotal);

        $discount = $voucher->calculateDiscount($subtotal);

        return [
            'code'             => $voucher->code,
            'name'             => $voucher->name,
            'type'             => $voucher->type,
            'value'            => (float) $voucher->value,
            'discount'         => $discount,
            'min_order_subtotal' => (int) round((float) $voucher->min_order_subtotal),
        ];
    }

    /**
     * Áp dụng voucher cho 1 order đã có subtotal.
     * Caller (CartService::checkout) phải giữ transaction.
     *
     * @return int số tiền giảm (VND) đã cộng vào order.discount_amount
     */
    public function apply(User $user, Order $order, string $code): int
    {
        $voucher = $this->findUsable($user, $code, (float) $order->subtotal);
        $discount = $voucher->calculateDiscount((float) $order->subtotal);

        // Ghi log + tăng used_count.
        VoucherUsage::create([
            'voucher_id'      => $voucher->id,
            'user_id'         => $user->id,
            'order_id'        => $order->id,
            // Lưu VND (không phải cents) — đồng bộ với orders.discount_amount.
            'discount_amount' => number_format($discount, 2, '.', ''),
        ]);
        $voucher->increment('used_count');

        // Ghi event để tra cứu sau.
        app(OnlineOrderService::class)->event($order, $user, 'VOUCHER_APPLIED',
            sprintf('Áp voucher %s (-%sđ)', $voucher->code, number_format($discount))
        );

        return $discount;
    }

    /**
     * Hoàn lượt dùng (khi đơn CANCELLED). Idempotent — không lỗi nếu không có usage.
     */
    public function refund(Order $order): void
    {
        $usage = VoucherUsage::where('order_id', $order->id)->first();
        if (!$usage) return;
        DB::table('vouchers')->where('id', $usage->voucher_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
        $usage->delete();
    }

    /**
     * Lấy voucher + validate. Ném ValidationException nếu không dùng được.
     */
    private function findUsable(User $user, string $code, float $subtotal): Voucher
    {
        $code = strtoupper(trim($code));
        $voucher = Voucher::where('code', $code)->first();
        if (!$voucher) {
            throw ValidationException::withMessages(['voucher_code' => 'Mã giảm giá không tồn tại.']);
        }
        if (!$voucher->is_active) {
            throw ValidationException::withMessages(['voucher_code' => 'Mã giảm giá đang tạm ngưng.']);
        }
        if (!$voucher->isWithinWindow()) {
            throw ValidationException::withMessages(['voucher_code' => 'Mã giảm giá chưa đến hoặc đã hết hạn.']);
        }
        if (!$voucher->hasGlobalQuota()) {
            throw ValidationException::withMessages(['voucher_code' => 'Mã giảm giá đã hết lượt sử dụng.']);
        }
        if ($voucher->usage_limit_per_user !== null) {
            $used = VoucherUsage::where('voucher_id', $voucher->id)
                ->where('user_id', $user->id)->count();
            if ($used >= $voucher->usage_limit_per_user) {
                throw ValidationException::withMessages(['voucher_code' => 'Bạn đã dùng hết số lượt cho mã này.']);
            }
        }
        if ((int) round($subtotal) < (int) round((float) $voucher->min_order_subtotal)) {
            throw ValidationException::withMessages([
                'voucher_code' => sprintf(
                    'Đơn hàng cần tối thiểu %sđ để áp dụng mã này.',
                    number_format((float) $voucher->min_order_subtotal)
                ),
            ]);
        }
        return $voucher;
    }
}