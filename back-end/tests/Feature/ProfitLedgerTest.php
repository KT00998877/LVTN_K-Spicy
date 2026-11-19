<?php

namespace Tests\Feature;

use App\Services\StatisticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfitLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_order_uses_recorded_ingredient_cost(): void
    {
        $this->seed(DatabaseSeeder::class);
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'TEST-PROFIT-1',
            'status' => 'COMPLETED',
            'total_amount' => 50000,
            'completed_at' => $now,
            'placed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'menu_item_id' => DB::table('menu_items')->value('id'),
            'item_name' => 'Món thử',
            'unit_price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
            'status' => 'SERVED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inventory_transactions')->insert([
            'ingredient_id' => DB::table('ingredients')->value('id'),
            'type' => 'EXPORT',
            'quantity' => -2,
            'unit_price' => 10000,
            'reference_type' => 'order_item',
            'reference_id' => $itemId,
            'transacted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $period = StatisticsService::resolvePeriod(['period' => 'today'])[0];
        $result = app(StatisticsService::class)->profitLedger($period);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['total']['orders']);
        $this->assertSame(50000.0, $result['total']['revenue']);
        $this->assertSame(20000.0, $result['total']['cost']);
        $this->assertSame(30000.0, $result['total']['profit']);
        $this->assertSame(0, $result['total']['missing_cost_items']);
    }

    public function test_missing_ingredient_cost_does_not_show_false_profit(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('menu_item_ingredient')->where('menu_item_id', DB::table('menu_items')->value('id'))->delete();
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'TEST-PROFIT-2',
            'status' => 'COMPLETED',
            'total_amount' => 50000,
            'completed_at' => $now,
            'placed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'menu_item_id' => DB::table('menu_items')->value('id'),
            'item_name' => 'Món thử',
            'unit_price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
            'status' => 'SERVED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $period = StatisticsService::resolvePeriod(['period' => 'today'])[0];
        $result = app(StatisticsService::class)->profitLedger($period);

        $this->assertSame(1, $result['total']['missing_cost_items']);
        $this->assertNull($result['total']['profit']);
    }

    public function test_missing_transaction_uses_recipe_import_price(): void
    {
        $this->seed(DatabaseSeeder::class);
        $now = now();
        $menuId = DB::table('menu_items')->value('id');
        $recipe = DB::table('menu_item_ingredient')->where('menu_item_id', $menuId)->first();
        $this->assertNotNull($recipe);
        DB::table('menu_item_ingredient')->where('menu_item_id', $menuId)->where('id', '!=', $recipe->id)->delete();
        DB::table('ingredients')->where('id', $recipe->ingredient_id)->update(['import_price' => 5000]);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'TEST-PROFIT-RECIPE', 'status' => 'COMPLETED', 'total_amount' => 50000,
            'completed_at' => $now, 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'menu_item_id' => $menuId, 'item_name' => 'Món thử',
            'unit_price' => 50000, 'quantity' => 1, 'subtotal' => 50000,
            'status' => 'SERVED', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $result = app(StatisticsService::class)->profitLedger(StatisticsService::resolvePeriod(['period' => 'today'])[0]);
        $expectedCost = ceil((float) $recipe->quantity_required * (float) $recipe->waste_factor) * 5000;
        $this->assertSame($expectedCost, $result['total']['cost']);
        $this->assertSame(50000 - $expectedCost, $result['total']['profit']);
        $this->assertSame(0, $result['total']['missing_cost_items']);
        $this->assertSame(1, $result['total']['estimated_cost_items']);
    }

    public function test_completed_order_uses_order_level_inventory_cost(): void
    {
        $this->seed(DatabaseSeeder::class);
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'TEST-PROFIT-ORDER', 'status' => 'COMPLETED', 'total_amount' => 50000,
            'completed_at' => $now, 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'menu_item_id' => DB::table('menu_items')->value('id'),
            'item_name' => 'Món thử', 'unit_price' => 50000, 'quantity' => 1,
            'subtotal' => 50000, 'status' => 'SERVED', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_transactions')->insert([
            'ingredient_id' => DB::table('ingredients')->value('id'), 'type' => 'EXPORT',
            'quantity' => -2, 'unit_price' => 10000, 'reference_type' => 'order',
            'reference_id' => $orderId, 'transacted_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $period = StatisticsService::resolvePeriod(['period' => 'today'])[0];
        $result = app(StatisticsService::class)->profitLedger($period);

        $this->assertSame(20000.0, $result['total']['cost']);
        $this->assertSame(30000.0, $result['total']['profit']);
        $this->assertSame(0, $result['total']['missing_cost_items']);
    }

    public function test_old_zero_cost_uses_current_import_price_as_estimate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'TEST-PROFIT-3', 'status' => 'COMPLETED', 'total_amount' => 50000,
            'completed_at' => $now, 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'menu_item_id' => DB::table('menu_items')->value('id'),
            'item_name' => 'Món thử', 'unit_price' => 50000, 'quantity' => 1,
            'subtotal' => 50000, 'status' => 'SERVED', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_transactions')->insert([
            'ingredient_id' => DB::table('ingredients')->where('code', 'NL005')->value('id'),
            'type' => 'EXPORT', 'quantity' => -2, 'unit_price' => 0,
            'reference_type' => 'order_item', 'reference_id' => $itemId,
            'transacted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $period = StatisticsService::resolvePeriod(['period' => 'today'])[0];
        $result = app(StatisticsService::class)->profitLedger($period);

        $this->assertSame(5000.0, $result['total']['cost']);
        $this->assertSame(45000.0, $result['total']['profit']);
        $this->assertSame(1, $result['total']['estimated_cost_items']);
        $this->assertSame(0, $result['total']['missing_cost_items']);
    }
}
