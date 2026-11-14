<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPricesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_ingredient_has_a_supplier_purchase_price(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('ingredients as i')
            ->whereNull('i.supplier_price')
            ->whereNotIn('i.id', DB::table('ingredient_packagings')
                ->where('is_active', true)->whereNotNull('supplier_price')
                ->select('ingredient_id'))
            ->count());

        $this->assertTrue(DB::table('ingredient_packagings as p')
            ->join('ingredients as i', 'i.id', '=', 'p.ingredient_id')
            ->where('i.code', 'NL020')->where('p.purchase_unit', 'thùng')
            ->where('p.conversion_quantity', 24)->where('p.supplier_price', 209000)
            ->exists());

        $this->assertEquals(2500, DB::table('ingredients')->where('code', 'NL005')->value('import_price'));
        $this->assertEquals(100, DB::table('ingredients')->where('code', 'NL035')->value('import_price'));
    }
}
