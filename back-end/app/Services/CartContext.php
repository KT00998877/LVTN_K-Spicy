<?php

namespace App\Services;

use App\Enums\Channel;
use App\Models\PromotionItem;

/**
 * Input DTO cho PromotionRuleEngine — truyền ngữ cảnh giỏ hàng cần để quyết định promotion nào áp được.
 *
 * Tất cả tiền tệ ở đây là VND (major units, integer) — khớp với VoucherService.
 * CartService::checkout() chịu trách nhiệm convert từ minor units (cents) sang VND trước khi gọi.
 *
 * @property-read list<CartLine> $items
 */
final class CartContext
{
    /**
     * @param  list<CartLine>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $subtotal,
        public readonly Channel $channel,
        public readonly \Carbon\CarbonInterface $now,
        public readonly int $userTotalOrders = 0,
        public readonly ?int $loyaltyTierId = null,
        public readonly ?int $userId = null,
    ) {}

    /** Convenience: xây từ Cart model (nếu có) — dùng cho /quote và /preview. */
    public static function fromCart(\App\Models\Cart $cart, Channel $channel, int $subtotalVnd): self
    {
        $lines = [];
        foreach ($cart->items as $ci) {
            $menuItem = $ci->menuItem;
            if (!$menuItem) continue;
            $unitPrice = Money::minor($menuItem->price);
            foreach ($ci->toppingRelations as $tr) {
                $unitPrice += $tr->topping ? Money::minor($tr->topping->price) : 0;
            }
            $lines[] = new CartLine(
                menuItemId: (int) $menuItem->id,
                categoryId: (int) ($menuItem->category_id ?? 0),
                quantity: (int) $ci->quantity,
                unitPriceVnd: $unitPrice,
            );
        }
        return new self(
            items: $lines,
            subtotal: $subtotalVnd,
            channel: $channel,
            now: now(),
            userId: (int) $cart->user_id,
        );
    }
}