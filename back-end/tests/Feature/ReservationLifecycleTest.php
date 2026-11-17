<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Table;
use App\Models\User;
use App\Services\StaffTableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_no_show_requires_the_thirty_minute_hold_to_end(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 18:15', config('reservations.timezone')));
        $reservation = $this->reservation('2026-11-20', '18:00');
        $service = app(StaffTableService::class);
        try {
            $service->updateReservationStatus($reservation->id, 'NO_SHOW');
            $this->fail('Early no-show should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 18:30', config('reservations.timezone')));
        $this->assertSame('NO_SHOW', $service->updateReservationStatus($reservation->id, 'NO_SHOW')['status']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'NO_SHOW']);
    }

    public function test_reminder_is_sent_once_for_confirmed_reservation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 17:00', config('reservations.timezone')));
        $reservation = $this->reservation('2026-11-20', '18:05');
        $this->artisan('reservations:remind')->assertExitCode(0);
        $this->artisan('reservations:remind')->assertExitCode(0);
        $this->assertSame(['RESERVATION_REMINDER:'.$reservation->id.':20261120:18:05'],
            DB::table('notifications')->where('notifiable_id', $reservation->customer_id)->pluck('deduplication_key')->all());
    }

    private function reservation(string $date, string $time): Reservation
    {
        $customer = User::factory()->create(['is_active' => true]);
        $table = Table::create(['code' => 'LIFE-01', 'capacity' => 4, 'status' => 'AVAILABLE', 'is_active' => true]);
        return Reservation::create([
            'customer_name' => $customer->name, 'customer_phone' => '0901234567',
            'customer_id' => $customer->id, 'table_id' => $table->id, 'guest_count' => 2,
            'reserved_date' => $date, 'reserved_time' => $time, 'status' => 'CONFIRMED',
        ]);
    }
}
