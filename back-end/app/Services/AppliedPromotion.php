<?php

namespace App\Services;

use App\Enums\PromotionKind;

/** 1 promotion đã được rule engine chọn để áp vào đơn. */
final class AppliedPromotion
{
    public function __construct(
        public readonly int $promotionId,
        public readonly string $slug,
        public readonly PromotionKind $kind,
        public readonly int $discountVnd,
    ) {}

    /** Format cho FE (kết quả preview). */
    public function toArray(): array
    {
        return [
            'promotion_id'   => $this->promotionId,
            'slug'           => $this->slug,
            'kind'           => $this->kind->value,
            'discount'       => $this->discountVnd,
            'discount_label' => number_format($this->discountVnd, 0, ',', '.') . 'đ',
        ];
    }
}