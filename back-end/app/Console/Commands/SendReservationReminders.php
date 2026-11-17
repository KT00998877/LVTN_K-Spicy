<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendReservationReminders extends Command
{
    protected $signature = 'reservations:remind';
    protected $description = 'Notify signed-in customers before confirmed reservations';

    public function handle(ReservationNotificationService $notifications): int
    {
        $now = CarbonImmutable::now(config('reservations.timezone'));
        $from = $now->addMinutes(60);
        $until = $now->addMinutes(70);
        Reservation::where('status', 'CONFIRMED')->whereNotNull('customer_id')
            ->whereDate('reserved_date', '>=', $from->toDateString())
            ->whereDate('reserved_date', '<=', $until->toDateString())
            ->chunkById(100, function ($rows) use ($from, $until, $notifications): void {
                foreach ($rows as $row) {
                    $at = CarbonImmutable::parse($row->reserved_date->format('Y-m-d').' '.$row->reserved_time, config('reservations.timezone'));
                    if ($at->betweenIncluded($from, $until)) $notifications->remind($row);
                }
            });
        return self::SUCCESS;
    }
}
