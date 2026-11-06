<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\ShipperEarningsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShipperEarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimum_distance_rounding_and_one_record_per_order(): void
    {
        $service = app(ShipperEarningsService::class);
        $this->assertSame(10500, $service->amountFor(1.8));
        $this->assertSame(10500, $service->amountFor(3));
        $this->assertSame(11000, $service->amountFor(3.14));
        $this->assertSame(10500, $service->amountFor(null));

        $shipper = User::factory()->create();
        $order = $this->makeOrder($shipper, 'SHIP-001', 3.14);
        $service->record($order, $shipper);
        $service->record($order, $shipper);

        $this->assertDatabaseCount('shipper_earnings', 1);
        $this->assertDatabaseHas('shipper_earnings', [
            'order_id' => $order->id,
            'shipper_id' => $shipper->id,
            'rate_per_km' => 3500,
            'amount' => 11000,
        ]);
    }

    public function test_shipper_sees_only_own_earnings(): void
    {
        $this->withoutMiddleware();
        $shipper = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ShipperEarningsService::class);
        $service->record($this->makeOrder($shipper, 'SHIP-OWN', 2), $shipper);
        $service->record($this->makeOrder($other, 'SHIP-OTHER', 5), $other);

        $this->actingAs($shipper)
            ->getJson('/api/staff/deliveries/earnings?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('summary.total_amount', 10500)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.code', 'SHIP-OWN');
    }

    private function makeOrder(User $shipper, string $code, ?float $distance): Order
    {
        $id = DB::table('orders')->insertGetId([
            'code' => $code,
            'shipper_id' => $shipper->id,
            'order_type' => 'ONLINE',
            'status' => 'DELIVERED',
            'subtotal' => 100000,
            'total_amount' => 100000,
            'delivery_distance_km' => $distance,
            'delivered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return Order::findOrFail($id);
    }
}
