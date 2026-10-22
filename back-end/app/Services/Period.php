<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Period — cặp (from, to) inclusive đầu ngày → cuối ngày theo timezone VN.
 *
 *  - `from`: startOfDay ở Asia/Ho_Chi_Minh (dùng để query DB).
 *  - `to`:   endOfDay ở Asia/Ho_Chi_Minh.
 *  - `previous()`: cùng độ dài, ngay trước current.
 *
 *  Lưu ý: Project lưu datetime theo `app.timezone` (UTC). Eloquent sẽ convert
 *  khi binding tham số whereBetween. Để đơn giản và tránh bug timezone khi
 *  test ở SQLite (lưu thẳng string), Period giữ boundaries ở Asia/Ho_Chi_Minh.
 *  Carbon sẽ convert sang UTC khi query (vì casts của các cột datetime là
 *  theo app.timezone).
 */
class Period
{
    public readonly Carbon $from;
    public readonly Carbon $to;

    public function __construct(Carbon $from, Carbon $to)
    {
        $this->from = $from->copy()->setTimezone('Asia/Ho_Chi_Minh');
        $this->to   = $to->copy()->setTimezone('Asia/Ho_Chi_Minh');
    }

    public function previous(): self
    {
        $days = (int) max(1, $this->from->diffInDays($this->to) + 1);
        $from = $this->from->copy()->subDays($days)->startOfDay();
        $to   = $this->from->copy()->subDay()->endOfDay();
        return new self($from, $to);
    }

    public function label(): string
    {
        return $this->from->toDateString() . ' → ' . $this->to->toDateString();
    }

    /**
     * Trả về cặp [from_utc, to_utc] dưới dạng string để bind vào whereBetween.
     * Ở production (MySQL UTC) sẽ match đúng; ở SQLite test env cũng OK vì
     * datetime cột được bind theo chuỗi và so sánh lexicographically.
     *
     *  Lưu ý: ta convert sang UTC để DB lưu trữ đúng ngữ nghĩa.
     */
    public function utcRange(): array
    {
        return [
            $this->from->copy()->setTimezone('UTC')->toDateTimeString(),
            $this->to->copy()->setTimezone('UTC')->toDateTimeString(),
        ];
    }
}
