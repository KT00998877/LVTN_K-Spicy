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
            ->get(['id', 'code', 'unit']);

        // Số đơn vị quán sử dụng trong một đơn vị mua từ nhà cung cấp.
        $packedGoods = [
            'NL005' => ['bịch', 24],      // 24 cục chả cá/bịch
            'NL007' => ['bịch', 5],       // 5 cây xúc xích/bịch
            'NL020' => ['thùng', 24],     // Coca Cola lon
            'NL021' => ['thùng', 24],     // Pepsi lon
            'NL024' => ['hộp', 1000],     // 1 hộp sữa 1 lít; kho tính ml
            'NL043' => ['thùng', 24],     // 7-Up lon
            'NL044' => ['thùng', 24],     // Sting lon
            'NL056' => ['chai', 1000],    // 1 chai dầu ăn 1 lít; kho tính ml
        ];

        foreach ($ingredients as $ingredient) {
            if (isset($packedGoods[$ingredient->code])) {
                [$purchaseUnit, $conversion] = $packedGoods[$ingredient->code];
            } elseif ($ingredient->unit === 'gram') {
                [$purchaseUnit, $conversion] = ['kg', 1000];
            } else {
                continue;
            }

            DB::table('ingredient_packagings')->updateOrInsert([
                'ingredient_id' => $ingredient->id,
                'supplier_id' => $supplierId,
                'purchase_unit' => $purchaseUnit,
            ], [
                'conversion_quantity' => $conversion,
                'is_default' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        DB::table('ingredient_packagings')
            ->where('supplier_id', $supplierId)
            ->where('ingredient_id', DB::table('ingredients')->where('code', 'NL024')->value('id'))
            ->where('purchase_unit', 'thùng')
            ->update(['is_active' => false, 'is_default' => false, 'updated_at' => now()]);

    }
}
