<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderReceiptService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPriceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_order_keeps_supplier_price_when_catalog_price_changes(): void
    {
        $manager = User::factory()->create();
        $supplier = Supplier::create(['code' => 'TEST-SUP', 'name' => 'Test Supplier', 'is_active' => true]);
        $ingredient = Ingredient::create([
            'code' => 'TEST-ING', 'name' => 'Test ingredient', 'unit' => 'kg',
            'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0,
            'supplier_id' => $supplier->id, 'is_active' => true,
        ]);
        DB::table('ingredients')->where('id', $ingredient->id)->update(['supplier_price' => 120000]);
        $order = PurchaseOrder::create([
            'code' => 'PO-TEST-001', 'supplier_id' => $supplier->id,
            'created_by' => $manager->id, 'status' => PurchaseOrder::STATUS_DRAFT,
        ]);
        $item = $order->items()->create([
            'ingredient_id' => $ingredient->id, 'purchase_unit' => 'kg',
            'conversion_quantity' => 1, 'requested_purchase_quantity' => 2,
        ]);

        $service = app(PurchaseOrderService::class);
        $service->send($order, $manager);
        $this->assertEquals(120000, $item->fresh()->purchase_unit_price);

        DB::table('ingredients')->where('id', $ingredient->id)->update(['supplier_price' => 150000]);
        $receipt = app(PurchaseOrderReceiptService::class)->createDraft($order, $manager)->stockImport;

        $this->assertEquals(120000, $item->fresh()->purchase_unit_price);
        $this->assertEquals(120000, $receipt->items->first()->purchase_unit_price);
    }

    public function test_gram_ingredient_must_be_ordered_by_kilogram(): void
    {
        $manager = User::factory()->create();
        $supplier = Supplier::create(['code' => 'TEST-KG', 'name' => 'Test Supplier', 'is_active' => true]);
        $ingredient = Ingredient::create([
            'code' => 'TEST-GRAM', 'name' => 'Test gram ingredient', 'unit' => 'gram',
            'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0,
            'supplier_id' => $supplier->id, 'is_active' => true,
        ]);
        $service = app(PurchaseOrderService::class);

        try {
            $service->create(['supplier_id' => $supplier->id, 'items' => [[
                'ingredient_id' => $ingredient->id, 'requested_purchase_quantity' => 1,
            ]]], $manager);
            $this->fail('Gram purchases must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $packagingId = DB::table('ingredient_packagings')->insertGetId([
            'ingredient_id' => $ingredient->id, 'supplier_id' => $supplier->id,
            'purchase_unit' => 'kg', 'conversion_quantity' => 1000,
            'is_default' => true, 'is_active' => true,
        ]);
        $order = $service->create(['supplier_id' => $supplier->id, 'items' => [[
            'ingredient_id' => $ingredient->id, 'ingredient_packaging_id' => $packagingId,
            'requested_purchase_quantity' => 2,
        ]]], $manager);

        $this->assertSame('kg', $order->items->first()->purchase_unit);
        $this->assertSame(1000, (int) $order->items->first()->conversion_quantity);
    }
}
