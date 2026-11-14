<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StaffKitchenController;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\NonKitchenItemService;
use App\Services\OnlineOrderService;
use App\Services\StaffOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NonKitchenOrderItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_coke_is_deducted_once_and_only_cooked_dishes_reach_kitchen(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = User::firstOrFail();
        $order = Order::create([
            'code' => 'TEST-KITCHEN-1',
            'customer_id' => $actor->id,
            'order_type' => 'ONLINE',
            'status' => 'CONFIRMED',
            'placed_at' => now(),
        ]);
        $order->forceFill(['confirmed_at' => now()])->save();

        $coke = MenuItem::where('slug', 'coca-cola')->firstOrFail();
        $noodle = MenuItem::where('slug', 'mi-cay-bo-nho')->firstOrFail();
        $side = MenuItem::where('slug', 'kimbap-truyen-thong')->firstOrFail();
        $topping = MenuItem::where('slug', 'them-trung')->firstOrFail();
        $cokeItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $coke->id, 'item_name' => $coke->name,
            'unit_price' => $coke->price, 'quantity' => 2, 'subtotal' => 2 * $coke->price, 'status' => 'PENDING',
        ]);
        $noodleItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $noodle->id, 'item_name' => $noodle->name,
            'unit_price' => $noodle->price, 'quantity' => 1, 'subtotal' => $noodle->price, 'status' => 'PENDING',
        ]);
        $sideItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $side->id, 'item_name' => $side->name,
            'unit_price' => $side->price, 'quantity' => 1, 'subtotal' => $side->price, 'status' => 'PENDING',
        ]);
        $toppingItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $topping->id, 'item_name' => $topping->name,
            'unit_price' => $topping->price, 'quantity' => 1, 'subtotal' => $topping->price, 'status' => 'PENDING',
        ]);

        $stockBefore = DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock');
        $service = app(NonKitchenItemService::class);
        $service->complete($cokeItem->load('menuItem.category'), $actor);
        $service->complete($cokeItem->fresh()->load('menuItem.category'), $actor);
        $service->complete($noodleItem->load('menuItem.category'), $actor);
        $service->complete($toppingItem->load('menuItem.category'), $actor);

        $this->assertSame('READY', $cokeItem->fresh()->status);
        $this->assertSame('PENDING', $noodleItem->fresh()->status);
        $this->assertSame('READY', $toppingItem->fresh()->status);
        $this->assertSame((int) $stockBefore - 2, (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock'));
        $this->assertCount(1, DB::table('inventory_transactions')->where('reference_type', 'order_item')->where('reference_id', $cokeItem->id)->get());

        $response = app(StaffKitchenController::class)->items(Request::create('/api/staff/kitchen/items'));
        $ids = collect($response->getData(true)['data'])->pluck('item.id')->all();
        $this->assertContains($noodleItem->id, $ids);
        $this->assertContains($sideItem->id, $ids);
        $this->assertNotContains($cokeItem->id, $ids);
        $this->assertNotContains($toppingItem->id, $ids);
    }

    public function test_table_drink_is_ready_and_deducted_when_added(): void
    {
        $this->seed(DatabaseSeeder::class);
        $actor = User::firstOrFail();
        $order = Order::create(['code' => 'TEST-TABLE-1', 'waiter_id' => $actor->id, 'order_type' => 'DINE_IN', 'status' => 'PENDING', 'placed_at' => now()]);
        $coke = MenuItem::where('slug', 'coca-cola')->firstOrFail();
        $before = (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock');

        app(StaffOrderService::class)->addItem($order->id, ['menu_item_id' => $coke->id, 'quantity' => 1], $actor);

        $this->assertSame('READY', $order->items()->firstOrFail()->status);
        $this->assertSame($before - 1, (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock'));
    }

    public function test_takeaway_drink_is_ready_and_deducted_on_confirmation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $actor = User::firstOrFail();
        $order = Order::create([
            'code' => 'TEST-TAKEAWAY-1', 'customer_id' => $actor->id,
            'order_type' => 'TAKEAWAY', 'status' => 'PENDING', 'payment_method' => 'COD',
            'discount_amount' => 0,
            'recipient_name' => 'Khách thử', 'recipient_phone' => '0900000000', 'placed_at' => now(),
        ]);
        $coke = MenuItem::where('slug', 'coca-cola')->firstOrFail();
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $coke->id, 'item_name' => $coke->name,
            'unit_price' => $coke->price, 'quantity' => 1, 'subtotal' => $coke->price, 'status' => 'PENDING',
        ]);
        $order->forceFill(['subtotal' => $coke->price, 'total_amount' => $coke->price])->save();
        $before = (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock');

        $service = app(OnlineOrderService::class);
        $service->createInvoice($order);
        $service->confirm($actor, $order->id);

        $this->assertSame('READY_NOTIFY_SHIPPER', $order->fresh()->status);
        $this->assertSame('READY', $order->items()->firstOrFail()->status);
        $this->assertSame($before - 1, (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock'));

        $service->customerCancel($actor, $order->id, 'Đổi ý');
        $service->approveCancellation($actor, $order->id);

        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->assertSame($before, (int) DB::table('ingredients')->where('code', 'NL020')->value('quantity_in_stock'));
    }
}
