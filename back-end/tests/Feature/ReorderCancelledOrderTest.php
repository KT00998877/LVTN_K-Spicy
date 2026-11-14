<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReorderCancelledOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_order_items_can_be_added_to_cart_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::firstOrFail();
        $menu = MenuItem::where('slug', 'coca-cola')->firstOrFail();
        $order = Order::create([
            'code' => 'TEST-REORDER-1', 'customer_id' => $customer->id,
            'order_type' => 'ONLINE', 'status' => 'CANCELLED', 'placed_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menu->id, 'item_name' => $menu->name,
            'unit_price' => $menu->price, 'quantity' => 2, 'subtotal' => 2 * $menu->price,
            'status' => 'CANCELLED',
        ]);

        $response = $this->actingAs($customer)->postJson("/api/orders/me/{$order->id}/reorder");

        $response->assertOk()->assertJsonPath('data.added.0', $menu->name);
        $this->assertSame(2, (int) DB::table('cart_items')->where('menu_item_id', $menu->id)->value('quantity'));
    }
}
