<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Công thức sổ kho ngày khi đã có tự trừ kho (Phase F).
 *
 * Bối cảnh quan trọng:
 *  - Từ Phase F, khi bếp bắt đầu nấu, nguyên liệu ĐÃ bị trừ khỏi tồn hệ thống.
 *  - Sổ kho ngày so "tồn đầu" với "tồn bếp đếm" để ra hao hụt thực tế.
 *  - "Dùng dự kiến" lấy từ công thức món đã nấu.
 *
 * Rủi ro cần chặn: cột tiêu hao bán bị trừ 2 lần (1 lần trong movement,
 * 1 lần trong usage) → tồn dự kiến lệch với tồn thật.
 */
class DailyStockSheetFormulaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->boolean('auto_availability_enabled')->default(false);
            $table->timestamp('recipe_managed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('unit', 20)->default('g');
            $table->decimal('quantity_in_stock', 12, 2)->default(0);
            $table->decimal('min_stock_level', 12, 2)->default(0);
            $table->decimal('import_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_item_ingredient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id');
            $table->foreignId('ingredient_id');
            $table->decimal('quantity_required', 12, 3)->default(0);
            $table->decimal('waste_factor', 6, 3)->default(1);
            $table->string('unit_override', 20)->nullable();
            $table->boolean('availability_required')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('order_type', 20)->default('DINE_IN');
            $table->string('status', 20)->default('PENDING');
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('menu_item_id');
            $table->string('item_name', 150);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->enum('status', ['PENDING', 'PREPARING', 'READY', 'SERVED', 'CANCELLED'])->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('recipe_snapshot')->nullable();
            $table->timestamp('recipe_captured_at')->nullable();
            $table->timestamp('inventory_deducted_at')->nullable();
            $table->uuid('inventory_deduction_key')->nullable();
            $table->timestamps();
        });

        Schema::create('order_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id');
            $table->foreignId('topping_menu_item_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id');
            $table->enum('type', ['IMPORT', 'EXPORT', 'ADJUSTMENT', 'WASTE']);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('performed_by')->nullable();
            $table->text('note')->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->decimal('stock_before', 12, 2)->nullable();
            $table->decimal('stock_after', 12, 2)->nullable();
            $table->timestamp('transacted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('daily_stock_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('business_date', 10)->unique();
            $table->string('status', 20)->default('NEW');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('closed_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('movement_version')->default(0);
            $table->timestamps();
        });

        Schema::create('daily_stock_sheet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_stock_sheet_id');
            $table->foreignId('ingredient_id');
            $table->decimal('opening_qty', 14, 3)->nullable();
            $table->decimal('incoming_qty', 14, 3)->default(0);
            $table->decimal('outgoing_qty', 14, 3)->default(0);
            $table->decimal('consumption_qty', 14, 3)->default(0);
            $table->decimal('correction_qty', 14, 3)->default(0);
            $table->decimal('counted_qty', 14, 3)->nullable();
            $table->decimal('used_qty', 14, 3)->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->timestamps();
        });

        Schema::create('ingredient_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
        });

        // Đăng nhập sẵn bằng user KITCHEN (bếp được xem/chốt sổ kho ngày).
        $user = User::create([
            'name' => 'Bếp Test',
            'email' => 'kitchen-sheet@test.local',
            'password' => 'secret1234',
            'is_active' => true,
        ]);
        DB::table('roles')->insert(['code' => 'KITCHEN', 'name' => 'Bếp']);
        DB::table('user_role')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('code', 'KITCHEN')->value('id'),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        // Cố định đồng hồ để "hôm nay" và mốc chốt sổ xác định.
        // App dùng timezone UTC → 20:00 giờ VN = 13:00 UTC.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-23 13:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Kịch bản thật trong ngày 23/10:
     *   - Tồn đầu kỳ (đếm cuối 22/10): 1000g mì.
     *   - Bếp nấu 2 phần, công thức 200g/phần → hệ thống tự trừ 400g, tồn còn 600g.
     *   - Cuối ngày bếp đếm thực tế còn 550g (hao hụt 50g, ví dụ bếp dùng thêm bột ớt).
     *
     * Kết quả đúng:
     *   - Tiêu hao bán = 400g (hệ thống tự trừ).
     *   - Xuất/hủy = 0 (bếp không xuất thủ công).
     *   - Đã dùng (used) = 1000 − 550 = 450g.
     *   - Dùng dự kiến (theo công thức) = 400g.
     *   - Chênh lệch = 450 − 400 = 50g → hao hụt thực tế.
     *   - Tồn dự kiến = 1000 − 400 = 600g (KHỚP tồn hệ thống).
     *
     * Trước khi sửa, tồn dự kiến ra 200g (trừ 2 lần) → sai.
     */
    public function test_ton_du_kien_khop_ton_he_thong_khi_da_co_tu_tru_kho(): void
    {
        $ingredient = $this->ingredient('NL001', 'Mì tươi', 600);
        $menu = $this->menu();
        MenuItemIngredient::create([
            'menu_item_id' => $menu->id, 'ingredient_id' => $ingredient->id,
            'quantity_required' => 200, 'waste_factor' => 1.0,
        ]);

        // Bếp nấu 2 phần → trừ 400g. Tồn còn 600g.
        $this->cookedItem($menu, $ingredient, quantity: 2, required: 400, deducted: 400);
        $this->assertSame(600.0, (float) $ingredient->fresh()->quantity_in_stock);

        $this->previousSheet($ingredient, opening: 1000);
        $this->todaySheet($ingredient, counted: 550);

        $row = $this->showRow('2026-10-23', $ingredient->id);

        $this->assertEqualsWithDelta(400.0, $row['consumption_qty'], 0.001, 'Tiêu hao bán phải là 400g.');
        $this->assertEqualsWithDelta(0.0, $row['outgoing_qty'], 0.001, 'Không có xuất thủ công.');
        $this->assertEqualsWithDelta(400.0, $row['expected_qty'], 0.001, 'Dùng dự kiến theo công thức = 400g.');
        $this->assertEqualsWithDelta(450.0, $row['used_qty'], 0.001, 'Đã dùng = tồn đầu − tồn đếm = 450g.');
        $this->assertEqualsWithDelta(50.0, $row['variance_qty'], 0.001, 'Chênh lệch = hao hụt thực tế 50g.');
        $this->assertEqualsWithDelta(600.0, $row['expected_balance_qty'], 0.001, 'Tồn dự kiến phải bằng tồn hệ thống (600g).');
    }

    /**
     * Xuất thủ công (bếp hủy món, hư hỏng) KHÔNG được gộp vào tiêu hao bán.
     *
     * Nếu gộp chung, cột "Xuất/hủy" và "Dùng dự kiến" cùng nói về một con số
     * → quản lý nhìn bảng thấy trùng và tưởng hao hụt sai.
     */
    public function test_xuat_thu_cong_tach_khoi_tieu_hao_ban(): void
    {
        $ingredient = $this->ingredient('NL002', 'Thịt bò', 700);
        $menu = $this->menu();
        MenuItemIngredient::create([
            'menu_item_id' => $menu->id, 'ingredient_id' => $ingredient->id,
            'quantity_required' => 200, 'waste_factor' => 1.0,
        ]);

        $this->cookedItem($menu, $ingredient, quantity: 2, required: 400, deducted: 400);

        // Bếp xuất thủ công 100g (hủy món do khách đổi ý).
        InventoryTransaction::create([
            'ingredient_id' => $ingredient->id, 'type' => 'EXPORT', 'quantity' => -100,
            'reference_type' => null, 'note' => 'Hủy món do khách đổi ý',
            'transacted_at' => '2026-10-23 11:00:00',
        ]);

        $this->previousSheet($ingredient, opening: 1000);
        // Tồn thực: 1000 − 400 (nấu) − 100 (xuất) = 500g.
        $this->todaySheet($ingredient, counted: 500);

        $row = $this->showRow('2026-10-23', $ingredient->id);

        $this->assertEqualsWithDelta(400.0, $row['consumption_qty'], 0.001, 'Tiêu hao bán = 400g.');
        $this->assertEqualsWithDelta(100.0, $row['outgoing_qty'], 0.001, 'Xuất thủ công = 100g.');
        $this->assertEqualsWithDelta(400.0, $row['expected_qty'], 0.001, 'Dùng dự kiến theo công thức = 400g.');
        $this->assertEqualsWithDelta(400.0, $row['used_qty'], 0.001, 'Đã dùng cho món = 400g.');
        $this->assertEqualsWithDelta(0.0, $row['variance_qty'], 0.001, 'Không hao hụt → chênh lệch 0.');
        $this->assertEqualsWithDelta(500.0, $row['expected_balance_qty'], 0.001, 'Tồn dự kiến = 1000 − 100 − 400 = 500g.');
    }

    // ====== Helpers ======

    private function ingredient(string $code, string $name, float $stock): Ingredient
    {
        return Ingredient::create([
            'code' => $code, 'name' => $name, 'unit' => 'g',
            'quantity_in_stock' => $stock, 'min_stock_level' => 10, 'import_price' => 1000,
        ]);
    }

    private function menu(): MenuItem
    {
        return MenuItem::create([
            'name' => 'Mì cay bò', 'slug' => 'mi-cay-bo-'.uniqid(), 'price' => 69000,
            'recipe_managed_at' => now(),
        ]);
    }

    /** Món đã bắt đầu nấu + giao dịch trừ kho tương ứng (trong ngày 23/10). */
    private function cookedItem(MenuItem $menu, Ingredient $ingredient, int $quantity, float $required, float $deducted): OrderItem
    {
        $order = Order::create(['code' => 'DH'.uniqid(), 'order_type' => 'DINE_IN', 'status' => 'PREPARING']);
        $item = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menu->id, 'item_name' => $menu->name,
            'unit_price' => $menu->price, 'quantity' => $quantity, 'subtotal' => $menu->price * $quantity,
            'status' => 'PREPARING',
        ]);
        $item->forceFill([
            'recipe_snapshot' => [[
                'ingredient_id' => $ingredient->id,
                'quantity' => $required,
                'unit' => 'g',
                'comparable' => true,
            ]],
            'recipe_captured_at' => '2026-10-23 05:00:00',
            'inventory_deducted_at' => '2026-10-23 05:00:00',
            'inventory_deduction_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->save();

        InventoryTransaction::create([
            'ingredient_id' => $ingredient->id, 'type' => 'EXPORT', 'quantity' => -$deducted,
            'reference_type' => 'order_item', 'reference_id' => $item->id,
            'note' => 'Tiêu hao món', 'transacted_at' => '2026-10-23 05:00:00',
        ]);

        return $item->fresh();
    }

    /** Sổ kho kỳ trước (22/10) đã chốt — làm mốc tồn đầu cho ngày 23/10. */
    private function previousSheet(Ingredient $ingredient, float $opening): void
    {
        $sheetId = DB::table('daily_stock_sheets')->insertGetId([
            'business_date' => '2026-10-22', 'status' => 'CLOSED',
            'counted_at' => '2026-10-22 16:00:00', 'closed_at' => '2026-10-22 16:00:00',
            'movement_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_stock_sheet_items')->insert([
            'daily_stock_sheet_id' => $sheetId, 'ingredient_id' => $ingredient->id,
            'opening_qty' => $opening, 'incoming_qty' => 0, 'outgoing_qty' => 0,
            'consumption_qty' => 0, 'correction_qty' => 0,
            'counted_qty' => $opening, 'used_qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Sổ kho hôm nay (23/10) ở trạng thái nháp, đã nhập số bếp đếm. */
    private function todaySheet(Ingredient $ingredient, float $counted): void
    {
        $sheetId = DB::table('daily_stock_sheets')->insertGetId([
            'business_date' => '2026-10-23', 'status' => 'DRAFT',
            'movement_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_stock_sheet_items')->insert([
            'daily_stock_sheet_id' => $sheetId, 'ingredient_id' => $ingredient->id,
            'counted_qty' => $counted,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Gọi API hiển thị sổ và trả về dòng của nguyên liệu cần xem. */
    private function showRow(string $date, int $ingredientId): array
    {
        $response = $this->getJson("/api/staff/daily-stock-sheets/{$date}");
        $response->assertOk();
        foreach ($response->json('data.items') as $item) {
            if ((int) $item['ingredient_id'] === $ingredientId) return $item;
        }
        $this->fail('Không thấy nguyên liệu trong sổ kho.');
    }
}
