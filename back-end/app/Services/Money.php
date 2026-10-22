<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Integer minor units for exact arithmetic with the existing DECIMAL(...,2) schema. */
final class Money
{
    public const MAX = 99999999999999;

    public static function minor(string|int|float $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Số tiền không hợp lệ, tối đa hai chữ số thập phân.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        return self::bound((int) $whole * 100 + (int) str_pad($fraction, 2, '0'));
    }

    public static function bound(int $amount): int
    {
        if ($amount < 0 || $amount > self::MAX) {
            throw ValidationException::withMessages(['amount' => 'Tổng tiền vượt giới hạn cho phép.']);
        }
        return $amount;
    }

    public static function decimal(int $amount): string
    {
        self::bound($amount);
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
