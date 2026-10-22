<?php

namespace App\Services;

use App\Enums\{Channel, PromotionKind, StackingMode};
use App\Models\{Promotion, PromotionItem};
use Illuminate\Support\Collection;

/**
 * PromotionRuleEngine — pure logic: nhận cart + context → trả danh sách promotion được áp.
 *
 * Tại sao tách thành class riêng (không gộp vào PromotionService)?
 *  - Dễ unit-test (không cần DB, không cần transaction).
 *  - Có thể tái sử dụng cho cả checkout, preview, /quote, /preview admin.
 *
 * Đơn vị tiền tệ trong rule engine:
 *  - Input: subtotal/cart items là VND (major units, integer) — đồng bộ VoucherService.
 *  - Output: discount_amount trên mỗi `applied` cũng là VND.
 *  Lý do: VoucherService đã làm vậy, các endpoint FE đang so sánh theo VND.
 *  CartService checkout sẽ convert qua `Money::decimal()` trước khi gọi.
 *
 * Thuật toán (xem back-end/PROMOTION_VOUCHER_PLAN.md mục 5):
 *  1. Lọc active + trong window + còn quota.
 *  2. Match điều kiện (min_subtotal, ngày/giờ, channel, loyalty, first_order_only).
 *  3. Match phạm vi (ALL/CATEGORY/MENU_ITEM).
 *  4. Sort theo priority DESC, rồi discount DESC.
 *  5. Stacking: STACK (áp tất cả, trừ dồn vào subtotal còn lại);
 *               EXCLUSIVE (chỉ priority cao nhất);
 *               BEST_ONLY (cùng priority → chọn cái giảm nhiều nhất).
 *  6. Output: danh sách applied + tổng discount.
 */
class PromotionRuleEngine
{
    /**
     * @param  Collection<int, Promotion>  $promotions
     * @param  CartContext                  $context
     * @return ApplyResult
     */
    public function resolve(Collection $promotions, CartContext $context): ApplyResult
    {
        $eligible = $this->filterEligible($promotions, $context);
        $eligible = $this->resolveByStacking($eligible, $context);

        $applied = [];
        $totalDiscount = 0;
        $runningSubtotal = $context->subtotal;

        foreach ($eligible as $promo) {
            $discount = $this->calculateForPromotion($promo, $runningSubtotal, $context);
            if ($discount <= 0) continue;

            $applied[] = new AppliedPromotion(
                promotionId: $promo->id,
                slug: $promo->slug,
                kind: $promo->kind,
                discountVnd: $discount,
            );

            $totalDiscount += $discount;
            $runningSubtotal = max(0, $runningSubtotal - $discount);
        }

        return new ApplyResult(
            applied: $applied,
            totalDiscountVnd: $totalDiscount,
            newSubtotalVnd: max(0, $context->subtotal - $totalDiscount),
        );
    }

    /* ============================================================== */
    /* Internal steps                                                  */
    /* ============================================================== */

    /**
     * @param  Collection<int, Promotion>  $promotions
     * @return Collection<int, Promotion>
     */
    private function filterEligible(Collection $promotions, CartContext $ctx): Collection
    {
        return $promotions
            ->filter(fn (Promotion $p) => $p->is_active)
            ->filter(fn (Promotion $p) => $p->isWithinWindow())
            ->filter(fn (Promotion $p) => $p->hasGlobalQuota())
            ->filter(fn (Promotion $p) => $this->matchesConditions($p, $ctx))
            ->filter(fn (Promotion $p) => $this->matchesScope($p, $ctx))
            ->sortByDesc(fn (Promotion $p) => [(int) $p->priority, $p->id])
            ->values();
    }

    private function matchesConditions(Promotion $p, CartContext $ctx): bool
    {
        $cond = $p->conditions ?? [];
        if (empty($cond)) return true;

        // min_subtotal — VND.
        if (isset($cond['min_subtotal']) && $ctx->subtotal < (int) $cond['min_subtotal']) {
            return false;
        }

        // Ngày trong tuần (0=CN..6=Thứ 7 theo Carbon::dayOfWeek).
        if (!empty($cond['days_of_week']) && is_array($cond['days_of_week'])) {
            if (!in_array($ctx->now->dayOfWeek, array_map('intval', $cond['days_of_week']), true)) {
                return false;
            }
        }

        // Khung giờ HH:MM (so với hour:minute của now).
        if (!empty($cond['time_from']) || !empty($cond['time_to'])) {
            $minutes = (int) $ctx->now->format('Hi');
            if (!empty($cond['time_from']) && $minutes < $this->hmToMinutes($cond['time_from'])) {
                return false;
            }
            if (!empty($cond['time_to']) && $minutes > $this->hmToMinutes($cond['time_to'])) {
                return false;
            }
        }

        // Kênh bán.
        if (!empty($cond['channels']) && is_array($cond['channels'])) {
            if (!in_array($ctx->channel->value, array_map('strval', $cond['channels']), true)) {
                return false;
            }
        }

        // Khách mới (chưa có đơn nào).
        if (!empty($cond['first_order_only']) && $ctx->userTotalOrders > 0) {
            return false;
        }

        // Hạng loyalty.
        if (!empty($cond['loyalty_tier_ids']) && is_array($cond['loyalty_tier_ids'])) {
            if ($ctx->loyaltyTierId === null || !in_array($ctx->loyaltyTierId, array_map('intval', $cond['loyalty_tier_ids']), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra phạm vi áp dụng (ALL / CATEGORY / MENU_ITEM).
     * Một promotion được coi là "có áp được" nếu có ÍT NHẤT 1 món trong giỏ khớp phạm vi.
     * Lượng giảm cụ thể (PERCENT/FIXED...) sẽ tính theo phần khớp trong calculateForPromotion().
     */
    private function matchesScope(Promotion $p, CartContext $ctx): bool
    {
        $config = $p->config ?? [];
        $applies = strtoupper((string) ($config['applies_to'] ?? 'ALL'));
        if ($applies === 'ALL') return true;

        // Lấy danh sách item_type + item_id mà promotion này áp (đã resolve qua relation).
        $scopeIds = $p->items
            ->map(fn ($pi) => ['type' => $pi->item_type, 'id' => (int) $pi->item_id])
            ->all();

        if (empty($scopeIds)) return false;

        foreach ($ctx->items as $item) {
            foreach ($scopeIds as $s) {
                if ($s['type'] === PromotionItem::TYPE_MENU_ITEM && $s['id'] === $item->menuItemId) {
                    return true;
                }
                if ($s['type'] === PromotionItem::TYPE_CATEGORY && $s['id'] === $item->categoryId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param  Collection<int, Promotion>  $sorted  đã sort priority DESC
     * @return Collection<int, Promotion>
     */
    private function resolveByStacking(Collection $sorted, CartContext $ctx): Collection
    {
        if ($sorted->isEmpty()) return $sorted;

        $mode = $sorted->first()->stacking_mode ?? StackingMode::STACK;
        // Lưu ý: cùng một nhóm áp dụng chung stacking_mode (UI khuyến nghị 1 mode / nhóm).
        // Nếu phát hiện khác mode, fallback về STACK cho an toàn.
        $allSame = $sorted->every(fn (Promotion $p) => $p->stacking_mode === $mode);
        if (!$allSame) $mode = StackingMode::STACK;

        return match ($mode) {
            StackingMode::EXCLUSIVE => $sorted->take(1)->values(),
            StackingMode::BEST_ONLY => $this->pickBestInGroup($sorted, $ctx),
            default                 => $sorted, // STACK: giữ nguyên thứ tự.
        };
    }

    /**
     * BEST_ONLY: nhóm các promotion có cùng priority, trong mỗi nhóm chọn 1 cái giảm nhiều nhất.
     *
     * @param  Collection<int, Promotion>  $sorted
     * @return Collection<int, Promotion>
     */
    private function pickBestInGroup(Collection $sorted, CartContext $ctx): Collection
    {
        $groups = [];
        foreach ($sorted as $p) {
            $key = (int) $p->priority;
            $groups[$key][] = $p;
        }

        $result = [];
        foreach ($groups as $group) {
            $best = null;
            $bestDiscount = 0;
            foreach ($group as $p) {
                $d = $this->calculateForPromotion($p, $ctx->subtotal, $ctx);
                if ($d > $bestDiscount) {
                    $best = $p;
                    $bestDiscount = $d;
                }
            }
            if ($best !== null) $result[] = $best;
        }
        return collect($result)->sortByDesc(fn (Promotion $p) => [(int) $p->priority, $p->id])->values();
    }

    /**
     * Tính số tiền giảm (VND) của 1 promotion cho 1 cart/subtotal cụ thể.
     * Lưu ý: PERCENT_OFF / FIXED_OFF dùng `runningSubtotal` (sau khi trừ promotion trước đó).
     *         BXGY / COMBO tính trên `originalSubtotal` (subtotal gốc của cart) để đảm bảo số
     *         lượng "mua X tặng Y" / "combo bundle" là đếm trên đơn gốc.
     */
    private function calculateForPromotion(Promotion $p, int $runningSubtotal, CartContext $ctx): int
    {
        $config = $p->config ?? [];

        return match ($p->kind) {
            PromotionKind::PERCENT_OFF => $this->calcPercentOff($config, $runningSubtotal),
            PromotionKind::FIXED_OFF   => $this->calcFixedOff($config, $runningSubtotal),
            PromotionKind::BXGY        => $this->calcBxgy($p, $config, $ctx),
            PromotionKind::COMBO       => $this->calcCombo($p, $config, $ctx),
            default                    => 0,
        };
    }

    private function calcPercentOff(array $config, int $subtotal): int
    {
        if ($subtotal <= 0) return 0;
        $percent = (float) ($config['value'] ?? 0);
        $discount = (int) floor($subtotal * $percent / 100);
        if (!empty($config['max_discount'])) {
            $discount = min($discount, (int) $config['max_discount']);
        }
        return max(0, min($discount, $subtotal));
    }

    private function calcFixedOff(array $config, int $subtotal): int
    {
        if ($subtotal <= 0) return 0;
        $value = (int) ($config['value'] ?? 0);
        return max(0, min($value, $subtotal));
    }

    /**
     * BXGY: đếm món thuộc get_item_ids × discount_percent%.
     * Ví dụ: buy_qty=2, get_qty=1, get_item_ids=[A], discount_percent=100%
     *   → cứ 2 phần món A → tặng 1 phần món A (giảm 100% giá 1 phần).
     */
    private function calcBxgy(Promotion $p, array $config, CartContext $ctx): int
    {
        $buyQty = max(1, (int) ($config['buy_qty'] ?? 0));
        $getQty = max(1, (int) ($config['get_qty'] ?? 0));
        $discountPct = (float) ($config['discount_percent'] ?? 0);

        // Lấy giá món được tặng (ưu tiên override trong promotion_items nếu có).
        $getItemIds = $p->items
            ->filter(fn ($pi) => $pi->item_type === PromotionItem::TYPE_MENU_ITEM)
            ->pluck('item_id')
            ->map(fn ($x) => (int) $x)
            ->all();

        if (empty($getItemIds) || $discountPct <= 0) return 0;

        $totalGetQty = 0;
        foreach ($ctx->items as $item) {
            if (!in_array($item->menuItemId, $getItemIds, true)) continue;
            $groups = (int) floor($item->quantity / ($buyQty + $getQty));
            $totalGetQty += $groups * $getQty;
        }
        if ($totalGetQty === 0) return 0;

        // Giá trung bình 1 phần của món tặng (đơn giản: lấy min để khuyến mãi không vượt khả thi).
        $prices = [];
        foreach ($ctx->items as $item) {
            if (in_array($item->menuItemId, $getItemIds, true)) {
                $prices[] = $item->unitPriceVnd;
            }
        }
        if (empty($prices)) return 0;
        $unitPrice = min($prices);

        $discount = (int) round($totalGetQty * $unitPrice * $discountPct / 100);
        return max(0, $discount);
    }

    /**
     * COMBO: bundle gồm N món với giá cố định.
     * Tính số combo khả dụng = min(số lượng từng món trong bundle / qty từng món).
     * Discount = (subtotal các món trong bundle ở giá gốc) - (bundle_price × số combo).
     */
    private function calcCombo(Promotion $p, array $config, CartContext $ctx): int
    {
        $bundle = $config['bundle_items'] ?? [];
        $bundlePrice = (int) ($config['bundle_price'] ?? 0);
        if (empty($bundle) || $bundlePrice <= 0) return 0;

        $required = [];
        foreach ($bundle as $entry) {
            $required[(int) $entry['item_id']] = (int) ($entry['qty'] ?? 1);
        }
        if (empty($required)) return 0;

        $comboCount = PHP_INT_MAX;
        $originalBundleValue = 0;
        foreach ($required as $itemId => $qty) {
            $have = 0;
            $unitPrice = 0;
            foreach ($ctx->items as $item) {
                if ($item->menuItemId === $itemId) {
                    $have += $item->quantity;
                    $unitPrice = $item->unitPriceVnd;
                }
            }
            if ($have < $qty) {
                $comboCount = 0;
                break;
            }
            $comboCount = min($comboCount, intdiv($have, $qty));
            $originalBundleValue += $qty * $unitPrice;
        }
        if ($comboCount <= 0 || $comboCount === PHP_INT_MAX) return 0;

        $discount = $originalBundleValue * $comboCount - $bundlePrice * $comboCount;
        return max(0, $discount);
    }

    private function hmToMinutes(string $hm): int
    {
        $parts = explode(':', $hm);
        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }
}