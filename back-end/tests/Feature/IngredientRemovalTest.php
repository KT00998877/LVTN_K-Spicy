<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Services\IngredientService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IngredientRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_remove_hides_empty_ingredient_and_rejects_stocked_ingredient(): void
    {
        $empty = Ingredient::create([
            'code' => 'TEST-EMPTY', 'name' => 'Empty', 'unit' => 'kg',
            'quantity_in_stock' => 0, 'min_stock_level' => 0,
            'import_price' => 0, 'is_active' => true,
        ]);
        $stocked = Ingredient::create([
            'code' => 'TEST-STOCKED', 'name' => 'Stocked', 'unit' => 'kg',
            'quantity_in_stock' => 2, 'min_stock_level' => 0,
            'import_price' => 0, 'is_active' => true,
        ]);

        app(IngredientService::class)->remove($empty->id);
        $this->assertFalse((bool) $empty->fresh()->is_active);
        $this->expectException(ValidationException::class);
        app(IngredientService::class)->remove($stocked->id);
    }

    public function test_remove_rejects_ingredient_in_active_recipe(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ingredient = Ingredient::where('code', 'NL003')->firstOrFail();
        $ingredient->update(['quantity_in_stock' => 0]);

        $this->expectException(ValidationException::class);
        app(IngredientService::class)->remove($ingredient->id);
    }
}
