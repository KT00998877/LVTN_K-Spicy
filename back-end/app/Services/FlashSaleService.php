<?php

namespace App\Services;

use Carbon\CarbonInterface;

class FlashSaleService
{
    public function activeFor(object $item, ?CarbonInterface $at = null): ?array
    {
        $at ??= now();
        $discount = (int) ($item->flash_sale_discount ?? 0);
        $price = (int) round((float) ($item->price ?? 0));
        $starts = $item->flash_sale_starts_at ?? null;
        $ends = $item->flash_sale_ends_at ?? null;
        if ($discount <= 0 || $discount >= $price || !$starts || !$ends) return null;

        $startsAt = $starts instanceof CarbonInterface ? $starts : \Carbon\Carbon::parse($starts);
        $endsAt = $ends instanceof CarbonInterface ? $ends : \Carbon\Carbon::parse($ends);
        if ($at->lt($startsAt) || $at->gte($endsAt)) return null;

        return [
            'original_price' => $price,
            'discount' => $discount,
            'price' => $price - $discount,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ];
    }

    public function priceFor(object $item, ?CarbonInterface $at = null): int
    {
        return $this->activeFor($item, $at)['price'] ?? (int) round((float) $item->price);
    }
}
