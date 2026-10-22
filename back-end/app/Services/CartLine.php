<?php

namespace App\Services;

/** 1 dòng trong giỏ hàng — đầu vào cho PromotionRuleEngine. */
final class CartLine
{
    public function __construct(
        public readonly int $menuItemId,
        public readonly int $categoryId,
        public readonly int $quantity,
        /** Đơn giá đã gồm topping (VND). */
        public readonly int $unitPriceVnd,
    ) {}
}