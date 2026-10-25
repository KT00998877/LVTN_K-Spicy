<?php

namespace Tests\Feature;

use App\Models\{Order, User};
use App\Services\DeliveryRatingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract của Phase 4 — đánh giá dịch vụ giao hàng.
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local
 * (giống ShipperDispatchTest / MenuSuggestionTest).
 */
class DeliveryRatingTest extends TestCase
{
    /** JPEG 1×1 thật — tránh phụ thuộc GD extension (giống AvatarUploadTest). */
    private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('order_type', ['DINE_IN', 'TAKEAWAY', 'ONLINE'])->default('DINE_IN');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('recipient_name', 100)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('delivery_distance_km', 10, 2)->nullable();
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->string('payment_method', 20)->default('COD');
            $table->enum('status', [
                'PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER',
                'SERVED', 'DELIVERING', 'DELIVERED', 'COMPLETED', 'CANCELLED',
            ])->default('PENDING');
            $table->timestamp('placed_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shipper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipper_accepted_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('customer_received_at')->nullable();
            $table->text('delivery_issue')->nullable();
            $table->unsignedTinyInteger('delivery_rating')->nullable();
            $table->text('delivery_comment')->nullable();
            $table->timestamp('delivery_rated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->enum('status', ['PENDING', 'APPROVED', 'REFUNDED'])->default('PENDING');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_delivery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('url', 255);
            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('size_kb')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['order_id', 'sort_order']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable();
            $table->string('item_name', 150);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('topping_id')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('toppings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('status', 20)->default('UNPAID');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('status', 30);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
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

    /** Tạo 1 file ảnh JPEG 1×1 thật (không cần GD extension). */
    private function fakeImage(string $name = 'food.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dlv');
        file_put_contents($tmp, base64_decode(self::JPEG_1X1));

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    private function customer(string $email = 'kh@example.com'): User
    {
        return User::create([
            'name' => 'Khách hàng', 'email' => $email, 'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);
    }

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

    private function shipper(string $email = 'shipper@example.com'): User
    {
        $user = User::create([
            'name' => 'Shipper', 'email' => $email, 'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);
        $role = \App\Models\Role::firstOrCreate(['code' => 'SHIPPER'], ['name' => 'Shipper']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function onlineOrder(User $customer, string $status = 'COMPLETED', ?User $shipper = null): Order
    {
        // `shipper_id` không nằm trong $fillable của Order → dùng forceFill.
        $order = Order::create([
            'code'         => 'OD' . uniqid(),
            'customer_id'  => $customer->id,
            'order_type'   => 'ONLINE',
            'status'       => $status,
            'subtotal'     => 100000,
            'total_amount' => 100000,
            'placed_at'    => now(),
        ]);
        $order->forceFill(['shipper_id' => $shipper?->id])->save();

        return $order;
    }

    /* ============================================================ */
    /* Customer đánh giá                                           */
    /* ============================================================ */

    public function test_khach_cham_diem_dich_vu_giao_thanh_cong(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);

        $response = $this->postJson("/api/orders/me/{$order->id}/delivery-rating", [
            'rating'  => 5,
            'comment' => 'Giao nhanh, món còn nóng. Shipper thân thiện!',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $response->assertJsonPath('data.rating', 5);
        $response->assertJsonPath('data.comment', 'Giao nhanh, món còn nóng. Shipper thân thiện!');

        $this->assertDatabaseHas('orders', [
            'id'               => $order->id,
            'delivery_rating'  => 5,
            'delivery_comment' => 'Giao nhanh, món còn nóng. Shipper thân thiện!',
        ]);
        $this->assertNotNull($order->fresh()->delivery_rated_at);
    }

    public function test_khach_cham_diem_duoc_kem_anh(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);

        $response = $this->post("/api/orders/me/{$order->id}/delivery-rating", [
            'rating' => 4,
            'photos' => [
                $this->fakeImage('food.jpg'),
                $this->fakeImage('box.jpg'),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200)->assertJsonPath('data.rating', 4);
        $this->assertCount(2, $response->json('data.photos'));
        $this->assertDatabaseCount('order_delivery_photos', 2);
    }

    public function test_khach_danh_gia_lai_duoc_ghi_de(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);
        $order->forceFill(['delivery_rating' => 2, 'delivery_comment' => 'Giao chậm'])->save();

        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", [
            'rating'  => 5,
            'comment' => 'Lần này giao nhanh hơn',
        ])->assertStatus(200)->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('orders', [
            'id'               => $order->id,
            'delivery_rating'  => 5,
            'delivery_comment' => 'Lần này giao nhanh hơn',
        ]);
    }

    public function test_khong_danh_gia_duoc_don_chua_hoan_thanh(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer, 'DELIVERED');

        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    public function test_khong_danh_gia_duoc_don_khong_phai_giao_hang(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = Order::create([
            'code' => 'OD' . uniqid(),
            'customer_id' => $customer->id,
            'order_type'  => 'DINE_IN',
            'status'      => 'COMPLETED',
        ]);

        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    public function test_khong_danh_gia_duoc_don_nguoi_khac(): void
    {
        $owner = $this->customer('owner@example.com');
        $other = $this->customer('other@example.com');
        $order = $this->onlineOrder($owner);

        Sanctum::actingAs($other);
        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", ['rating' => 1])
            ->assertStatus(404);
    }

    public function test_diem_danh_gia_phai_tu_1_den_5(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);

        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", ['rating' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", ['rating' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_comment_qua_dai_bi_tu_choi(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);

        $this->postJson("/api/orders/me/{$order->id}/delivery-rating", [
            'rating'  => 5,
            'comment' => str_repeat('a', DeliveryRatingService::COMMENT_MAX + 1),
        ])->assertStatus(422)->assertJsonValidationErrors('comment');
    }

    public function test_toi_da_5_anh_bi_tu_choi(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);

        $photos = [];
        for ($i = 0; $i < DeliveryRatingService::MAX_PHOTOS + 1; $i++) {
            $photos[] = $this->fakeImage("p{$i}.jpg");
        }

        $this->post("/api/orders/me/{$order->id}/delivery-rating", [
            'rating' => 5,
            'photos' => $photos,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photos');
    }

    public function test_can_phai_dang_nhap_moi_danh_gia_duoc(): void
    {
        $this->postJson('/api/orders/me/1/delivery-rating', ['rating' => 5])
            ->assertStatus(401);
    }

    /* ============================================================ */
    /* Manager xem thống kê                                          */
    /* ============================================================ */

    public function test_quan_ly_xem_duoc_thong_ke_breakdown(): void
    {
        $manager = $this->manager();
        $customer = $this->customer();
        $shipper = $this->shipper();

        $o1 = $this->onlineOrder($customer, 'COMPLETED', $shipper);
        $o1->forceFill(['delivery_rating' => 5, 'delivery_rated_at' => now()])->save();
        $o2 = $this->onlineOrder($customer, 'COMPLETED', $shipper);
        $o2->forceFill(['delivery_rating' => 3, 'delivery_rated_at' => now()])->save();
        $o3 = $this->onlineOrder($customer, 'COMPLETED', $shipper);
        $o3->forceFill(['delivery_rating' => 3, 'delivery_rated_at' => now()])->save();

        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/staff/manager/delivery-ratings');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $response->assertJsonPath('data.count', 3);
        $response->assertJsonPath('data.average', 3.67);
        $response->assertJsonPath('data.breakdown.3', 2);
        $response->assertJsonPath('data.breakdown.5', 1);
        $response->assertJsonPath('data.breakdown.1', 0);
        $this->assertCount(1, $response->json('data.shipper_stats'));
        $response->assertJsonPath('data.shipper_stats.0.shipper_name', 'Shipper');
        $response->assertJsonPath('data.shipper_stats.0.count', 3);
    }

    public function test_quan_ly_loc_thong_ke_theo_shipper(): void
    {
        $manager = $this->manager();
        $customer = $this->customer();
        $shipperA = $this->shipper('a@example.com');
        $shipperB = $this->shipper('b@example.com');

        $o1 = $this->onlineOrder($customer, 'COMPLETED', $shipperA);
        $o1->forceFill(['delivery_rating' => 5])->save();
        $o2 = $this->onlineOrder($customer, 'COMPLETED', $shipperB);
        $o2->forceFill(['delivery_rating' => 1])->save();

        Sanctum::actingAs($manager);
        $response = $this->getJson("/api/staff/manager/delivery-ratings?shipper_id={$shipperA->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.count', 1);
        $response->assertJsonPath('data.average', 5);
    }

    public function test_khach_khong_xem_duoc_thong_ke_quan_ly(): void
    {
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/staff/manager/delivery-ratings')->assertStatus(403);
    }

    /* ============================================================ */
    /* Hiển thị trên chi tiết đơn                                     */
    /* ============================================================ */

    public function test_chi_tiet_don_hien_thi_danh_gia_giao_hang(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->onlineOrder($customer);
        $order->forceFill([
            'delivery_rating'   => 4,
            'delivery_comment'  => 'Giao khá nhanh',
            'delivery_rated_at' => now(),
        ])->save();

        $response = $this->getJson("/api/orders/me/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.delivery_rating', 4);
        $response->assertJsonPath('data.delivery_comment', 'Giao khá nhanh');
        $response->assertJsonPath('data.delivery_photos', []);
    }
}
