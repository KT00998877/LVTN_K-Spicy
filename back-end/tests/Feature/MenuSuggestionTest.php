<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\MenuItemSuggestion;
use App\Models\User;
use App\Services\MenuSuggestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract của Phase 3 — gợi ý combo nước uống do quản lý cấu hình.
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local
 * (giống ShipperDispatchTest / MarketingCampaignTest).
 */
class MenuSuggestionTest extends TestCase
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
            $table->string('email');
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->integer('loyalty_points')->default(0);
            $table->integer('total_spent')->default(0);
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('image_url', 255)->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('flash_sale_discount')->nullable();
            $table->timestamp('flash_sale_starts_at')->nullable();
            $table->timestamp('flash_sale_ends_at')->nullable();
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('preparation_minutes')->default(15);
            $table->unsignedTinyInteger('spicy_level')->default(0);
            $table->enum('size', ['SMALL', 'LARGE'])->nullable();
            $table->boolean('auto_availability_enabled')->default(false);
            $table->timestamp('recipe_managed_at')->nullable();
            $table->string('sold_out_reason', 255)->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('menu_item_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('suggested_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('title', 150)->nullable();
            $table->string('message', 255)->nullable();
            $table->decimal('combo_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['menu_item_id', 'suggested_item_id']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts');
            $table->foreignId('menu_item_id')->constrained('menu_items');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedTinyInteger('spicy_level');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('cart_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')->constrained('cart_items');
            $table->foreignId('topping_menu_item_id')->constrained('menu_items');
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->boolean('is_approved')->default(true);
            $table->boolean('is_hidden')->default(false);
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

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /* ============================================================ */
    /* Helpers                                                     */
    /* ============================================================ */

    private function manager(string $email = 'm@example.com'): User
    {
        $user = User::create([
            'name' => 'Quản lý', 'email' => $email, 'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);
        $role = \App\Models\Role::firstOrCreate(['code' => 'MANAGER'], ['name' => 'Quản lý']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function category(string $name, string $slug): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => $name, 'slug' => $slug, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function menuItem(string $name, float $price, ?int $categoryId = null, array $overrides = []): MenuItem
    {
        $categoryId ??= $this->category('Mì cay', 'mi-cay-' . uniqid());

        return MenuItem::create(array_merge([
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => str($name)->slug() . '-' . uniqid(),
            'price' => $price,
            'is_available' => true,
            'is_active' => true,
        ], $overrides));
    }

    /* ============================================================ */
    /* Manager CRUD                                                */
    /* ============================================================ */

    public function test_quan_ly_tao_duoc_goi_y_combo(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);

        $response = $this->postJson('/api/staff/manager/menu-suggestions', [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
            'message' => 'Thêm trà đào, tiết kiệm 10k',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('menu_item_suggestions', [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);
    }

    public function test_combo_duoc_quang_ba_va_thong_bao_mot_lan_cho_khach(): void
    {
        $customer = User::create(['name' => 'Khách', 'email' => 'combo@example.com', 'password' => 'secret123', 'is_active' => true]);
        $role = \App\Models\Role::firstOrCreate(['code' => 'CUSTOMER'], ['name' => 'Khách hàng']);
        $customer->roles()->attach($role->id);
        Sanctum::actingAs($this->manager());
        $main = $this->menuItem('Mì cay bạch tuộc', 109000);
        $drink = $this->menuItem('7-Up', 20000);
        $payload = ['menu_item_id' => $main->id, 'suggested_item_id' => $drink->id, 'combo_price' => 120000];

        $this->postJson('/api/staff/manager/menu-suggestions', $payload)->assertCreated();
        $combo = app(MenuSuggestionService::class)->featuredCombos(1)[0];
        $this->assertSame(9000, $combo['saving']);
        $this->assertSame($main->id, $combo['main_item']['id']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('COMBO_PROMOTION', json_decode(\DB::table('notifications')->value('data'), true)['event']);

        $suggestion = MenuItemSuggestion::first();
        app(\App\Services\ComboNotificationService::class)->sendFor($suggestion);
        $this->assertDatabaseCount('notifications', 1);

        $laterCustomer = User::create(['name' => 'Khách mới', 'email' => 'later@example.com', 'password' => 'secret123', 'is_active' => true]);
        $laterCustomer->roles()->attach($role->id);
        Sanctum::actingAs($laterCustomer);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 1);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_danh_muc_combo_chi_hien_khi_co_combo_dang_ban(): void
    {
        $main = $this->menuItem('Mì cay bò', 69000);
        $drink = $this->menuItem('7-Up', 20000);
        $this->assertNotContains('combo', array_column($this->getJson('/api/home/categories')->assertOk()->json('data'), 'slug'));

        $suggestion = MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 80000,
        ]);
        $this->getJson('/api/home/categories')->assertOk()->assertJsonPath('data.0.slug', 'combo')->assertJsonPath('data.0.items_count', 1);
        $this->getJson('/api/menu?category=combo')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $main->id)
            ->assertJsonPath('data.0.suggestions.0.suggested_item.id', $drink->id);

        $suggestion->update(['is_active' => false]);
        $this->assertNotContains('combo', array_column($this->getJson('/api/home/categories')->assertOk()->json('data'), 'slug'));
        $this->getJson('/api/menu?category=combo')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_them_combo_vao_gio_la_mot_thao_tac_nguyen_tu(): void
    {
        $customer = User::create(['name' => 'Khách', 'email' => 'atomic@example.com', 'password' => 'secret123', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $main = $this->menuItem('Mì cay bò', 69000);
        $drink = $this->menuItem('7-Up', 20000);
        $suggestion = MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 80000,
        ]);
        $payload = ['suggestion_id' => $suggestion->id, 'main' => [
            'menu_item_id' => $main->id, 'quantity' => 1, 'spicy_level' => 0, 'topping_ids' => [],
        ]];

        \DB::statement("CREATE TRIGGER reject_combo_drink BEFORE INSERT ON cart_items WHEN NEW.menu_item_id = {$drink->id} BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        $this->postJson('/api/cart/combo', $payload)->assertStatus(500);
        $this->assertDatabaseCount('cart_items', 0);
        \DB::statement('DROP TRIGGER reject_combo_drink');

        $this->postJson('/api/cart/combo', $payload)->assertCreated();
        $this->assertDatabaseCount('cart_items', 2);

        $drink->update(['is_available' => false]);
        $this->postJson('/api/cart/combo', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_quan_ly_xem_danh_sach_goi_y(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $this->getJson('/api/staff/manager/menu-suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.menu_item_id', $main->id)
            ->assertJsonPath('data.0.suggested_item_id', $drink->id)
            ->assertJsonPath('data.0.saving', 10000);
    }

    public function test_khach_hang_khong_duoc_goi_y_combo(): void
    {
        $customer = User::create([
            'name' => 'Khách', 'email' => 'c@example.com', 'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);
        $role = \App\Models\Role::firstOrCreate(['code' => 'CUSTOMER'], ['name' => 'Khách hàng']);
        $customer->roles()->attach($role->id);
        Sanctum::actingAs($customer);

        $this->getJson('/api/staff/manager/menu-suggestions')->assertStatus(403);
    }

    public function test_mon_goi_y_phai_khac_mon_chinh(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);

        $this->postJson('/api/staff/manager/menu-suggestions', [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $main->id,
        ])->assertStatus(422);
    }

    public function test_khong_cho_trung_goi_y(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);

        $payload = [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ];
        $this->postJson('/api/staff/manager/menu-suggestions', $payload)->assertStatus(201);
        $this->postJson('/api/staff/manager/menu-suggestions', $payload)->assertStatus(422);
    }

    public function test_gia_combo_phai_thap_hon_tong_gia_hai_mon(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);

        $this->postJson('/api/staff/manager/menu-suggestions', [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 65000, // >= tổng 65.000 → không có ưu đãi
        ])->assertStatus(422);
    }

    public function test_quan_ly_tat_va_xoa_goi_y(): void
    {
        Sanctum::actingAs($this->manager());

        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        $suggestion = MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $this->patchJson("/api/staff/manager/menu-suggestions/{$suggestion->id}", [
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 50000,
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('menu_item_suggestions', [
            'id' => $suggestion->id,
            'combo_price' => 50000,
            'is_active' => false,
        ]);

        $this->deleteJson("/api/staff/manager/menu-suggestions/{$suggestion->id}")->assertOk();
        $this->assertDatabaseMissing('menu_item_suggestions', ['id' => $suggestion->id]);
    }

    /* ============================================================ */
    /* Public menu API                                             */
    /* ============================================================ */

    public function test_menu_cong_khai_co_goi_y_combo(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $response = $this->getJson('/api/menu');
        $response->assertOk();

        $items = collect($response->json('data'))->keyBy('id');
        $this->assertCount(1, $items[$main->id]['suggestions']);
        $this->assertSame($drink->id, $items[$main->id]['suggestions'][0]['suggested_item']['id']);
        $this->assertSame(10000, $items[$main->id]['suggestions'][0]['saving']);
        $this->assertCount(0, $items[$drink->id]['suggestions']);
    }

    public function test_goi_y_tat_khong_hien_thi(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
            'is_active' => false,
        ]);

        $items = collect($this->getJson('/api/menu')->json('data'))->keyBy('id');
        $this->assertCount(0, $items[$main->id]['suggestions']);
    }

    /* ============================================================ */
    /* Tính tiết kiệm combo                                        */
    /* ============================================================ */

    public function test_tiet_kiem_combo_khi_gio_hang_co_ca_hai_mon(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $result = app(MenuSuggestionService::class)->comboDiscountForCart([
            $main->id => 1,
            $drink->id => 1,
        ]);

        $this->assertSame(10000, $result['discount_vnd']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(65000, $result['lines'][0]['original_unit_price']);
        $this->assertSame(55000, $result['lines'][0]['combo_price']);
        $this->assertSame('Trà đào', $result['lines'][0]['suggested_item_name']);
    }

    public function test_khong_co_tiet_kiem_khi_thieu_mon_goi_y(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $service = app(MenuSuggestionService::class);

        $this->assertSame(0, $service->comboDiscountForCart([$main->id => 1])['discount_vnd']);
        $this->assertSame(0, $service->comboDiscountForCart([$drink->id => 1])['discount_vnd']);
        $this->assertSame(0, $service->comboDiscountForCart([])['discount_vnd']);
    }

    public function test_tiet_kiem_combo_nhan_theo_so_luong(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $result = app(MenuSuggestionService::class)->comboDiscountForCart([
            $main->id => 3,
            $drink->id => 2,
        ]);

        // 2 combo (giới hạn bởi số lượng đồ uống) → 2 × 10.000
        $this->assertSame(20000, $result['discount_vnd']);
        $this->assertSame(2, $result['lines'][0]['quantity']);
    }

    public function test_khong_tinh_trung_khi_mot_mon_goi_y_dung_cho_nhieu_mon_chinh(): void
    {
        $mainA = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $mainB = $this->menuItem('Mì cay kim chi', 50000);
        $drink = $this->menuItem('Trà đào', 20000);

        MenuItemSuggestion::create([
            'menu_item_id' => $mainA->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);
        MenuItemSuggestion::create([
            'menu_item_id' => $mainB->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 60000,
        ]);

        // 2 món chính nhưng chỉ 1 đồ uống → chỉ được áp 1 combo.
        $result = app(MenuSuggestionService::class)->comboDiscountForCart([
            $mainA->id => 1,
            $mainB->id => 1,
            $drink->id => 1,
        ]);

        $this->assertCount(1, $result['lines']);
        $this->assertSame(10000, $result['discount_vnd']);
    }

    public function test_mon_goi_y_het_hang_khong_ap_combo(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000, null, ['is_available' => false]);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => 55000,
        ]);

        $result = app(MenuSuggestionService::class)->comboDiscountForCart([
            $main->id => 1,
            $drink->id => 1,
        ]);

        $this->assertSame(0, $result['discount_vnd']);
    }

    public function test_goi_y_khong_co_combo_price_khong_tinh_tiet_kiem(): void
    {
        $main = $this->menuItem('Mì cay Hàn Quốc', 45000);
        $drink = $this->menuItem('Trà đào', 20000);
        MenuItemSuggestion::create([
            'menu_item_id' => $main->id,
            'suggested_item_id' => $drink->id,
            'combo_price' => null,
        ]);

        $result = app(MenuSuggestionService::class)->comboDiscountForCart([
            $main->id => 1,
            $drink->id => 1,
        ]);

        $this->assertSame(0, $result['discount_vnd']);
    }
}
