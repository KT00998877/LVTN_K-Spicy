<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TablePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_payment_requires_received_money_and_cannot_be_recorded_twice(): void
    {
        $this->withoutMiddleware();
        $waiter = User::factory()->create();
        $this->actingAs($waiter);
        $tableId = DB::table('tables')->insertGetId(['code' => 'B01', 'status' => 'OCCUPIED', 'created_at' => now(), 'updated_at' => now()]);
        $sessionId = DB::table('table_sessions')->insertGetId(['table_id' => $tableId, 'waiter_id' => $waiter->id, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        $orderId = DB::table('orders')->insertGetId(['code' => 'ORD-TEST-001', 'table_session_id' => $sessionId, 'waiter_id' => $waiter->id, 'order_type' => 'DINE_IN', 'status' => 'SERVED', 'subtotal' => 50000, 'total_amount' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Mì cay', 'slug' => 'mi-cay', 'created_at' => now(), 'updated_at' => now()]);
        $menuId = DB::table('menu_items')->insertGetId(['category_id' => $categoryId, 'name' => 'Mì cay', 'slug' => 'mi-cay-test', 'price' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'menu_item_id' => $menuId, 'item_name' => 'Mì cay', 'unit_price' => 50000, 'quantity' => 1, 'subtotal' => 50000, 'status' => 'SERVED', 'created_at' => now(), 'updated_at' => now()]);

        $url = "/api/staff/orders/{$orderId}/collect-payment";
        $this->postJson($url, ['method' => 'CASH', 'expected_amount' => 50000])->assertUnprocessable();
        $this->postJson($url, ['method' => 'TRANSFER', 'expected_amount' => 50000, 'confirmed_received' => true])->assertUnprocessable();
        $this->postJson($url, ['method' => 'CASH', 'expected_amount' => 40000, 'confirmed_received' => true])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);

        $this->postJson($url, ['method' => 'TRANSFER', 'expected_amount' => 50000, 'transaction_ref' => 'BANK-001', 'confirmed_received' => true])->assertOk();
        $this->assertDatabaseHas('payments', ['method' => 'BANK_TRANSFER', 'amount' => 50000, 'transaction_ref' => 'BANK-001', 'status' => 'SUCCESS', 'processed_by' => $waiter->id]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('table_sessions', ['id' => $sessionId, 'status' => 'CLOSED']);
        $this->assertDatabaseHas('tables', ['id' => $tableId, 'status' => 'AVAILABLE']);

        $this->postJson($url, ['method' => 'CASH', 'expected_amount' => 50000, 'confirmed_received' => true])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }
}
