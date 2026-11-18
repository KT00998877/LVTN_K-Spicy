<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('vnpay:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('chatbot:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('marketing:send-campaigns')->everyMinute()->withoutOverlapping();
Schedule::command('flash-sale:notify')->everyMinute()->withoutOverlapping();
Schedule::command('reservations:remind')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
