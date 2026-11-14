<?php

namespace Tests\Feature;

use App\Services\RecipeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeSupplierPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_cost_uses_current_supplier_price_per_stock_unit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $menuId = DB::table('menu_items')->where('slug', 'mi-cay-bo-nho')->value('id');
        $ingredientId = DB::table('ingredients')->where('code', 'NL003')->value('id');
        $service = app(RecipeService::class);

        $recipe = $service->get($menuId);
        $beef = collect($recipe['lines'])->firstWhere('ingredient_id', $ingredientId);
        $this->assertSame(190.0, $beef['unit_price']);
        $this->assertSame($beef['quantity_required'] * 190.0, $beef['line_cost']);

        DB::table('ingredient_packagings')->where('ingredient_id', $ingredientId)
            ->where('purchase_unit', 'kg')->update(['supplier_price' => 200000]);
        $updated = $service->get($menuId);
        $updatedBeef = collect($updated['lines'])->firstWhere('ingredient_id', $ingredientId);
        $this->assertSame($beef['quantity_required'] * 200.0, $updatedBeef['line_cost']);
        $this->assertSame($recipe['summary']['ingredient_cost'] + $beef['quantity_required'] * 10, $updated['summary']['ingredient_cost']);
    }
}
