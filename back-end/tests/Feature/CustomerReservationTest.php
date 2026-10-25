<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('tables', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
            $table->unsignedInteger('capacity');
            $table->string('location')->nullable();
            $table->string('status')->default('AVAILABLE');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->unsignedInteger('guest_count');
            $table->date('reserved_date');
            $table->time('reserved_time');
            $table->text('note')->nullable();
            $table->string('status')->default('PENDING');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();
        });

        DB::table('tables')->insert([
            ['code' => 'B01', 'name' => 'Bàn 1', 'capacity' => 4, 'location' => 'Tầng 1', 'status' => 'AVAILABLE', 'is_active' => true],
            ['code' => 'B02', 'name' => 'Bàn 2', 'capacity' => 8, 'location' => 'Tầng 2', 'status' => 'AVAILABLE', 'is_active' => true],
        ]);
    }

    public function test_authenticated_booking_is_attached_and_listed_for_customer(): void
    {
        $customer = $this->customer('one@example.com');

        $this->withToken($customer->createToken('web')->plainTextToken)
            ->postJson('/api/reservations', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.can_edit', true);

        $this->assertDatabaseHas('reservations', ['customer_id' => $customer->id]);
        $this->withToken($customer->createToken('list')->plainTextToken)
            ->getJson('/api/reservations/me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.table.code', 'B01');
    }

    public function test_customer_can_update_only_a_pending_reservation(): void
    {
        $customer = $this->customer('edit@example.com');
        $reservation = $this->reservation($customer);
        $token = $customer->createToken('web')->plainTextToken;
        $newDate = CarbonImmutable::now(config('reservations.timezone'))->addDays(3)->toDateString();

        $this->withToken($token)->patchJson("/api/reservations/me/{$reservation->id}", [
            'guest_count' => 6,
            'reserved_date' => $newDate,
            'reserved_time' => '19:00',
            'location' => 'Tầng 2',
            'note' => 'Gần cửa sổ',
        ])->assertOk()
            ->assertJsonPath('data.guest_count', 6)
            ->assertJsonPath('data.table.code', 'B02');

        $reservation->refresh()->update(['status' => 'CONFIRMED']);
        $this->withToken($token)->patchJson("/api/reservations/me/{$reservation->id}", [
            'guest_count' => 4,
            'reserved_date' => $newDate,
            'reserved_time' => '20:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    public function test_customer_can_cancel_own_pending_or_confirmed_reservation(): void
    {
        $customer = $this->customer('cancel@example.com');
        $reservation = $this->reservation($customer, 'CONFIRMED');
        $token = $customer->createToken('web')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/reservations/me/{$reservation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.can_cancel', false);

        $other = $this->customer('other@example.com');
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('web')->plainTextToken)
            ->postJson("/api/reservations/me/{$reservation->id}/cancel")
            ->assertNotFound();
    }

    private function customer(string $email): User
    {
        return User::create([
            'name' => 'Khách hàng',
            'email' => $email,
            'password' => 'secret123',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'customer_name' => 'Nguyễn Văn An',
            'customer_phone' => '0901234567',
            'guest_count' => 2,
            'reserved_date' => CarbonImmutable::now(config('reservations.timezone'))->addDays(2)->toDateString(),
            'reserved_time' => '18:00',
            'location' => 'Tầng 1',
            'note' => null,
            'request_key' => (string) Str::uuid(),
        ];
    }

    private function reservation(User $customer, string $status = 'PENDING'): Reservation
    {
        return Reservation::create([
            'customer_name' => 'Nguyễn Văn An',
            'customer_phone' => '0901234567',
            'customer_id' => $customer->id,
            'table_id' => 1,
            'guest_count' => 2,
            'reserved_date' => CarbonImmutable::now(config('reservations.timezone'))->addDays(2)->toDateString(),
            'reserved_time' => '18:00:00',
            'status' => $status,
        ]);
    }
}
