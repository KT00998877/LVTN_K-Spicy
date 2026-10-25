<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract của tự trừ kho theo món (Phase F).
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local.
 *
 * Trọng tâm là các ràng buộc nghiệp vụ rủi ro cao:
 *  - Tồn kho KHÔNG BAO GIỜ âm khi thiếu nguyên liệu (clamp về 0).
 *  - Trừ đúng 1 lần (idempotency) dù bếp bấm nấu nhiều lần.
 *  - Thiếu nguyên liệu → bếp phải được hỏi trước (chưa đồng ý thì không trừ).
 *  - Đồng ý nấu tiếp → tạo yêu cầu nhập kho + báo quản lý.
 */
class InventoryConsumptionTest extends TestCase
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

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->string('sold_out_reason', 255)->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('unit', 20);
            $table->decimal('quantity_in_stock', 12, 2)->default(0);
            $table->decimal('min_stock_level', 12, 2)->default(0);
            $table->decimal('import_price', 12, 2)->default(0);
            $table->date('import_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('supplier_id')->nullable();
            $table->foreignId('ingredient_category_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_item_ingredient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id');
            $table->foreignId('ingredient_id');
            $table->decimal('quantity_required', 12, 3);
            $table->decimal('waste_factor', 5, 3)->default(1.000);
            $table->string('unit_override', 20)->nullable();
            $table->boolean('availability_required')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('order_type', 20)->default('DINE_IN');
            $table->string('status', 20)->default('PENDING');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
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

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->string('event', 60);
            $table->string('status', 30)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('order_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id');
            $table->foreignId('topping_menu_item_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
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

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('menu_item_id')->nullable();
            $table->string('menu_item_name', 150)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->enum('status', ['OPEN', 'RESOLVED', 'DISMISSED'])->default('OPEN');
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id');
            $table->foreignId('ingredient_id');
            $table->decimal('required_qty', 12, 3);
            $table->decimal('available_qty', 12, 3);
            $table->decimal('shortage_qty', 12, 3);
            $table->string('unit', 20);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->string('deduplication_key')->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    // ====== Tình huống: đủ nguyên liệu ======

    public function test_tru_kho_dung_cong_thuc_khi_bep_bam_bat_dau_nau(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);
        $item = $this->orderItem($menu, quantity: 2);

        $result = $this->service()->deductForItem($item, $kitchen);

        $this->assertTrue($result['deducted']);
        $this->assertSame([], $result['shortages']);
        // 2 phần × 50g = 100g trừ từ 100g còn 0.
        $this->assertSame(0.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);
    }

    public function test_tru_mot_lan_duy_nhat_ke_du_bep_bam_nhieu_lan(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        $this->service()->deductForItem($item, $kitchen);
        $second = $this->service()->deductForItem($item, $kitchen);

        // Lần 2 bị bỏ qua → tồn không bị trừ 2 lần.
        $this->assertFalse($second['deducted']);
        $this->assertSame(50.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);
    }

    // ====== Tình huống: thiếu nguyên liệu ======

    public function test_thieu_nguyen_lieu_bep_phai_duoc_hoi_truoc_khi_tru(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 30, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        $result = $this->service()->deductForItem($item, $kitchen);

        $this->assertFalse($result['deducted']);
        $this->assertCount(1, $result['shortages']);
        $this->assertSame(50.0, $result['shortages'][0]['required']);
        $this->assertSame(30.0, $result['shortages'][0]['available']);
        $this->assertSame(20.0, $result['shortages'][0]['shortage']);
        // Chưa đồng ý nấu tiếp → KHÔNG đụng tồn kho.
        $this->assertSame(30.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);
    }

    public function test_ton_kho_khong_bao_gio_am_khi_bep_dong_y_nau_tiep(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 30, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        $result = $this->service()->deductForItem($item, $kitchen, allowShortage: true);

        $this->assertTrue($result['deducted']);
        $this->assertCount(1, $result['shortages']);
        // Tồn clamp về 0 — KHÔNG âm.
        $this->assertSame(0.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);
    }

    public function test_bep_dong_y_nau_tiep_se_tao_vao_bao_quan_ly(): void
    {
        [$kitchen, $menu] = $this->fixture(stock: 30, required: 50);
        $manager = $this->user('MANAGER');
        $item = $this->orderItem($menu, quantity: 1);

        $result = $this->service()->deductForItem($item, $kitchen, allowShortage: true);

        $this->assertNotNull($result['request_code']);
        $this->assertDatabaseHas('stock_requests', [
            'code' => $result['request_code'],
            'status' => 'OPEN',
            'menu_item_name' => $menu->name,
        ]);
        $this->assertDatabaseHas('stock_request_items', [
            'stock_request_id' => \DB::table('stock_requests')->where('code', $result['request_code'])->value('id'),
            'shortage_qty' => 20,
        ]);
        // Quản lý nhận được thông báo.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $manager->id,
            'deduplication_key' => 'STOCK_REQUEST:'
                .\DB::table('stock_requests')->where('code', $result['request_code'])->value('id')
                .':'.$manager->id,
        ]);
    }

    // ====== Hoàn kho ======

    public function test_huy_mon_da_nau_se_hoan_lai_dung_phan_da_tru(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        $this->service()->deductForItem($item, $kitchen);
        $this->assertSame(50.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);

        $this->service()->restoreForItem($item, $kitchen, 'Hết nguyên liệu');

        $this->assertSame(100.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);
        // Cờ trừ được reset → nấu lại sẽ trừ lại từ đầu.
        $this->assertNull($item->fresh()->inventory_deducted_at);
    }

    // ====== Ẩn món khỏi thực đơn ======

    public function test_huy_mon_vi_het_nguyen_lieu_se_an_mon_khoi_thuc_don(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 30, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        // Món có nguyên liệu trọng yếu → bếp hủy vì hết nguyên liệu sẽ ẩn món.
        \DB::table('menu_item_ingredient')->where('menu_item_id', $menu->id)
            ->update(['availability_required' => true]);

        $hidden = $this->service()->hideMenuItemForShortage($menu->id, 'Hết nguyên liệu', [$noodles->id]);

        $this->assertTrue($hidden);
        $menu->refresh();
        $this->assertFalse((bool) $menu->is_available);
        $this->assertStringContainsString('Hết nguyên liệu', (string) $menu->sold_out_reason);
    }

    public function test_thieu_nguyen_lieu_phu_khong_an_mon(): void
    {
        [$kitchen, $menu] = $this->fixture(stock: 30, required: 50);
        // Không đánh dấu trọng yếu → món vẫn nấu được, không ẩn.
        \DB::table('menu_item_ingredient')->where('menu_item_id', $menu->id)
            ->update(['availability_required' => false]);

        $hidden = $this->service()->hideMenuItemForShortage($menu->id, 'Hết nguyên liệu', [1]);

        $this->assertFalse($hidden);
        $this->assertTrue((bool) $menu->fresh()->is_available);
    }

    /**
     * Món CÓ nguyên liệu trọng yếu trong công thức, nhưng nguyên liệu đang THIẾU
     * chỉ là gia vị phụ → không được ẩn món khỏi thực đơn.
     *
     * Lỗi này rất dễ xảy ra nếu kiểm tra "công thức có trọng yếu không" thay vì
     * "nguyên liệu đang thiếu có trọng yếu không".
     */
    public function test_mon_co_nguyen_lieu_trong_yieu_nhung_thieu_gia_vi_phu_khong_an_mon(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);

        // Thêm 1 nguyên liệu phụ (gia vị) vào công thức, tồn = 0.
        $spice = \App\Models\Ingredient::create([
            'code' => 'NL999', 'name' => 'Bột ớt', 'unit' => 'g',
            'quantity_in_stock' => 0, 'is_active' => true,
        ]);
        \DB::table('menu_item_ingredient')->insert([
            'menu_item_id' => $menu->id, 'ingredient_id' => $spice->id,
            'quantity_required' => 50, 'waste_factor' => 1.0,
            'availability_required' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Mì vẫn là trọng yếu (nhưng vẫn còn đủ tồn).
        \DB::table('menu_item_ingredient')->where('menu_item_id', $menu->id)
            ->where('ingredient_id', $noodles->id)
            ->update(['availability_required' => true]);

        $hidden = $this->service()->hideMenuItemForShortage($menu->id, 'Hết nguyên liệu', [$spice->id]);

        $this->assertFalse($hidden);
        $this->assertTrue((bool) $menu->fresh()->is_available);
    }

    /**
     * Luồng đầy đủ: bếp bấm nấu → hệ thống báo thiếu → bếp chọn "Hủy món".
     *
     * Món phải bị ẩn khỏi thực đơn (dù món chưa từng vào PREPARING).
     */
    public function test_luong_bep_huy_mon_thi_hieu_se_an_mon_khoi_menu(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 30, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        \DB::table('menu_item_ingredient')->where('menu_item_id', $menu->id)
            ->update(['availability_required' => true]);

        // Bếp bấm nấu → thiếu nguyên liệu → phải ném lỗi 409, món chưa đổi trạng thái.
        try {
            app(\App\Services\StaffOrderService::class)->updateItemStatus(
                $item->order_id, $item->id, 'PREPARING', null, $kitchen
            );
            $this->fail('Phải báo thiếu nguyên liệu cho bếp quyết định.');
        } catch (\App\Exceptions\ShortageDetectedException $e) {
            $this->assertNotEmpty($e->shortages);
        }
        $this->assertSame('PENDING', $item->fresh()->status);

        // Bếp chọn "Hủy món" vì hết nguyên liệu.
        app(\App\Services\StaffOrderService::class)->updateItemStatus(
            $item->order_id, $item->id, 'CANCELLED', 'Hết nguyên liệu', $kitchen
        );

        $this->assertSame('CANCELLED', $item->fresh()->status);
        $menu->refresh();
        $this->assertFalse((bool) $menu->is_available, 'Món hết mì gói phải bị ẩn khỏi thực đơn.');
        $this->assertStringContainsString('Hết nguyên liệu', (string) $menu->sold_out_reason);
    }

    // ====== Bảo vệ khỏi trừ 2 lần ======

    /**
     * Sau khi bếp đã trừ kho thật, tồn khả dụng KHÔNG được trừ thêm lượng dự kiến.
     *
     * Nếu sai, mọi món sẽ bị trừ kho 2 lần → tồn âm nhanh, bếp luôn thấy thiếu.
     */
    public function test_ton_kha_dung_khong_bi_tru_hai_lan(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        // Bếp nấu → trừ kho thật 50g, còn 50g.
        $this->service()->deductForItem($item, $kitchen);
        $this->assertSame(50.0, (float) Ingredient::find($noodles->id)->quantity_in_stock);

        // Tính tồn khả dụng: phải là 50g (đã trừ 1 lần), KHÔNG phải 0g (trừ 2 lần).
        $undeducted = app(\App\Services\ExpectedUsageService::class)
            ->undeductedBetween(now()->subDay(), now()->addDay());

        $this->assertSame(0.0, (float) ($undeducted['by_ingredient'][$noodles->id]['quantity'] ?? 0.0));
    }

    /**
     * Món nấu trước khi có Phase F (chưa trừ kho) vẫn phải được trừ khi tính tồn.
     */
    public function test_mon_chua_tru_kho_van_bi_tru_khi_tinh_ton(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 100, required: 50);
        $item = $this->orderItem($menu, quantity: 1);

        // Món có snapshot nhưng chưa có inventory_deducted_at (nấu trước Phase F).
        $undeducted = app(\App\Services\ExpectedUsageService::class)
            ->undeductedBetween(now()->subDay(), now()->addDay());

        $this->assertSame(50.0, (float) ($undeducted['by_ingredient'][$noodles->id]['quantity'] ?? 0.0));
    }

    // ====== Báo cáo tiêu hao ======

    public function test_bao_cao_tieu_hao_theo_mon(): void
    {
        [$kitchen, $menu, $noodles] = $this->fixture(stock: 500, required: 50);
        $item = $this->orderItem($menu, quantity: 2);

        $this->service()->deductForItem($item, $kitchen);

        $report = app(\App\Services\ConsumptionReportService::class)
            ->byMenuItem(now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $report);
        $this->assertSame($menu->name, $report[0]['menu_item_name']);
        $this->assertSame(2, $report[0]['total_quantity']);
        $this->assertSame(100.0, $report[0]['ingredients'][0]['quantity_required']);
        $this->assertSame(100.0, $report[0]['ingredients'][0]['quantity_deducted']);
    }

    public function test_bao_cao_tieu_hao_theo_nguyen_lieu_co_ton_hien_tai(): void
    {
        [$kitchen, $menu] = $this->fixture(stock: 500, required: 50);
        $item = $this->orderItem($menu, quantity: 2);

        $this->service()->deductForItem($item, $kitchen);

        $report = app(\App\Services\ConsumptionReportService::class)
            ->byIngredient(now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $report);
        $this->assertSame(400.0, $report[0]['current_stock']);
        $this->assertSame(100.0, $report[0]['consumed_qty']);
    }

    // ====== Helpers ======

    private function service(): \App\Services\InventoryConsumptionService
    {
        return app(\App\Services\InventoryConsumptionService::class);
    }

    /**
     * @return array{0: User, 1: MenuItem, 2: Ingredient}
     */
    private function fixture(float $stock, float $required): array
    {
        $kitchen = $this->user('KITCHEN');
        $this->user('MANAGER');

        $menu = MenuItem::create([
            'name' => 'Mì cay bò', 'slug' => 'mi-cay-bo-'.uniqid(), 'price' => 69000,
        ]);
        $noodles = Ingredient::create([
            'code' => 'NL-'.uniqid(), 'name' => 'Mì tươi', 'unit' => 'g',
            'quantity_in_stock' => $stock, 'min_stock_level' => 10, 'import_price' => 8000,
        ]);
        MenuItemIngredient::create([
            'menu_item_id' => $menu->id, 'ingredient_id' => $noodles->id,
            'quantity_required' => $required, 'waste_factor' => 1.000,
        ]);

        return [$kitchen, $menu, $noodles];
    }

    private function orderItem(MenuItem $menu, int $quantity): OrderItem
    {
        $order = Order::create(['code' => 'DH'.uniqid(), 'order_type' => 'DINE_IN', 'status' => 'CONFIRMED']);
        $item = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menu->id, 'item_name' => $menu->name,
            'unit_price' => $menu->price, 'quantity' => $quantity, 'subtotal' => $menu->price * $quantity,
            'status' => 'PENDING',
        ]);
        // Snapshot công thức như lúc bếp bắt đầu nấu.
        $item->forceFill([
            'recipe_snapshot' => [[
                'ingredient_id' => $menu->recipes()->first()->ingredient_id,
                'quantity' => $menu->recipes()->first()->quantity_required * $quantity,
                'unit' => 'g',
                'comparable' => true,
            ]],
            'recipe_captured_at' => now(),
        ])->save();

        return $item->fresh();
    }

    private function user(string $role = 'KITCHEN'): User
    {
        $user = User::create([
            'name' => ucfirst(strtolower($role)).' Test',
            'email' => strtolower($role).'-'.uniqid().'@test.local',
            'password' => 'secret1234',
            'is_active' => true,
        ]);
        // Role là dữ liệu dùng chung → tạo 1 lần, dùng lại cho các user sau.
        $roleId = \DB::table('roles')->where('code', $role)->value('id');
        if (!$roleId) {
            $roleId = \DB::table('roles')->insertGetId(['code' => $role, 'name' => ucfirst(strtolower($role))]);
        }
        \DB::table('user_role')->insert(['user_id' => $user->id, 'role_id' => $roleId]);

        return $user;
    }
}
