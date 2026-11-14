<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OnlineOrderController;
use App\Http\Controllers\Api\ShipperLocationController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\ShipperDispatchService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ShipperAssignmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_assigned_order_moves_from_nearby_to_assigned_after_acceptance(): void
    {
        $this->seed(DatabaseSeeder::class);
        $shipper = User::where('email', 'shipper@micay.local')->firstOrFail();
        $order = Order::create([
            'code' => 'TEST-SHIPPER-1', 'order_type' => 'ONLINE', 'status' => 'CONFIRMED',
            'placed_at' => now(), 'delivery_latitude' => 10.03, 'delivery_longitude' => 105.77,
        ]);
        $order->forceFill(['confirmed_at' => now(), 'shipper_id' => $shipper->id])->save();
        $unassigned = Order::create([
            'code' => 'TEST-SHIPPER-UNASSIGNED', 'order_type' => 'ONLINE', 'status' => 'CONFIRMED',
            'placed_at' => now(), 'delivery_latitude' => 10.03, 'delivery_longitude' => 105.77,
        ]);
        $unassigned->forceFill(['confirmed_at' => now()])->save();

        $request = Request::create('/api/staff/deliveries/nearby-jobs');
        $request->setUserResolver(fn () => $shipper);
        $nearby = app(ShipperLocationController::class)->nearbyJobs($request)->getData(true)['data'];
        $this->assertCount(1, $nearby);
        $this->assertSame($order->id, $nearby[0]['id']);

        $assigned = app(OnlineOrderController::class)->deliveries($request)->getData(true)['data'];
        $this->assertNull($assigned[0]['shipper_accepted_at']);

        $order->forceFill(['status' => 'PREPARING'])->save();
        app(ShipperDispatchService::class)->accept($shipper, $order->id);

        $this->assertSame([], app(ShipperLocationController::class)->nearbyJobs($request)->getData(true)['data']);
        $assigned = app(OnlineOrderController::class)->deliveries($request)->getData(true)['data'];
        $this->assertNotNull($assigned[0]['shipper_accepted_at']);
    }

    public function test_shipper_arrives_at_restaurant_before_manager_handover(): void
    {
        $this->seed(DatabaseSeeder::class);
        $shipper = User::where('email', 'shipper@micay.local')->firstOrFail();
        $manager = User::where('email', 'manager@micay.local')->firstOrFail();
        $menu = MenuItem::where('slug', 'mi-cay-bo-nho')->firstOrFail();
        $order = Order::create([
            'code' => 'TEST-SHIPPER-ARRIVAL', 'order_type' => 'ONLINE',
            'status' => 'READY_NOTIFY_SHIPPER', 'placed_at' => now(),
        ]);
        $order->forceFill(['shipper_id' => $shipper->id, 'shipper_accepted_at' => now(), 'confirmed_at' => now()])->save();
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menu->id, 'item_name' => $menu->name,
            'unit_price' => $menu->price, 'quantity' => 1, 'subtotal' => $menu->price, 'status' => 'READY',
        ]);

        $this->actingAs($manager)->postJson("/api/staff/online-orders/{$order->id}/handover", ['shipper_id' => $shipper->id])
            ->assertStatus(409);
        $this->actingAs($shipper)->postJson("/api/staff/deliveries/{$order->id}/arrived-restaurant")
            ->assertOk()->assertJsonPath('data.shipper_arrived_restaurant_at', fn ($value) => $value !== null);
        $arrivedAt = $order->fresh()->shipper_arrived_restaurant_at;
        $this->actingAs($shipper)->postJson("/api/staff/deliveries/{$order->id}/arrived-restaurant")->assertOk();
        $this->assertEquals($arrivedAt, $order->fresh()->shipper_arrived_restaurant_at);
        $this->actingAs($manager)->postJson("/api/staff/online-orders/{$order->id}/handover", ['shipper_id' => $shipper->id])
            ->assertOk();
        $this->assertSame('DELIVERING', $order->fresh()->status);
    }
}
