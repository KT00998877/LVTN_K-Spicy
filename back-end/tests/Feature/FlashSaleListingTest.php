<?php

namespace Tests\Feature;

use App\Events\CustomerPromoNotificationCreated;
use App\Models\User;
use App\Services\FlashSaleNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlashSaleListingTest extends TestCase
{
    public function test_flash_sale_endpoint_returns_every_active_item(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active');
        });
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->string('slug');
            $table->string('image_url')->nullable();
            $table->integer('price');
            $table->integer('flash_sale_discount');
            $table->timestamp('flash_sale_starts_at');
            $table->timestamp('flash_sale_ends_at');
            $table->boolean('is_active');
            $table->boolean('is_available');
            $table->boolean('auto_availability_enabled');
        });

        DB::table('categories')->insert(['id' => 1, 'is_active' => true]);
        foreach (['Món một', 'Món hai'] as $index => $name) {
            DB::table('menu_items')->insert([
                'category_id' => 1,
                'name' => $name,
                'slug' => 'mon-'.$index,
                'image_url' => '/menu/'.$index.'.jpg',
                'price' => 100000,
                'flash_sale_discount' => 20000,
                'flash_sale_starts_at' => now()->subHour(),
                'flash_sale_ends_at' => now()->addHour(),
                'is_active' => true,
                'is_available' => true,
                'auto_availability_enabled' => false,
            ]);
        }

        $this->getJson('/api/menu/flash-sales')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Món một')
            ->assertJsonPath('data.1.name', 'Món hai')
            ->assertJsonPath('data.1.image_url', '/menu/1.jpg');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('user_role', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamp('assigned_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->string('deduplication_key')->unique();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'is_active' => true]);
        DB::table('roles')->insert(['id' => 1, 'code' => 'CUSTOMER']);
        DB::table('user_role')->insert(['user_id' => 1, 'role_id' => 1]);
        Event::fake([CustomerPromoNotificationCreated::class]);

        $notifications = app(FlashSaleNotificationService::class);
        $customer = User::findOrFail(1);
        $this->assertSame(2, $notifications->syncForUser($customer));
        $this->assertSame(0, $notifications->syncForUser($customer));
        $this->assertSame(2, DB::table('notifications')->count());
    }
}
