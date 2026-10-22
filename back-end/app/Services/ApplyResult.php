<?php

namespace App\Services;

/**
 * Output DTO của PromotionRuleEngine::resolve().
 */
final class ApplyResult
{
    /**
     * @param  list<AppliedPromotion>  $applied
     */
    public function __construct(
        public readonly array $applied,
        public readonly int $totalDiscountVnd,
        public readonly int $newSubtotalVnd,
    ) {}

    /** True nếu có ít nhất 1 promotion được áp. */
    public function hasAny(): bool
    {
        return !empty($this->applied);
    }

    /** Format cho FE: danh sách + tổng. */
    public function toArray(): array
    {
        return [
            'applied'          => array_map(fn (AppliedPromotion $a) => $a->toArray(), $this->applied),
            'total_discount'   => $this->totalDiscountVnd,
            'new_subtotal'     => $this->newSubtotalVnd,
            'has_discount'     => $this->hasAny(),
        ];
    }
}