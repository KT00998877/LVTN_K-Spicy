<?php

namespace App\Services;

use App\Models\{Order, Promotion, PromotionUsage, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PromotionService — điều phối khuyến mãi tự động.
 *
 * Phân vai với PromotionRuleEngine:
 *  - PromotionRuleEngine: pure logic, không DB.
 *  - PromotionService:    load promotion từ DB, áp dụng vào order, ghi usage + refund.
 *
 * Phân vai với VoucherService:
 *  - VoucherService: khách nhập mã, 1 đơn chỉ 1, kiểm tra quota theo user.
 *  - PromotionService: hệ thống TỰ áp, 1 đơn có thể NHIỀU (stacking), quota theo promotion.
 *
 * Quy tắc BR-PROMO-* (chi tiết trong rule engine):
 *  - BR-PROMO-01: 1 đơn có thể áp NHIỀU promotion (theo stacking_mode + priority).
 *  - BR-PROMO-02: priority cao áp trước; cùng priority → cái giảm nhiều thắng.
 *  - BR-PROMO-03: tăng used_count có WHERE used_count < usage_limit để chặn race.
 *  - BR-PROMO-04: refund promotion khi đơn CANCELLED — hoàn used_count, xoá usage log.
 *  - BR-PROMO-05: ghi log event 'PROMOTION_APPLIED' để tra cứu.
 */
class PromotionService
{
    public function __construct(private readonly PromotionRuleEngine $engine) {}

    /**
     * Trả về ApplyResult cho 1 cart + context — KHÔNG ghi DB.
     * Dùng cho /quote và /preview.
     */
    public function preview(CartContext $context): ApplyResult
    {
        $promotions = $this->loadEligiblePromotions();
        return $this->engine->resolve($promotions, $context);
    }

    /**
     * Áp dụng promotion cho 1 order ĐÃ CÓ items (cart snapshot).
     * Caller (CartService::checkout) phải giữ transaction.
     *
     * @param  CartContext  $context  đã có items + subtotal + channel + user
     * @return int tổng số tiền giảm (VND) đã cộng vào order.discount_amount
     */
    public function apply(CartContext $context, int $orderId): int
    {
        $result = $this->preview($context);

        foreach ($result->applied as $applied) {
            $this->recordUsage($applied, (int) ($context->userId ?? 0), $orderId);
        }

        return $result->totalDiscountVnd;
    }

    /**
     * Hoàn lượt dùng (khi đơn CANCELLED). Idempotent.
     */
    public function refund(Order $order): void
    {
        $usages = PromotionUsage::where('order_id', $order->id)->get();
        foreach ($usages as $usage) {
            DB::table('promotions')->where('id', $usage->promotion_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
            $usage->delete();
        }
    }

    /**
     * Lấy tất cả promotion đang chạy — eager load items để rule engine tra phạm vi.
     */
    private function loadEligiblePromotions(): \Illuminate\Database\Eloquent\Collection
    {
        return Promotion::query()
            ->with('items')
            ->live()
            ->get();
    }

    private function recordUsage(AppliedPromotion $applied, int $userId, int $orderId): void
    {
        // Tăng used_count có điều kiện (chống race vượt quota).
        $affected = DB::table('promotions')
            ->where('id', $applied->promotionId)
            ->where(function ($q) {
                $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->increment('used_count');

        if ($affected === 0) {
            // Hết quota trong lúc đang xử lý — bỏ qua, không ghi usage.
            return;
        }

        PromotionUsage::create([
            'promotion_id'    => $applied->promotionId,
            'user_id'         => $userId,
            'order_id'        => $orderId,
            'discount_amount' => number_format($applied->discountVnd, 2, '.', ''),
        ]);

        // Ghi event để tra cứu (nếu có user).
        $order = \App\Models\Order::find($orderId);
        $user  = $userId > 0 ? \App\Models\User::find($userId) : null;
        if ($order && $user) {
            app(OnlineOrderService::class)->event(
                $order, $user, 'PROMOTION_APPLIED',
                sprintf('Áp khuyến mãi %s (-%sđ)', $applied->slug, number_format($applied->discountVnd))
            );
        }
    }
}