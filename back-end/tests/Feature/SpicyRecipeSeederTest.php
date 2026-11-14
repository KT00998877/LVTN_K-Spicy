<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InventorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpicyRecipeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_noodle_recipes_gain_chili_even_when_recipe_already_exists(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['mi-cay-bo-nho' => 2, 'mi-cay-bo-lon' => 3] as $slug => $gramsPerLevel) {
            $this->assertSame($gramsPerLevel, (int) $this->chiliQuantity($slug));
        }

        DB::table('menu_item_ingredient')->where('ingredient_id', DB::table('ingredients')->where('code', 'NL009')->value('id'))
            ->where('menu_item_id', DB::table('menu_items')->where('slug', 'mi-cay-bo-nho')->value('id'))->delete();
        $this->seed(InventorySeeder::class);

        $this->assertSame(2, (int) $this->chiliQuantity('mi-cay-bo-nho'));
    }

    public function test_noodle_recipes_gain_noodles_even_when_recipe_already_exists(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['mi-cay-bo-nho' => 1, 'mi-cay-bo-lon' => 2] as $slug => $quantity) {
            $this->assertSame($quantity, (int) $this->noodleQuantity($slug));
        }

        DB::table('menu_item_ingredient')->where('ingredient_id', DB::table('ingredients')->where('code', 'NL001')->value('id'))
            ->whereIn('menu_item_id', DB::table('menu_items')->whereIn('slug', ['mi-cay-bo-nho', 'mi-cay-bo-lon'])->pluck('id'))->delete();
        $this->seed(InventorySeeder::class);

        $this->assertSame(1, (int) $this->noodleQuantity('mi-cay-bo-nho'));
        $this->assertSame(2, (int) $this->noodleQuantity('mi-cay-bo-lon'));

        DB::table('menu_item_ingredient')->where('ingredient_id', DB::table('ingredients')->where('code', 'NL001')->value('id'))
            ->where('menu_item_id', DB::table('menu_items')->where('slug', 'mi-cay-bo-lon')->value('id'))
            ->update(['quantity_required' => 1]);
        $this->seed(InventorySeeder::class);

        $this->assertSame(2, (int) $this->noodleQuantity('mi-cay-bo-lon'));
    }

    public function test_mixed_noodle_recipes_are_seeded_for_both_sizes(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['mi-cay-thap-cam-nho' => ['NL001' => 1, 'NL003' => 30, 'NL005' => 2, 'NL006' => 30, 'NL007' => 1],
                  'mi-cay-thap-cam-lon' => ['NL001' => 2, 'NL003' => 36, 'NL005' => 3, 'NL006' => 36, 'NL007' => 2]] as $slug => $expected) {
            $actual = DB::table('menu_item_ingredient as recipe')
                ->join('menu_items as menu', 'menu.id', '=', 'recipe.menu_item_id')
                ->join('ingredients as ingredient', 'ingredient.id', '=', 'recipe.ingredient_id')
                ->where('menu.slug', $slug)
                ->pluck('recipe.quantity_required', 'ingredient.code')
                ->all();

            foreach ($expected as $code => $quantity) {
                $this->assertSame($quantity, (int) ($actual[$code] ?? 0), "$slug: $code");
            }
        }
    }

    public function test_extra_vegetables_recipe_contains_four_ingredients_at_twenty_grams_each(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actual = DB::table('menu_item_ingredient as recipe')
            ->join('menu_items as menu', 'menu.id', '=', 'recipe.menu_item_id')
            ->join('ingredients as ingredient', 'ingredient.id', '=', 'recipe.ingredient_id')
            ->where('menu.slug', 'them-rau')
            ->pluck('recipe.quantity_required', 'ingredient.code')
            ->all();

        $this->assertCount(4, $actual);
        foreach (['NL012', 'NL060', 'NL061', 'NL062'] as $code) {
            $this->assertSame(20, (int) ($actual[$code] ?? 0), $code);
        }
    }

    public function test_each_soju_flavor_uses_its_matching_bottle(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['soju-vi-tao' => 'NL046', 'soju-vi-dao' => 'NL047', 'soju-vi-dau' => 'NL048', 'soju-vi-viet-quat' => 'NL049'] as $slug => $code) {
            $actual = DB::table('menu_item_ingredient as recipe')
                ->join('menu_items as menu', 'menu.id', '=', 'recipe.menu_item_id')
                ->join('ingredients as ingredient', 'ingredient.id', '=', 'recipe.ingredient_id')
                ->where('menu.slug', $slug)
                ->pluck('recipe.quantity_required', 'ingredient.code')
                ->all();

            $this->assertSame([$code => 1], $actual, $slug);
        }
    }

    private function chiliQuantity(string $slug): ?int
    {
        return DB::table('menu_item_ingredient as recipe')
            ->join('menu_items as menu', 'menu.id', '=', 'recipe.menu_item_id')
            ->join('ingredients as ingredient', 'ingredient.id', '=', 'recipe.ingredient_id')
            ->where('menu.slug', $slug)->where('ingredient.code', 'NL009')
            ->value('recipe.quantity_required');
    }

    private function noodleQuantity(string $slug): ?int
    {
        return DB::table('menu_item_ingredient as recipe')
            ->join('menu_items as menu', 'menu.id', '=', 'recipe.menu_item_id')
            ->join('ingredients as ingredient', 'ingredient.id', '=', 'recipe.ingredient_id')
            ->where('menu.slug', $slug)->where('ingredient.code', 'NL001')
            ->value('recipe.quantity_required');
    }
}
