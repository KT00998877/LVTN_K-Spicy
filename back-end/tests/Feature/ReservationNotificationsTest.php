<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_and_customer_receive_reservation_decision_notifications(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::where('email', 'customer@micay.local')->firstOrFail();
        $cashier = User::where('email', 'cashier@micay.local')->firstOrFail();
        $key = (string) Str::uuid();
        $date = now(config('reservations.timezone'))->addDays(2)->toDateString();

        $payload = [
            'customer_name' => 'Khách Thanh', 'customer_phone' => '0901234567',
            'guest_count' => 2, 'reserved_date' => $date, 'reserved_time' => '12:00',
            'request_key' => $key,
        ];
        $this->actingAs($customer)->postJson('/api/reservations', $payload)->assertCreated();
        $this->postJson('/api/reservations', $payload)->assertOk();

        $reservationId = DB::table('reservations')->where('request_key', $key)->value('id');
        $this->assertNotNull($reservationId);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $cashier->id,
            'deduplication_key' => "RESERVATION_CREATED:{$reservationId}:{$cashier->id}",
        ]);
        $this->assertSame(1, DB::table('notifications')->where('deduplication_key', "RESERVATION_CREATED:{$reservationId}:{$cashier->id}")->count());
        $this->getJson('/api/reservations/status?request_key='.$key)->assertOk()
            ->assertJsonPath('data.status', 'PENDING');

        auth()->forgetGuards();
        $this->actingAs($cashier, 'sanctum')->getJson('/api/staff/notifications')->assertOk();
        $this->patchJson("/api/staff/reservations/{$reservationId}/status", ['status' => 'CONFIRMED'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customer->id,
            'deduplication_key' => "RESERVATION_CONFIRMED:{$reservationId}:{$customer->id}",
        ]);
        $this->getJson('/api/reservations/status?request_key='.$key)->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->patchJson("/api/staff/reservations/{$reservationId}/status", ['status' => 'CANCELLED'])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customer->id,
            'deduplication_key' => "RESERVATION_CANCELLED:{$reservationId}:{$customer->id}",
        ]);
    }

    public function test_cashier_rejection_notifies_customer(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::where('email', 'customer@micay.local')->firstOrFail();
        $cashier = User::where('email', 'cashier@micay.local')->firstOrFail();
        $key = (string) Str::uuid();
        $this->actingAs($customer)->postJson('/api/reservations', [
            'customer_name' => 'Khách Thanh', 'customer_phone' => '0901234567',
            'guest_count' => 2,
            'reserved_date' => now(config('reservations.timezone'))->addDays(2)->toDateString(),
            'reserved_time' => '12:00', 'request_key' => $key,
        ])->assertCreated();
        $reservationId = DB::table('reservations')->where('request_key', $key)->value('id');

        auth()->forgetGuards();
        $this->actingAs($cashier, 'sanctum')
            ->patchJson("/api/staff/reservations/{$reservationId}/status", ['status' => 'CANCELLED'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customer->id,
            'deduplication_key' => "RESERVATION_REJECTED:{$reservationId}:{$customer->id}",
        ]);
        $this->getJson('/api/reservations/status?request_key='.$key)->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }
}
