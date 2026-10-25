<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use App\Services\HomeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuCurationAndFavoritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('user_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->timestamp('assigned_at')->nullable();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->unsignedInteger('preparation_minutes')->default(15);
            $table->unsignedTinyInteger('spicy_level')->default(0);
            $table->string('size')->nullable();
            $table->boolean('auto_availability_enabled')->default(false);
            $table->timestamp('recipe_managed_at')->nullable();
            $table->string('sold_out_reason')->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->unsignedBigInteger('sold_out_by')->nullable();
            $table->timestamps();
        });
        Schema::create('user_favorite_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('menu_item_id');
            $table->timestamps();
            $table->unique(['user_id', 'menu_item_id']);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id');
            $table->unsignedInteger('quantity');
        });
    }

    public function test_manager_can_toggle_featured_and_best_seller_flags(): void
    {
        $manager = $this->user('manager@example.com');
        $role = Role::create(['code' => 'MANAGER', 'name' => 'Quản lý']);
        $manager->roles()->attach($role->id);
        Sanctum::actingAs($manager);
        $item = $this->menuItem('Mì cay kim chi');

        $this->patchJson("/api/staff/dashboard/menu-items/{$item->id}", [
            'is_featured' => true,
            'is_best_seller' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.is_best_seller', true);

        $this->assertDatabaseHas('menu_items', [
            'id' => $item->id,
            'is_featured' => true,
            'is_best_seller' => true,
        ]);
    }

    public function test_home_lists_only_items_enabled_by_manager(): void
    {
        $curated = $this->menuItem('Mì cay đặc sắc', ['is_featured' => true, 'is_best_seller' => true]);
        $this->menuItem('Mì cay thường');
        DB::table('order_items')->insert(['menu_item_id' => $curated->id, 'quantity' => 4]);

        $home = app(HomeService::class);

        $this->assertSame([$curated->id], array_column($home->getFeaturedItems(), 'id'));
        $this->assertSame([$curated->id], array_column($home->getBestSellers(), 'id'));
    }

    public function test_customer_can_add_list_and_remove_a_favorite_idempotently(): void
    {
        $customer = $this->user('customer@example.com');
        Sanctum::actingAs($customer);
        $item = $this->menuItem('Tokbokki phô mai');

        $this->putJson("/api/favorites/{$item->id}")->assertOk();
        $this->putJson("/api/favorites/{$item->id}")->assertOk();

        $this->assertDatabaseCount('user_favorite_menu_items', 1);
        $this->getJson('/api/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $item->id);

        $this->deleteJson("/api/favorites/{$item->id}")->assertOk();
        $this->assertDatabaseCount('user_favorite_menu_items', 0);
    }

    public function test_guest_cannot_access_favorites(): void
    {
        $this->getJson('/api/favorites')->assertUnauthorized();
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Người dùng',
            'email' => $email,
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function menuItem(string $name, array $overrides = []): MenuItem
    {
        $categoryId = DB::table('categories')->value('id') ?: DB::table('categories')->insertGetId([
            'name' => 'Mì cay',
            'slug' => 'mi-cay',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MenuItem::create(array_merge([
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 59000,
            'is_available' => true,
            'is_active' => true,
        ], $overrides));
    }
}
