<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatbotController;
use Tests\TestCase;

class ReservationQuickActionTest extends TestCase
{
    public function test_only_valid_booking_hints_cross_the_chat_boundary(): void
    {
        $method = new \ReflectionMethod(ChatbotController::class, 'sanitizeQuickAction');
        $controller = app(ChatbotController::class);
        $result = $method->invoke($controller, [
            'type' => 'reservation_form', 'prefill' => [
                'date' => '2026-02-30', 'time' => '19:15', 'guest_count' => 200, 'location' => 'VIP',
            ],
        ]);
        $this->assertSame(['time' => '19:15'], $result['prefill']);
        $this->assertNull($method->invoke($controller, ['type' => 'unknown']));
    }
}
