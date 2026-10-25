<?php

namespace Tests\Feature;

use App\Models\{Order, ShipperLocation, User};
use App\Services\ShipperDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract của Phase 2 — vị trí shipper & gán shipper gần nhất.
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local
 * (giống MarketingCampaignTest).
 */
class ShipperDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'checkout.latitude' => 10.0308,
            'checkout.longitude' => 105.7689,
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
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->string('recipient_name', 100)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
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

        Schema::create('shipper_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at');
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

    private function shipper(string $email, bool $active = true): User
    {
        $user = User::create([
            'name'      => 'Shipper',
            'email'     => $email,
            'password'  => bcrypt('secret123'),
            'is_active' => $active,
        ]);

        $role = \App\Models\Role::firstOrCreate(['code' => 'SHIPPER'], ['name' => 'Shipper']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function customer(string $email = 'c@example.com'): User
    {
        return User::create([
            'name'     => 'Khách',
            'email'    => $email,
            'password' => bcrypt('secret123'),
        ]);
    }

    private function onlineOrder(string $code = 'HD-001'): Order
    {
        return Order::create([
            'code'        => $code,
            'customer_id' => $this->customer()->id,
            'order_type'  => 'ONLINE',
            'status'      => 'CONFIRMED',
            'total_amount' => 120000,
            'recipient_name' => 'Nguyễn Văn B',
            'recipient_phone' => '0900000000',
            'delivery_address' => 'Khu II, Cần Thơ',
            'delivery_latitude' => 10.0320,
            'delivery_longitude' => 105.7700,
            'payment_method' => 'COD',
        ]);
    }

    /* ============================================================ */
    /* Vị trí shipper                                               */
    /* ============================================================ */

    public function test_shipper_cap_nhat_vi_tri(): void
    {
        $shipper = $this->shipper('s1@example.com');
        Sanctum::actingAs($shipper);

        $response = $this->postJson('/api/staff/deliveries/location', [
            'latitude' => 10.0310,
            'longitude' => 105.7690,
            'accuracy' => 12.5,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('shipper_locations', [
            'user_id' => $shipper->id,
            'is_available' => true,
        ]);
    }

    public function test_cap_nhat_vi_tri_twic_vao_mot_dong(): void
    {
        $shipper = $this->shipper('s1@example.com');
        Sanctum::actingAs($shipper);

        $this->postJson('/api/staff/deliveries/location', ['latitude' => 10.0310, 'longitude' => 105.7690]);
        $this->postJson('/api/staff/deliveries/location', ['latitude' => 10.0350, 'longitude' => 105.7750]);

        $this->assertSame(1, ShipperLocation::where('user_id', $shipper->id)->count());
        $this->assertEquals(10.0350, (float) ShipperLocation::where('user_id', $shipper->id)->value('latitude'));
    }

    public function test_vi_tri_ngoai_khoang_ban_reject(): void
    {
        $shipper = $this->shipper('s1@example.com');
        Sanctum::actingAs($shipper);

        $this->postJson('/api/staff/deliveries/location', ['latitude' => 999, 'longitude' => 105.7690])
            ->assertStatus(422);
    }

    public function test_vi_tri_cu_shipper_khong_tan_duoc_trong_bang(): void
    {
        $shipper = $this->shipper('s1@example.com');
        Sanctum::actingAs($shipper);

        $this->postJson('/api/staff/deliveries/location', ['latitude' => 10.0310, 'longitude' => 105.7690])->assertOk();

        // Mỗi shipper chỉ có đúng 1 dòng vị trí (upsert).
        $this->assertSame(1, ShipperLocation::where('user_id', $shipper->id)->count());
    }

    /* ============================================================ */
    /* Tìm shipper gần nhất                                         */
    /* ============================================================ */

    public function test_tim_shipper_gan_nhat(): void
    {
        $far = $this->shipper('far@example.com');
        $near = $this->shipper('near@example.com');

        app(ShipperDispatchService::class)->updateLocation($far, 10.0500, 105.8000);
        app(ShipperDispatchService::class)->updateLocation($near, 10.0310, 105.7691);

        $nearest = app(ShipperDispatchService::class)->nearestAvailable();

        $this->assertNotNull($nearest);
        $this->assertSame($near->id, $nearest->id, 'Shipper gần nhà hàng hơn phải được chọn');
    }

    public function test_shipper_da_lau_khong_cap_nhat_vi_tri_bi_loai(): void
    {
        $stale = $this->shipper('stale@example.com');

        ShipperLocation::create([
            'user_id'      => $stale->id,
            'latitude'     => 10.0310,
            'longitude'    => 105.7690,
            'is_available' => true,
            'last_seen_at' => now()->subHour(), // > 30 phút → stale
        ]);

        $this->assertNull(app(ShipperDispatchService::class)->nearestAvailable());
    }

    public function test_shipper_khong_ranh_khong_duoc_gan_don(): void
    {
        $busy = $this->shipper('busy@example.com');

        app(ShipperDispatchService::class)->updateLocation($busy, 10.0310, 105.7690, null, false);

        $this->assertNull(app(ShipperDispatchService::class)->nearestAvailable());
    }

    public function test_shipper_bi_khoa_tai_khoan_khong_duoc_gan(): void
    {
        $locked = $this->shipper('locked@example.com', false);

        app(ShipperDispatchService::class)->updateLocation($locked, 10.0310, 105.7690);

        $this->assertNull(app(ShipperDispatchService::class)->nearestAvailable());
    }

    /* ============================================================ */
    /* Gán đơn tự động                                             */
    /* ============================================================ */

    public function test_tu_dong_gan_shipper_gan_nhat(): void
    {
        $far = $this->shipper('far@example.com');
        $near = $this->shipper('near@example.com');

        app(ShipperDispatchService::class)->updateLocation($far, 10.0500, 105.8000);
        app(ShipperDispatchService::class)->updateLocation($near, 10.0310, 105.7691);

        $order = $this->onlineOrder();

        $assigned = app(ShipperDispatchService::class)->autoAssign($order);

        $this->assertSame($near->id, $assigned);
        $this->assertSame($near->id, $order->fresh()->shipper_id);
    }

    public function test_khong_gan_don_khi_khong_co_shipper(): void
    {
        $order = $this->onlineOrder();

        $this->assertNull(app(ShipperDispatchService::class)->autoAssign($order));
        $this->assertNull($order->fresh()->shipper_id);
    }

    public function test_khong_gan_lai_don_da_co_shipper(): void
    {
        $shipper = $this->shipper('s1@example.com');
        app(ShipperDispatchService::class)->updateLocation($shipper, 10.0310, 105.7690);

        $order = $this->onlineOrder();
        $order->forceFill(['shipper_id' => $shipper->id])->save();

        $assigned = app(ShipperDispatchService::class)->autoAssign($order);

        $this->assertSame($shipper->id, $assigned);
    }

    /* ============================================================ */
    /* Nhận đơn — chỉ 1 shipper nhận được                          */
    /* ============================================================ */

    public function test_shipper_nhan_don_thanh_cong(): void
    {
        $shipper = $this->shipper('s1@example.com');
        $order = $this->onlineOrder();

        Sanctum::actingAs($shipper);
        $this->postJson("/api/staff/deliveries/{$order->id}/accept")->assertOk();

        $this->assertSame($shipper->id, $order->fresh()->shipper_id);
        $this->assertNotNull($order->fresh()->shipper_accepted_at);
    }

    public function test_hai_shipper_khong_nhan_duoc_cung_mot_don(): void
    {
        $first = $this->shipper('first@example.com');
        $second = $this->shipper('second@example.com');

        $order = $this->onlineOrder();

        Sanctum::actingAs($first);
        $this->postJson("/api/staff/deliveries/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($second);
        $this->postJson("/api/staff/deliveries/{$order->id}/accept")->assertStatus(409);
    }

    public function test_shipper_nhan_don_khong_phai_trang_thai_cho(): void
    {
        $shipper = $this->shipper('s1@example.com');
        $order = $this->onlineOrder();
        $order->forceFill(['status' => 'PREPARING'])->save();

        Sanctum::actingAs($shipper);
        $this->postJson("/api/staff/deliveries/{$order->id}/accept")->assertStatus(409);
    }

    /* ============================================================ */
    /* API khách theo dõi shipper                                    */
    /* ============================================================ */

    public function test_khach_xem_vi_tri_shipper(): void
    {
        $shipper = $this->shipper('s1@example.com');
        $customer = $this->customer('watcher@example.com');

        app(ShipperDispatchService::class)->updateLocation($shipper, 10.0330, 105.7710);

        $order = Order::create([
            'code'        => 'HD-002',
            'customer_id' => $customer->id,
            'order_type'  => 'ONLINE',
            'status'      => 'DELIVERING',
            'delivery_latitude' => 10.0400,
            'delivery_longitude' => 105.7800,
        ]);
        // shipper_id không nằm trong $fillable của Order.
        $order->forceFill(['shipper_id' => $shipper->id])->save();

        Sanctum::actingAs($customer);

        $response = $this->getJson("/api/orders/me/{$order->id}/shipper-location");

        $response->assertOk()
            ->assertJsonPath('data.shipper_id', $shipper->id)
            ->assertJsonPath('data.is_fresh', true);

        $this->assertNotNull($response->json('data.remaining_distance_km'));
    }

    public function test_khach_khong_xem_duoc_vi_tri_shipper_cua_don_nguoi_khac(): void
    {
        $shipper = $this->shipper('s1@example.com');
        $order = $this->onlineOrder('HD-003');
        $order->forceFill(['shipper_id' => $shipper->id])->save();

        $other = $this->customer('other@example.com');
        Sanctum::actingAs($other);

        $this->getJson("/api/orders/me/{$order->id}/shipper-location")->assertNotFound();
    }

    public function test_vi_tri_shipper_null_khi_chua_cap_nhat(): void
    {
        $shipper = $this->shipper('s1@example.com');
        $customer = $this->customer('watcher@example.com');

        $order = Order::create([
            'code'        => 'HD-004',
            'customer_id' => $customer->id,
            'order_type'  => 'ONLINE',
            'status'      => 'CONFIRMED',
        ]);
        $order->forceFill(['shipper_id' => $shipper->id])->save();

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/me/{$order->id}/shipper-location")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /* ============================================================ */
    /* Đơn gần nhất cho shipper                                      */
    /* ============================================================ */

    public function test_shipper_xem_duoc_don_gan(): void
    {
        $shipper = $this->shipper('s1@example.com');
        app(ShipperDispatchService::class)->updateLocation($shipper, 10.0310, 105.7690);

        $order = $this->onlineOrder('HD-005');

        Sanctum::actingAs($shipper);
        $response = $this->getJson('/api/staff/deliveries/nearby-jobs');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($order->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('data.0.distance_km'));
    }

    public function test_don_da_co_shipper_nhan_khong_hien_cho_shipper_khac(): void
    {
        $first = $this->shipper('first@example.com');
        $second = $this->shipper('second@example.com');

        app(ShipperDispatchService::class)->updateLocation($second, 10.0310, 105.7690);

        $order = $this->onlineOrder('HD-006');
        $order->forceFill([
            'shipper_id' => $first->id,
            'shipper_accepted_at' => now(),
        ])->save();

        Sanctum::actingAs($second);
        $response = $this->getJson('/api/staff/deliveries/nearby-jobs');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
