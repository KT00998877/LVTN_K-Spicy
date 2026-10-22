<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $categoryMap = [];
        foreach ([
            'Mì và tinh bột', 'Thịt tươi', 'Hải sản tươi', 'Hàng chế biến sẵn',
            'Rau củ', 'Sốt và gia vị', 'Đồ uống', 'Nguyên liệu pha chế',
        ] as $order => $name) {
            DB::table('ingredient_categories')->updateOrInsert(['name' => $name], [
                'sort_order' => $order + 1, 'is_active' => true, 'updated_at' => now(),
            ]);
            $categoryMap[$name] = DB::table('ingredient_categories')->where('name', $name)->value('id');
        }
        $suppliers = [
            ['name' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food', 'contact_name' => 'Lee Min Ho',   'phone' => '0901111111', 'email' => 'sales@kfood.vn'],
            ['name' => 'Chợ đầu mối Bình Điền',                  'contact_name' => 'Trần Thị B',   'phone' => '0912222222', 'email' => 'binhdien@example.com'],
            ['name' => 'Công ty CP Rau sạch Đà Lạt',             'contact_name' => 'Nguyễn Văn D', 'phone' => '0923333333', 'email' => 'info@raudalat.vn'],
            ['name' => 'Công ty TNHH Đồ uống Sài Gòn',           'contact_name' => 'Phạm Văn E',   'phone' => '0934444444', 'email' => 'order@douongsg.vn'],
        ];

        $supplierIds = [];
        foreach ($suppliers as $s) {
            $existing = DB::table('suppliers')->where('name', $s['name'])->first();
            if ($existing) {
                $supplierIds[$s['name']] = $existing->id;
                continue;
            }
            $supplierIds[$s['name']] = DB::table('suppliers')->insertGetId($s + [
                'address' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ingredients = [
            // Mì và gạo
            ['code' => 'NL001', 'name' => 'Mì tươi (gói)',          'unit' => 'gói', 'quantity_in_stock' => 300, 'min_stock_level' => 80,  'import_price' => 8000,   'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL002', 'name' => 'Mì Ý (gói)',              'unit' => 'gói', 'quantity_in_stock' => 100, 'min_stock_level' => 30,  'import_price' => 10000,  'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],

            // Thịt
            ['code' => 'NL003', 'name' => 'Thịt bò',                  'unit' => 'kg',  'quantity_in_stock' => 25,  'min_stock_level' => 8,   'import_price' => 320000, 'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL004', 'name' => 'Thịt gà',                  'unit' => 'kg',  'quantity_in_stock' => 30,  'min_stock_level' => 10,  'import_price' => 120000, 'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL005', 'name' => 'Chả cá Hàn Quốc',          'unit' => 'cục', 'quantity_in_stock' => 0,   'min_stock_level' => 0,   'import_price' => 0,      'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'], // 1 bịch = 24 cục; chưa biết giá/cục.
            ['code' => 'NL006', 'name' => 'Tôm sú',                   'unit' => 'kg',  'quantity_in_stock' => 15,  'min_stock_level' => 5,   'import_price' => 450000, 'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL007', 'name' => 'Xúc xích Hàn Quốc',        'unit' => 'cây', 'quantity_in_stock' => 0,   'min_stock_level' => 0,   'import_price' => 7000,   'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'], // 1 bịch = 5 cây, 35.000đ/bịch.

            // Gia vị cay
            ['code' => 'NL008', 'name' => 'Ớt Gochujang (hộp)',       'unit' => 'hộp', 'quantity_in_stock' => 40, 'min_stock_level' => 10,  'import_price' => 75000,  'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL009', 'name' => 'Bột ớt Hàn Quốc cấp 5',    'unit' => 'gói', 'quantity_in_stock' => 20, 'min_stock_level' => 5,   'import_price' => 45000,  'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL010', 'name' => 'Tỏi',                      'unit' => 'kg',  'quantity_in_stock' => 10, 'min_stock_level' => 3,   'import_price' => 60000,  'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL011', 'name' => 'Gừng',                     'unit' => 'kg',  'quantity_in_stock' => 5,  'min_stock_level' => 2,   'import_price' => 80000,  'supplier' => 'Chợ đầu mối Bình Điền'],

            // Rau và trứng, phô mai
            ['code' => 'NL012', 'name' => 'Rau cải thảo',             'unit' => 'kg',  'quantity_in_stock' => 15, 'min_stock_level' => 5,   'import_price' => 25000,  'supplier' => 'Công ty CP Rau sạch Đà Lạt'],
            ['code' => 'NL013', 'name' => 'Hành tây',                 'unit' => 'kg',  'quantity_in_stock' => 10, 'min_stock_level' => 3,   'import_price' => 30000,  'supplier' => 'Công ty CP Rau sạch Đà Lạt'],
            ['code' => 'NL014', 'name' => 'Cà rốt',                   'unit' => 'kg',  'quantity_in_stock' => 12, 'min_stock_level' => 4,   'import_price' => 22000,  'supplier' => 'Công ty CP Rau sạch Đà Lạt'],
            ['code' => 'NL015', 'name' => 'Trứng gà',                 'unit' => 'quả', 'quantity_in_stock' => 200,'min_stock_level' => 80,  'import_price' => 3500,   'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL016', 'name' => 'Phô mai mozzarella',       'unit' => 'kg',  'quantity_in_stock' => 10, 'min_stock_level' => 3,   'import_price' => 180000, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],

            // Gạo và bánh cho món phụ
            ['code' => 'NL017', 'name' => 'Gạo tẻ',                   'unit' => 'kg',  'quantity_in_stock' => 200,'min_stock_level' => 50,  'import_price' => 25000,  'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL018', 'name' => 'Rong biển cuộn kimbap',    'unit' => 'gói', 'quantity_in_stock' => 50, 'min_stock_level' => 15,  'import_price' => 60000,  'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL019', 'name' => 'Bột mì',                   'unit' => 'kg',  'quantity_in_stock' => 30, 'min_stock_level' => 10,  'import_price' => 25000,  'supplier' => 'Chợ đầu mối Bình Điền'],

            // Đồ uống
            ['code' => 'NL020', 'name' => 'Coca Cola lon',             'unit' => 'lon', 'quantity_in_stock' => 200,'min_stock_level' => 50,  'import_price' => 10000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL021', 'name' => 'Pepsi lon',                 'unit' => 'lon', 'quantity_in_stock' => 150,'min_stock_level' => 50,  'import_price' => 10000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL022', 'name' => 'Soju chai',                 'unit' => 'chai','quantity_in_stock' => 60, 'min_stock_level' => 20,  'import_price' => 65000,  'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL023', 'name' => 'Nước suối',                 'unit' => 'chai','quantity_in_stock' => 240,'min_stock_level' => 60,  'import_price' => 4000,   'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL024', 'name' => 'Sữa tươi (hộp)',           'unit' => 'hộp', 'quantity_in_stock' => 100,'min_stock_level' => 30,  'import_price' => 9000,   'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL025', 'name' => 'Trà đen (gói)',            'unit' => 'gói', 'quantity_in_stock' => 200,'min_stock_level' => 50,  'import_price' => 8000,   'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL026', 'name' => 'Trân châu',                 'unit' => 'kg',  'quantity_in_stock' => 10, 'min_stock_level' => 3,   'import_price' => 85000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],

            // Hải sản bổ sung (cho biến thể mực / bạch tuộc)
            ['code' => 'NL027', 'name' => 'Mực ống',                   'unit' => 'kg',  'quantity_in_stock' => 10, 'min_stock_level' => 4,   'import_price' => 380000, 'supplier' => 'Chợ đầu mối Bình Điền'],
            ['code' => 'NL028', 'name' => 'Bạch tuộc',                 'unit' => 'kg',  'quantity_in_stock' => 8,  'min_stock_level' => 3,   'import_price' => 420000, 'supplier' => 'Chợ đầu mối Bình Điền'],

            // Nguyên liệu cho món phụ mới
            ['code' => 'NL029', 'name' => 'Bắp ngô',                   'unit' => 'kg',  'quantity_in_stock' => 20, 'min_stock_level' => 5,   'import_price' => 30000,  'supplier' => 'Công ty CP Rau sạch Đà Lạt'],
            ['code' => 'NL030', 'name' => 'Đào (lon)',                 'unit' => 'lon', 'quantity_in_stock' => 50, 'min_stock_level' => 15,  'import_price' => 18000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL031', 'name' => 'Siro dâu',                  'unit' => 'chai','quantity_in_stock' => 20, 'min_stock_level' => 5,   'import_price' => 65000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL032', 'name' => 'Siro việt quất',            'unit' => 'chai','quantity_in_stock' => 20, 'min_stock_level' => 5,   'import_price' => 65000,  'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL033', 'name' => 'Soda',                      'unit' => 'chai','quantity_in_stock' => 60, 'min_stock_level' => 20,  'import_price' => 7000,   'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL034', 'name' => 'Đá bào',                    'unit' => 'kg',  'quantity_in_stock' => 30, 'min_stock_level' => 10,  'import_price' => 10000,  'supplier' => 'Chợ đầu mối Bình Điền'],

            // Nguyên liệu bán thành phẩm và đồ uống còn thiếu trong công thức rà soát.
            // Khởi tạo 0; bếp đếm tồn thực tế trước khi dùng cho cảnh báo hết món.
            ['code' => 'NL035', 'name' => 'Bánh gạo tokbokki',         'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL036', 'name' => 'Kim chi thành phẩm',         'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL037', 'name' => 'Gà viên thành phẩm',         'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL038', 'name' => 'Tôm viên thành phẩm',        'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL039', 'name' => 'Chả mực thành phẩm',         'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL040', 'name' => 'Bạch tuộc viên thành phẩm', 'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL041', 'name' => 'Bò viên thành phẩm',         'unit' => 'kg',   'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL042', 'name' => 'Mandu thành phẩm',           'unit' => 'cái',  'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL043', 'name' => '7-Up lon',                   'unit' => 'lon',  'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL044', 'name' => 'Sting lon',                  'unit' => 'lon',  'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL045', 'name' => 'Trà xanh (gói)',             'unit' => 'gói',  'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Đồ uống Sài Gòn'],
            ['code' => 'NL046', 'name' => 'Soju vị táo',               'unit' => 'chai', 'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL047', 'name' => 'Soju vị đào',               'unit' => 'chai', 'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL048', 'name' => 'Soju vị dâu',               'unit' => 'chai', 'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
            ['code' => 'NL049', 'name' => 'Soju vị việt quất',         'unit' => 'chai', 'quantity_in_stock' => 0, 'min_stock_level' => 0, 'import_price' => 0, 'supplier' => 'Công ty TNHH Thực phẩm Hàn Quốc K-Food'],
        ];

        $ingredientIds = [];
        foreach ($ingredients as $i) {
            $existing = DB::table('ingredients')->where('code', $i['code'])->first();
            if ($existing) {
                $ingredientIds[$i['code']] = $existing->id;
                continue;
            }
            $ingredientIds[$i['code']] = DB::table('ingredients')->insertGetId([
                'code' => $i['code'],
                'name' => $i['name'],
                'unit' => $i['unit'],
                'quantity_in_stock' => $i['quantity_in_stock'],
                'min_stock_level' => $i['min_stock_level'],
                'import_price' => $i['import_price'],
                'import_date' => now()->toDateString(),
                'expiry_date' => now()->addMonths(3)->toDateString(),
                'supplier_id' => $supplierIds[$i['supplier']] ?? null,
                'ingredient_category_id' => $categoryMap[$this->categoryFor($i['code'])],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Map code -> id helper
        $id = fn (string $code) => $ingredientIds[$code] ?? null;
        /** Theo plan mục 4: NL trọng yếu để tự báo hết món (mì, thịt/hải sản, chả/viên, bánh gạo, phô mai, đồ uống đóng chai/lon).
         *  Rau/gia vị có lượng linh hoạt KHÔNG tự làm món hết. */
        $criticalCodes = [
            'NL001', // Mì tươi
            'NL002', // Mì Ý
            'NL003', // Thịt bò
            'NL004', // Thịt gà
            'NL005', // Chả cá
            'NL006', // Tôm sú
            'NL007', // Xúc xích Hàn Quốc
            'NL016', // Phô mai mozzarella
            'NL017', // Gạo tẻ (dùng cho kimbap, makkoli)
            'NL035', // Bánh gạo tokbokki
            'NL036', // Kim chi thành phẩm
            'NL037', // Gà viên thành phẩm
            'NL038', // Tôm viên thành phẩm
            'NL039', // Chả mực thành phẩm
            'NL040', // Bạch tuộc viên thành phẩm
            'NL041', // Bò viên thành phẩm
            'NL042', // Mandu thành phẩm
            // Đồ uống đóng chai/lon (bị thiếu thì món "Hết đồ uống" hợp lý).
            'NL020', 'NL021', 'NL022', 'NL023', 'NL024',
            'NL043', 'NL044', 'NL025', 'NL045',
            'NL046', 'NL047', 'NL048', 'NL049',
        ];
        $isCritical = static fn (string $code): bool => in_array($code, $criticalCodes, true);

        foreach ([
            ['code' => 'NL005', 'unit' => 'bịch', 'conversion' => 24],
            ['code' => 'NL007', 'unit' => 'bịch', 'conversion' => 5],
        ] as $package) {
            DB::table('ingredient_packagings')->updateOrInsert([
                'ingredient_id' => $id($package['code']), 'supplier_id' => null,
                'purchase_unit' => $package['unit'],
            ], [
                'conversion_quantity' => $package['conversion'],
                'is_default' => true, 'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        $menuItems = DB::table('menu_items')->select('id', 'slug', 'size', 'recipe_managed_at')->get()->keyBy('slug');

        // Định nghĩa 1 phần "mì cay base" cho size NHỎ.
        // Size LỚN nhân x1.5 phần chung; chả cá và xúc xích có số cục/cây riêng theo cỡ.
        $proteinRecipes = [
            'bo'         => ['NL003' => 0.15],
            'ga'         => ['NL004' => 0.15],
            'cha-ca'     => [],
            'hai-san'    => ['NL006' => 0.15],
            'muc'        => ['NL027' => 0.15],
            'bach-tuoc'  => ['NL028' => 0.15],
            'xuc-xich'   => [],
        ];
        $countableRecipes = [
            'cha-ca' => ['nho' => ['NL005' => 8], 'lon' => ['NL005' => 12]],
            'xuc-xich' => ['nho' => ['NL007' => 2], 'lon' => ['NL007' => 2, 'NL005' => 5]],
        ];

        // Phần nguyên liệu chung cho mọi mì cay (mì + gia vị cay + rau + trứng)
        $baseCommonSmall = [
            'NL001' => 1,
            'NL008' => 0.05,
            'NL012' => 0.10,
            'NL015' => 1,
            'NL010' => 0.01,
        ];

        foreach ($proteinRecipes as $proteinSlug => $proteinMap) {
            foreach (['nho', 'lon'] as $sizeLabel) {
                $sizeKey = $sizeLabel === 'nho' ? 'SMALL' : 'LARGE';
                $slug = "mi-cay-{$proteinSlug}-{$sizeLabel}";
                $menuItem = $menuItems->get($slug);
                if (!$menuItem) {
                    continue;
                }
                if ($menuItem->recipe_managed_at !== null || DB::table('menu_item_ingredient')->where('menu_item_id', $menuItem->id)->exists()) {
                    continue;
                }

                $multiplier = $sizeKey === 'LARGE' ? 1.5 : 1.0;

                $ingredients = [];
                foreach ($baseCommonSmall as $code => $qty) {
                    $ingredients[$code] = round($qty * $multiplier, 3);
                }
                foreach ($proteinMap as $code => $qty) {
                    $ingredients[$code] = round($qty * $multiplier, 3);
                }
                foreach ($countableRecipes[$proteinSlug][$sizeLabel] ?? [] as $code => $qty) {
                    $ingredients[$code] = $qty;
                }

                foreach ($ingredients as $code => $qty) {
                    DB::table('menu_item_ingredient')->insertOrIgnore([
                        'menu_item_id'        => $menuItem->id,
                        'ingredient_id'       => $id($code),
                        'quantity_required'   => $qty,
                        'waste_factor'        => 1.000,
                        'availability_required' => $isCritical($code),
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                }
            }
        }

        $recipes = [
            // Topping
            'them-trung'         => [$id('NL015') => 1],
            'them-pho-mai'       => [$id('NL016') => 0.05],
            'them-bo'            => [$id('NL003') => 0.05],
            'them-ga'            => [$id('NL004') => 0.10],
            'them-tom'           => [$id('NL006') => 0.08],
            'them-muc'           => [$id('NL027') => 0.05],
            'them-bach-tuoc'     => [$id('NL028') => 0.05],
            'them-cha-ca'        => [$id('NL005') => 5],
            'them-mi-y'          => [$id('NL002') => 1],
            'them-xuc-xich'      => [$id('NL007') => 1],
            'them-rau-thap-cam'  => [$id('NL012') => 0.10, $id('NL013') => 0.05, $id('NL014') => 0.05],
            'them-tokbokki'      => [$id('NL035') => 0.15, $id('NL008') => 0.02],

            // Món phụ
            'kimbap-truyen-thong'   => [$id('NL018') => 0.10, $id('NL017') => 0.20, $id('NL014') => 0.05, $id('NL013') => 0.05],
            'kimbap-chien'          => [$id('NL018') => 0.10, $id('NL017') => 0.20, $id('NL019') => 0.05, $id('NL014') => 0.05],
            'kimbap-pho-mai'        => [$id('NL018') => 0.10, $id('NL017') => 0.20, $id('NL014') => 0.05, $id('NL016') => 0.03],
            'tokbokki-pho-mai'      => [$id('NL035') => 0.30, $id('NL016') => 0.08, $id('NL008') => 0.03],
            'tokbokki-cay-cap-3'    => [$id('NL035') => 0.30, $id('NL008') => 0.08, $id('NL009') => 0.04],
            'cha-gio-re-han-quoc'   => [$id('NL019') => 0.10, $id('NL004') => 0.10],
            'ga-ran-han-quoc'       => [$id('NL004') => 0.25, $id('NL010') => 0.02, $id('NL011') => 0.01],
            'ga-ran-sot-cay'        => [$id('NL004') => 0.25, $id('NL008') => 0.06, $id('NL010') => 0.02],
            'mandu-banh-xep-chien'  => [$id('NL042') => 3],
            'bap-xao-pho-mai-han-quoc' => [$id('NL029') => 0.30, $id('NL016') => 0.05],

            // Tráng miệng
            'patbingsu-da-bao-han-quoc' => [$id('NL034') => 0.40, $id('NL024') => 0.20, $id('NL031') => 0.03],

            // Đồ uống
            'tra-sua-tran-chau'        => [$id('NL024') => 0.5, $id('NL025') => 0.01, $id('NL026') => 0.05],
            'tra-sua-han-quoc-chuseok' => [$id('NL024') => 0.5, $id('NL025') => 0.015, $id('NL026') => 0.05, $id('NL030') => 0.10],
            'tra-xanh-dao'             => [$id('NL025') => 0.005, $id('NL030') => 0.10],
            'soju-han-quoc'            => [$id('NL022') => 1],
            'soju-vi-dao'              => [$id('NL022') => 1, $id('NL030') => 0.10],
            'nuoc-gao-han-quoc-makkoli'=> [$id('NL017') => 0.05],
            'soda-dau'                 => [$id('NL033') => 1, $id('NL031') => 0.03],
            'soda-viet-quat'           => [$id('NL033') => 1, $id('NL032') => 0.03],
            'coca-cola'                => [$id('NL020') => 1],
            'pepsi'                    => [$id('NL021') => 1],
            'nuoc-suoi'                => [$id('NL023') => 1],
            'sua-hop-nho'              => [$id('NL024') => 1],
        ];

        foreach ($recipes as $slug => $items) {
            $menuItem = $menuItems->get($slug);
            if (!$menuItem) {
                continue;
            }
            if ($menuItem->recipe_managed_at !== null || DB::table('menu_item_ingredient')->where('menu_item_id', $menuItem->id)->exists()) {
                continue;
            }
            foreach ($items as $ingredientId => $qty) {
                if (!$ingredientId || !$qty) {
                    continue;
                }
                $code = array_search($ingredientId, $ingredientIds, true);
                DB::table('menu_item_ingredient')->insertOrIgnore([
                    'menu_item_id'        => $menuItem->id,
                    'ingredient_id'       => $ingredientId,
                    'quantity_required'   => $qty,
                    'waste_factor'        => 1.000,
                    'availability_required' => $code !== false && $isCritical($code),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }
        }
    }

    private function categoryFor(string $code): string
    {
        $number = (int) substr($code, 2);
        return match (true) {
            in_array($number, [1, 2, 17, 18, 19, 35], true) => 'Mì và tinh bột',
            in_array($number, [3, 4], true) => 'Thịt tươi',
            in_array($number, [6, 27, 28], true) => 'Hải sản tươi',
            in_array($number, [5, 7, 15, 37, 38, 39, 40, 41, 42], true) => 'Hàng chế biến sẵn',
            in_array($number, [12, 13, 14, 29, 36], true) => 'Rau củ',
            in_array($number, [8, 9, 10, 11, 16], true) => 'Sốt và gia vị',
            in_array($number, [20, 21, 22, 23, 43, 44, 46, 47, 48, 49], true) => 'Đồ uống',
            default => 'Nguyên liệu pha chế',
        };
    }
};
