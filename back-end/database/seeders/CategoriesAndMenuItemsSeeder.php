<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoriesAndMenuItemsSeeder extends Seeder
{
    public function run(): void
    {
       
        $slugsToDelete = [
            'cha-gio-re-han-quoc',
            'banh-mochi',
            'banh-bong-lan-trung-muoi',
            'sua-chua-dau-han-quoc',
            'che-sam-bo-luong',
            'patbingsu-da-bao-han-quoc',
            'nuoc-gao-han-quoc-makkoli',
            'soda-dau',             
            'soda-viet-quat',
            'nuoc-suoi',
            'sua-hop-nho',
        ];

        $deletedCount = DB::table('menu_items')
            ->whereIn('slug', $slugsToDelete)
            ->delete();
        $this->command?->info("Đã xoá {$deletedCount} món không có ảnh.");

        // ===== BƯỚC 2: Đảm bảo categories tồn tại =====
        $categories = [
            ['name' => 'Mì cay Hàn Quốc', 'description' => 'Mì cay chuẩn vị Hàn Quốc — chọn cấp độ cay 1-5 khi đặt', 'sort_order' => 1],
            ['name' => 'Topping thêm',    'description' => 'Topping thêm theo yêu cầu',                       'sort_order' => 2],
            ['name' => 'Món phụ',         'description' => 'Kimbap, tokbokki, chả giò, …',                    'sort_order' => 3],
            ['name' => 'Tráng miệng',     'description' => 'Bánh, chè và tráng miệng Hàn Quốc',               'sort_order' => 4],
            ['name' => 'Đồ uống',         'description' => 'Nước uống giải cay và trà sữa',                   'sort_order' => 5],
        ];

        $categoryIds = [];
        foreach ($categories as $cat) {
            $slug = Str::slug($cat['name']);
            $existing = DB::table('categories')->where('slug', $slug)->first();
            if ($existing) {
                $categoryIds[$slug] = $existing->id;
                continue;
            }
            $categoryIds[$slug] = DB::table('categories')->insertGetId($cat + [
                'slug' => $slug,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ===== BƯỚC 3: Mì cay (8 biến thể × 2 size = 16 món) =====
        $variants = [
            ['protein' => 'bò',         'price_small' => 69000, 'price_large' => 89000, 'protein_ingredient' => 'NL003', 'image_key' => 'mi-cay-bo'],
            ['protein' => 'gà',         'price_small' => 65000, 'price_large' => 85000, 'protein_ingredient' => 'NL004', 'image_key' => 'mi-cay-ga'],
            ['protein' => 'chả cá',     'price_small' => 65000, 'price_large' => 85000, 'protein_ingredient' => 'NL005', 'image_key' => 'mi-cay-cha-ca'],
            ['protein' => 'hải sản',    'price_small' => 79000, 'price_large' => 99000, 'protein_ingredient' => 'NL006', 'image_key' => 'mi-cay-hai-san'],
            ['protein' => 'mực',        'price_small' => 79000, 'price_large' => 99000, 'protein_ingredient' => 'NL027', 'image_key' => 'mi-cay-muc'],
            ['protein' => 'bạch tuộc',  'price_small' => 89000, 'price_large' => 109000,'protein_ingredient' => 'NL028', 'image_key' => 'mi-cay-bach-tuot'],
            ['protein' => 'xúc xích',   'price_small' => 65000, 'price_large' => 85000, 'protein_ingredient' => 'NL007', 'image_key' => 'mi-cay-xuc-xich'],
            ['protein' => 'thập cẩm',   'price_small' => 80000, 'price_large' => 100000, 'image_key' => 'mi-cay-thap-cam'],
        ];

        foreach ($variants as $v) {
            foreach (['SMALL', 'LARGE'] as $size) {
                $name = sprintf('Mì cay %s %s', $v['protein'], $size === 'SMALL' ? 'nhỏ' : 'lớn');
                $slug = Str::slug($name);

                $exists = DB::table('menu_items')
                    ->where('slug', $slug)
                    ->orWhere(fn ($query) => $query
                        ->where('category_id', $categoryIds['mi-cay-han-quoc'])
                        ->where('name', $name)
                        ->where('size', $size))
                    ->first();
                if ($exists) {
                    continue;
                }

                $price = $size === 'SMALL' ? $v['price_small'] : $v['price_large'];

                DB::table('menu_items')->insert([
                    'category_id' => $categoryIds['mi-cay-han-quoc'],
                    'name' => $name,
                    'slug' => $slug,
                    'description' => sprintf(
                        'Mì cay %s %s — chọn cấp độ cay 1-5 khi đặt',
                        $v['protein'],
                        $size === 'SMALL' ? 'phần nhỏ' : 'phần lớn'
                    ),
                    'spicy_level' => 0,
                    'size' => $size,
                    'image_url' => $this->menuImage($v['image_key']),
                    'price' => $price,
                    'cost_price' => (int) round($price * 0.4),
                    'is_available' => true,
                    'is_active' => true,
                    'preparation_minutes' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ===== BƯỚC 4: Topping thêm =====
        $toppings = [
            ['name' => 'Thêm trứng',           'price' => 10000, 'preparation_minutes' => 2, 'image_key' => 'bo-them'],
            ['name' => 'Thêm gà',         'price' => 20000, 'preparation_minutes' => 3, 'image_key' => 'ga-them'],
            ['name' => 'Thêm tôm ',        'price' => 25000, 'preparation_minutes' => 3, 'image_key' => 'tom-them'],
            ['name' => 'Thêm mực',         'price' => 28000, 'preparation_minutes' => 3, 'image_key' => 'muc-them'],
            ['name' => 'Thêm bạch tuộc',  'price' => 30000, 'preparation_minutes' => 3, 'image_key' => 'bach-tuot-them'],
            ['name' => 'Thêm chả cá',          'price' => 15000, 'preparation_minutes' => 2, 'image_key' => 'cha-ca-them'],
            ['name' => 'Thêm bò ',         'price' => 20000, 'preparation_minutes' => 3, 'image_key' => 'bo-them'],
            ['name' => 'Thêm xúc xích',        'price' => 12000, 'preparation_minutes' => 2, 'image_key' => 'xuc-xich-them'],
            ['name' => 'Thêm kim chi',         'price' => 12000, 'preparation_minutes' => 2, 'image_key' => 'kim-chi-them'],
            ['name' => 'Thêm phô mai',         'price' => 15000, 'preparation_minutes' => 2, 'image_key' => 'pho-mai-them'],
            ['name' => 'Thêm rau',             'price' => 12000, 'preparation_minutes' => 2, 'image_key' => 'rau-them'],
            ['name' => 'Thêm tokbokki',        'price' => 18000, 'preparation_minutes' => 3, 'image_key' => 'tokbokki-them'],
        ];
        $this->insertItemsByCategory($toppings, $categoryIds['topping-them']);

        // ===== BƯỚC 5: Món phụ (đã xoá "Chả giò rế" — chưa có ảnh) =====
        $sides = [
            ['name' => 'Kimbap truyền thống',         'price' => 45000, 'preparation_minutes' => 8,  'image_key' => 'kimbap-truyen-thong'],
            ['name' => 'Kimbap chiên',                'price' => 55000, 'preparation_minutes' => 10, 'image_key' => 'kimbap-chien'],
            ['name' => 'Kimbap phô mai',              'price' => 55000, 'preparation_minutes' => 10, 'image_key' => 'kimbap-pho-mai'],
            ['name' => 'Tokbokki phô mai',            'price' => 65000, 'preparation_minutes' => 10, 'image_key' => 'tokbokki-pho-mai'],
            ['name' => 'Tokbokki cay cấp 3',          'price' => 55000, 'preparation_minutes' => 10, 'image_key' => 'tokbokki-them'],
            ['name' => 'Gà rán Hàn Quốc',             'price' => 75000, 'preparation_minutes' => 12, 'image_key' => 'ga-ran-han-quoc'],
            ['name' => 'Gà rán sốt cay',              'price' => 79000, 'preparation_minutes' => 12, 'image_key' => 'ga-ran-sot-cay'],
            ['name' => 'Mandu (bánh xếp) chiên',      'price' => 50000, 'preparation_minutes' => 8,  'image_key' => 'mandu-chien'],
            ['name' => 'Bắp xào phô mai Hàn Quốc',    'price' => 45000, 'preparation_minutes' => 8,  'image_key' => 'bap-xao-pho-mai'],
        ];
        $this->insertItemsByCategory($sides, $categoryIds['mon-phu']);

        // ===== BƯỚC 6: Đồ uống =====
        $drinks = [
            // Trà
            ['name' => 'Trà sữa trân châu',                'price' => 45000, 'preparation_minutes' => 5, 'image_key' => 'tra-sua'],
            ['name' => 'Trà sữa Hàn Quốc (Chuseok)',       'price' => 49000, 'preparation_minutes' => 5, 'image_key' => 'tra-sua-han-quoc'],
            ['name' => 'Trà xanh đào',                     'price' => 35000, 'preparation_minutes' => 4, 'image_key' => 'tra-dao'],
            // Soju
            ['name' => 'Soju Hàn Quốc (vị táo)',           'price' => 85000, 'preparation_minutes' => 1, 'image_key' => 'soju-tao'],
            ['name' => 'Soju vị đào',                      'price' => 95000, 'preparation_minutes' => 1, 'image_key' => 'soju-dao'],
            ['name' => 'Soju vị dâu',                      'price' => 95000, 'preparation_minutes' => 1, 'image_key' => 'soju-dau'],
            ['name' => 'Soju vị việt quất',                'price' => 95000, 'preparation_minutes' => 1, 'image_key' => 'soju-viet-quoc'],
            // Nước ngọt có ảnh
            ['name' => 'Coca Cola',                         'price' => 20000, 'preparation_minutes' => 1, 'image_key' => 'coca'],
            ['name' => 'Pepsi',                             'price' => 20000, 'preparation_minutes' => 1, 'image_key' => 'pepsi'],
            ['name' => '7-Up',                              'price' => 20000, 'preparation_minutes' => 1, 'image_key' => '7-up'],
            ['name' => 'Sting',                             'price' => 20000, 'preparation_minutes' => 1, 'image_key' => 'sting'],
            ['name' => 'Soda dâu',                          'price' => 25000, 'preparation_minutes' => 2, 'image_key' => 'soda-dau'],
        ];
        $this->insertItemsByCategory($drinks, $categoryIds['do-uong']);
    }

    /**
     * Trả về URL ảnh menu để frontend dùng được ngay.
     *
     * Ảnh nằm trong `storage/app/public/menu/<file>` và được serve qua
     * symlink `public/storage` → `storage/app/public`.
     * Frontend (hàm imageUrl() trong src/utils/image.ts) hiểu path bắt đầu bằng `/`
     * là đường dẫn tuyệt đối tới backend và ghép với API origin.
     *
     * Trả về null nếu không có ảnh — lúc đó MenuService sẽ fallback sang placeholder.
     */
    private function menuImage(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        return '/storage/menu/' . $key . '.png';
    }

    private function insertItemsByCategory(array $items, int $categoryId): void
    {
        foreach ($items as $item) {
            $slug = Str::slug($item['name']);
            $exists = DB::table('menu_items')->where('slug', $slug)->first();
            if ($exists) {
                continue;
            }
            DB::table('menu_items')->insert([
                'category_id' => $categoryId,
                'name' => $item['name'],
                'slug' => $slug,
                'description' => null,
                'spicy_level' => 0,
                'size' => null,
                'image_url' => $this->menuImage($item['image_key'] ?? null),
                'price' => $item['price'],
                'cost_price' => (int) round($item['price'] * 0.4),
                'is_available' => true,
                'is_active' => true,
                'preparation_minutes' => $item['preparation_minutes'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
