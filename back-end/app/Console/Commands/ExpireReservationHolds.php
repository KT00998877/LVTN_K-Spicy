<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireReservationHolds extends Command
{
    protected $signature = 'reservations:expire-holds';
    protected $description = 'Release confirmed reservations after the arrival grace period';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now(config('reservations.timezone'))
            ->subMinutes(config('reservations.hold_minutes'));
        $date = $cutoff->toDateString();

        Reservation::where('status', 'CONFIRMED')
            ->where(function ($query) use ($date, $cutoff) {
                $query->whereDate('reserved_date', '<', $date)
                    ->orWhere(function ($query) use ($date, $cutoff) {
                        $query->whereDate('reserved_date', $date)
                            ->where('reserved_time', '<=', $cutoff->toTimeString());
                    });
            })
            ->update(['status' => 'NO_SHOW']);

        return self::SUCCESS;
    }
}
