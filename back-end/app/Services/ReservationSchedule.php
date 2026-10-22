<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ReservationSchedule
{
    /** Inputs must already have passed strict Y-m-d / H:i format validation. */
    public static function errors(string $date, string $time): array
    {
        $timezone = config('reservations.timezone');
        $now = CarbonImmutable::now($timezone);
        $at = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, $timezone);
        if ($date < $now->toDateString()) {
            return ['reserved_date' => ['Không thể đặt bàn cho ngày đã qua.']];
        }
        if ($at->lessThanOrEqualTo($now)) {
            return ['reserved_time' => ['Thời gian đặt bàn phải lớn hơn thời gian hiện tại.']];
        }
        if ($at->lessThan($now->addMinutes(config('reservations.min_advance_minutes')))) {
            return ['reserved_time' => ['Vui lòng đặt bàn trước ít nhất '.config('reservations.min_advance_minutes').' phút.']];
        }
        if ($time < config('reservations.opens_at') || $time > config('reservations.closes_at')) {
            return ['reserved_time' => ['Nhà hàng nhận đặt bàn từ '.config('reservations.opens_at').' đến '.config('reservations.closes_at').'.']];
        }
        return [];
    }
}
