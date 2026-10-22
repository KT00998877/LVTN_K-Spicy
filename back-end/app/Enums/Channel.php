<?php

namespace App\Enums;

/**
 * Kênh bán hàng — dùng cho điều kiện promotion và voucher.
 *
 * Đồng bộ với giá trị `order_type` của bảng `orders` (siêu thị nhỏ: ONLINE / DINE_IN / TAKEAWAY).
 *
 *  - ONLINE:   Khách đặt qua web/app, giao tận nơi (delivery).
 * - DINE_IN:  Khách ăn tại bàn nhà hàng.
 * - TAKEAWAY: Khách đến lấy mang đi (pickup).
 */
enum Channel: string
{
    case ONLINE  = 'ONLINE';
    case DINE_IN = 'DINE_IN';
    case TAKEAWAY = 'TAKEAWAY';

    public function label(): string
    {
        return match ($this) {
            self::ONLINE   => 'Đặt online (giao tận nơi)',
            self::DINE_IN  => 'Ăn tại nhà hàng',
            self::TAKEAWAY => 'Mang đi',
        };
    }

    /** @return list<string> — danh sách value, dùng cho validation. */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}