<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientPackaging;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagerPurchaseOrderReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_receive_a_sent_order_and_count_only_accepted_stock_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $manager = User::where('email', 'manager@micay.local')->firstOrFail();
        $ingredient = Ingredient::where('code', 'NL003')->firstOrFail();
        $packaging = IngredientPackaging::where('ingredient_id', $ingredient->id)
            ->where('purchase_unit', 'kg')->firstOrFail();
        $stockBefore = $ingredient->quantity_in_stock;
        $endpoint = '/api/staff/manager/purchase-orders';
        Sanctum::actingAs($manager);

        $draft = $this->postJson($endpoint, [
            'supplier_id' => $ingredient->supplier_id,
            'items' => [[
                'ingredient_id' => $ingredient->id,
                'ingredient_packaging_id' => $packaging->id,
                'requested_purchase_quantity' => 3,
            ]],
        ])->assertCreated()->json('data');
        $order = $endpoint.'/'.$draft['id'];
        $this->postJson($order.'/receipt')->assertUnprocessable();
        $this->postJson($order.'/send')->assertOk()->assertJsonPath('data.status', 'SENT');
        $this->getJson('/api/staff/manager/sidebar-counts')->assertOk()
            ->assertJsonPath('data.purchase_orders', 1);
        $this->postJson($order.'/receipt')->assertCreated()
            ->assertJsonPath('data.stock_import.items.0.delivered_purchase_quantity', 3);

        $this->patchJson($order.'/receipt', ['items' => [[
            'purchase_order_item_id' => $draft['items'][0]['id'],
            'delivered_purchase_quantity' => 2,
            'accepted_purchase_quantity' => 1,
            'rejection_reason' => 'Damaged packaging',
            'supplier_lot_code' => 'LOT-01',
        ]]])->assertOk()->assertJsonPath('data.stock_import.items.0.supplier_lot_code', 'LOT-01');
        $this->assertSame($stockBefore, $ingredient->fresh()->quantity_in_stock);

        $payload = ['received_at' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s')];
        $received = $this->postJson($order.'/receive', $payload)->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.received_by', $manager->id)
            ->assertJsonPath('data.stock_import.status', 'CONFIRMED')->json('data');
        $this->postJson($order.'/receive', $payload)->assertOk();
        $this->assertSame($stockBefore + 1000, $ingredient->fresh()->quantity_in_stock);
        $this->getJson('/api/staff/manager/sidebar-counts')->assertOk()
            ->assertJsonPath('data.purchase_orders', 0);

        $returns = '/api/staff/manager/return-requests';
        $return = $this->postJson($returns.'/purchase-orders/'.$draft['id'], [
            'request_key' => (string) Str::uuid(),
            'reason' => 'Quality issue',
            'items' => [[
                'stock_import_item_id' => $received['stock_import']['items'][0]['id'],
                'quantity' => 1000,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data');
        $this->assertSame($stockBefore + 1000, $ingredient->fresh()->quantity_in_stock);
        $this->postJson($returns.'/'.$return['id'].'/dispatch')->assertOk()
            ->assertJsonPath('data.status', 'DISPATCHED');
        $this->postJson($returns.'/'.$return['id'].'/receive')->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED');
        $this->assertSame($stockBefore, $ingredient->fresh()->quantity_in_stock);

        Sanctum::actingAs(User::where('email', 'supplier@micay.local')->firstOrFail());
        $this->postJson($order.'/receive', $payload)->assertForbidden();
        $this->getJson('/api/staff/supplier/purchase-orders')->assertNotFound();
    }
}
