<?php

return [
    // Restaurant wall time; application/database audit timestamps remain UTC.
    'timezone' => env('RESERVATION_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'opens_at' => '10:00',
    'closes_at' => '22:00',
    'min_advance_minutes' => 60,
    'max_guests' => 20,
    'max_note_length' => 1000,
];
