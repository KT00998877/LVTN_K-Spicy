<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderPackagingSeeder extends Seeder
{
    public function run(): void
    {
        $supplierId = DB::table('suppliers')->where('code', 'SUPPLIER_K')->value('id');
        if (! $supplierId) return;

        $ingredients = DB::table('ingredients')
            ->where('supplier_id', $supplierId)
            ->where(function ($query) {
                $query->where('unit', 'gram')->orWhere('code', 'NL043');
            })
            ->get(['id', 'code', 'unit']);

        foreach ($ingredients as $ingredient) {
            $is7Up = $ingredient->code === 'NL043';
            if (! $is7Up && $ingredient->unit !== 'gram') continue;

            DB::table('ingredient_packagings')->updateOrInsert([
                'ingredient_id' => $ingredient->id,
                'supplier_id' => $supplierId,
                'purchase_unit' => $is7Up ? 'thùng' : 'kg',
            ], [
                'conversion_quantity' => $is7Up ? 24 : 1000,
                'is_default' => false,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }
    }
}
