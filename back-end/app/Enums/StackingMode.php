<?php

namespace App\Enums;

/**
 * Chế độ stacking của promotion trên một đơn hàng.
 *
 *  - STACK:      Áp TẤT CẢ promotion đủ điều kiện (mỗi cái trừ vào subtotal còn lại).
 *  - EXCLUSIVE:  Chỉ promotion có priority CAO NHẤT được áp, bỏ qua phần còn lại.
 *  - BEST_ONLY:  Trong NHÓM (cùng `priority`), chọn 1 cái giảm nhiều nhất.
 *
 * Chi tiết thuật toán xem back-end/PROMOTION_VOUCHER_PLAN.md — mục 5.2.
 */
enum StackingMode: string
{
    case STACK     = 'STACK';
    case EXCLUSIVE = 'EXCLUSIVE';
    case BEST_ONLY = 'BEST_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::STACK     => 'Cộng dồn nhiều khuyến mãi',
            self::EXCLUSIVE => 'Chỉ áp cái ưu tiên cao nhất',
            self::BEST_ONLY => 'Chọn 1 cái giảm nhiều nhất',
        };
    }
}