<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('spicy_level')->default(0);
            $table->string('size')->nullable();
            $table->unsignedInteger('preparation_minutes')->default(15);
            $table->boolean('is_available')->default(true);
            $table->string('sold_out_reason')->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->boolean('auto_availability_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('recipe_managed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_item_id');
            $table->unsignedTinyInteger('rating');
            $table->boolean('is_approved')->default(true);
            $table->boolean('is_hidden')->default(false);
        });

        Schema::create('menu_item_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_item_id');
            $table->unsignedBigInteger('suggested_item_id');
            $table->string('title')->nullable();
            $table->string('message')->nullable();
            $table->decimal('combo_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Mì cay',
            'slug' => 'mi-cay',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        DB::table('menu_items')->insert([
            [
                'category_id' => $categoryId,
                'name' => 'Mì cay bò',
                'slug' => 'mi-cay-bo',
                'price' => 59000,
                'is_available' => true,
                'is_active' => true,
                'auto_availability_enabled' => false,
            ],
            [
                'category_id' => $categoryId,
                'name' => 'Mì cay hải sản',
                'slug' => 'mi-cay-hai-san',
                'price' => 69000,
                'is_available' => true,
                'is_active' => true,
                'auto_availability_enabled' => false,
            ],
        ]);

        Route::middleware(['api', 'auth:sanctum'])->post(
            '/api/_security-auth-test',
            fn () => response()->json(['user_id' => request()->user()->id]),
        );
    }

    public function test_search_payload_is_bound_as_data(): void
    {
        $payload = "' OR 1=1; DROP TABLE menu_items; --";

        $this->getJson('/api/menu?'.http_build_query(['search' => $payload]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertTrue(Schema::hasTable('menu_items'));
        $this->assertSame(2, DB::table('menu_items')->count());
    }

    public function test_sort_payload_falls_back_to_whitelisted_order(): void
    {
        $payload = 'price desc; DROP TABLE menu_items; --';

        $this->getJson('/api/menu?'.http_build_query(['sort' => $payload]))
            ->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('meta.filters.sort', $payload);

        $this->assertTrue(Schema::hasTable('menu_items'));
    }

    public function test_bearer_api_requires_authentication_but_not_csrf_cookie(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')
            ->postJson('/api/_security-auth-test')
            ->assertUnauthorized();

        $user = User::create([
            'name' => 'Khách hàng',
            'email' => 'customer@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('security-contract')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/_security-auth-test')
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }
}
