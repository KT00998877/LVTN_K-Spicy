<?php

namespace App\Services;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;

class LiveChatBroadcaster
{
    public function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (BroadcastException $exception) {
            Log::warning('Live chat realtime unavailable; message remains available via API.', [
                'event' => $event::class,
                'error_type' => $exception::class,
            ]);
        }
    }
}
