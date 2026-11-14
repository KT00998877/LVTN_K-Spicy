<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplierPricesSeeder extends Seeder
{
    public function run(): void
    {
        // Giá mẫu VND/đơn vị mua để demo, tham khảo thị trường; nhà cung cấp có thể cập nhật sau.
        // Những món chưa rõ quy cách đóng gói dùng đơn vị nguyên liệu trong kho.
        $ingredientPrices = [
            'NL001' => 13000,  // Mì hàn quốc / gói
            'NL015' => 2000,  // Trứng gà / quả
            'NL022' => 65000, // Soju / chai
            'NL031' => 65,    // Siro dâu / ml (chưa rõ dung tích chai)
            'NL033' => 7000,  // Soda / chai
            'NL042' => 5000,  // Mandu / cái
            'NL045' => 8000,  // Trà xanh / gói
            'NL046' => 65000, // Soju táo / chai
            'NL047' => 65000, // Soju đào / chai
            'NL048' => 65000, // Soju dâu / chai
            'NL049' => 65000, // Soju việt quất / chai
            'NL052' => 70,    // Sữa đặc / ml (chưa rõ dung tích hộp)
        ];

        // Giá cho một đơn vị mua từ nhà cung cấp.
        $packagingPrices = [
            'NL003' => ['kg' => 190000], // Thịt bò
            'NL004' => ['kg' => 105000], // Thịt gà
            'NL006' => ['kg' => 250000], // Tôm sú
            'NL010' => ['kg' => 60000], // Tỏi
            'NL011' => ['kg' => 80000], // Gừng
            'NL012' => ['kg' => 25000], // Rau cải thảo
            'NL013' => ['kg' => 30000], // Hành tây
            'NL014' => ['kg' => 22000], // Cà rốt
            'NL016' => ['kg' => 350000], // Phô mai mozzarella
            'NL017' => ['kg' => 25000], // Gạo tẻ
            'NL019' => ['kg' => 25000], // Bột mì
            'NL026' => ['kg' => 85000], // Trân châu
            'NL027' => ['kg' => 180000], // Mực ống
            'NL028' => ['kg' => 180000], // Bạch tuộc
            'NL029' => ['kg' => 30000], // Bắp ngô
            'NL034' => ['kg' => 10000], // Đá bào
            'NL035' => ['kg' => 100000], // Bánh gạo tokbokki
            'NL036' => ['kg' => 95000], // Kim chi thành phẩm
            'NL008' => ['kg' => 150000], // Ớt Gochujang (hộp)
            'NL009' => ['kg' => 180000], // Bột ớt Hàn Quốc
            'NL018' => ['kg' => 200000], // Rong biển cuộn kimbap
            'NL025' => ['kg' => 80000], // Trà đen (gói)
            'NL030' => ['kg' => 90000], // Đào (lon)
            'NL007' => ['bịch' => 35000], // Xúc xích Hàn Quốc, 5 cây/bịch
            'NL005' => ['bịch' => 60000], // Chả cá Hàn Quốc, 24 cục/bịch
            'NL020' => ['thùng' => 209000], // Coca Cola lon, 24 lon/thùng
            'NL021' => ['thùng' => 195000], // Pepsi lon, 24 lon/thùng
            'NL024' => ['hộp' => 46000], // Sữa tươi, hộp 1 lít
            'NL043' => ['thùng' => 185000], // 7-Up lon, 24 lon/thùng
            'NL044' => ['thùng' => 210000], // Sting lon, 24 lon/thùng
            'NL056' => ['chai' => 50000], // Dầu ăn, chai 1 lít
            'NL050' => ['kg' => 120000], // Dâu tây tươi
            'NL051' => ['kg' => 80000], // Đậu đỏ ngọt
            'NL053' => ['kg' => 40000], // Đường nâu
            'NL054' => ['kg' => 200000], // Bột quế
            'NL055' => ['kg' => 100000], // Men nở
            'NL057' => ['kg' => 200000], // Óc chó
            'NL058' => ['kg' => 50000], // Xoài tươi
            'NL059' => ['kg' => 120000], // Bánh gạo ngọt
            'NL060' => ['kg' => 50000], // Nấm kim châm
            'NL061' => ['kg' => 50000], // Súp lơ
            'NL062' => ['kg' => 15000], // Bắp cải
        ];

        foreach ($ingredientPrices as $code => $price) {
            if ($price === null) continue;

            DB::table('ingredients')->where('code', $code)->update([
                'supplier_price' => $price,
                'updated_at' => now(),
            ]);
            DB::table('ingredients')->where('code', $code)->where('import_price', '<=', 0)
                ->update(['import_price' => $price]);
        }

        foreach ($packagingPrices as $code => $units) {
            $ingredientId = DB::table('ingredients')->where('code', $code)->value('id');
            foreach ($units as $unit => $price) {
                if ($price === null) continue;

                DB::table('ingredient_packagings')
                    ->where('ingredient_id', $ingredientId)
                    ->where('purchase_unit', $unit)
                    ->update(['supplier_price' => $price, 'updated_at' => now()]);
                $conversion = DB::table('ingredient_packagings')
                    ->where('ingredient_id', $ingredientId)->where('purchase_unit', $unit)
                    ->value('conversion_quantity');
                if ($conversion > 0) {
                    DB::table('ingredients')->where('id', $ingredientId)->where('import_price', '<=', 0)
                        ->update(['import_price' => round($price / $conversion, 2)]);
                }
            }
        }
    }
}
