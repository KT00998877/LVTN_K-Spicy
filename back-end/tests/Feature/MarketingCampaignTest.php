<?php

namespace Tests\Feature;

use App\Events\CustomerPromoNotificationCreated;
use App\Models\MarketingCampaign;
use App\Models\User;
use App\Models\Voucher;
use App\Services\MarketingCampaignService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract của Phase 1 — chuông ưu đãi + banner + mã voucher.
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local
 * (giống AvatarUploadTest).
 */
class MarketingCampaignTest extends TestCase
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

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->integer('min_spent')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->enum('type', ['PERCENT', 'FIXED'])->default('PERCENT');
            $table->decimal('value', 14, 2);
            $table->decimal('max_discount_amount', 14, 2)->nullable();
            $table->decimal('min_order_subtotal', 14, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('message');
            $table->string('voucher_code', 50);
            $table->string('banner_image_url', 500)->nullable();
            $table->string('accent_color', 20)->default('#E83A14');
            $table->string('cta_text', 60)->default('Nhận ưu đãi');
            $table->string('landing_url', 500)->nullable();
            $table->json('target_tier_ids')->nullable();
            $table->timestamp('send_at');
            $table->time('window_start');
            $table->time('window_end');
            $table->enum('status', ['SCHEDULED', 'SENT', 'SKIPPED', 'CANCELLED'])->default('SCHEDULED');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('created_by')->nullable();
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

    private function customer(string $email = 'a@example.com', int $totalSpent = 100000, bool $active = true): User
    {
        $user = User::create([
            'name'        => 'Khách hàng',
            'email'       => $email,
            'password'    => bcrypt('secret123'),
            'is_active'   => $active,
        ]);

        // total_spent không nằm trong $fillable (app dùng increment()).
        $user->forceFill(['total_spent' => $totalSpent])->save();

        return $user;
    }

    private function voucher(string $code = 'MI-CAY-20', string $type = 'PERCENT', float $value = 20): Voucher
    {
        return Voucher::create([
            'code'     => $code,
            'name'     => 'Giảm mì cay',
            'type'     => $type,
            'value'    => $value,
            'is_active' => true,
        ]);
    }

    private function campaign(array $overrides = []): MarketingCampaign
    {
        return MarketingCampaign::create(array_merge([
            'name'          => 'Flash deal trưa',
            'message'       => 'Mì cay giảm 20% từ 11h-13h30!',
            'voucher_code'  => 'MI-CAY-20',
            'send_at'       => now()->subMinute(),
            'window_start'  => '00:00',
            'window_end'    => '23:59',
            'status'        => MarketingCampaign::STATUS_SCHEDULED,
        ], $overrides));
    }

    /* ============================================================ */
    /* Model / window logic                                        */
    /* ============================================================ */

    public function test_khung_gio_vang_nhan_dung(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

        $campaign = $this->campaign(['window_start' => '11:00', 'window_end' => '13:30']);

        $this->assertTrue($campaign->isWithinWindow(), '12:00 nằm trong khung 11:00-13:30');

        $before = $this->campaign(['window_start' => '11:00', 'window_end' => '13:30']);
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00'));
        $this->assertFalse($before->fresh()->isWithinWindow(), '10:00 nằm ngoài khung 11:00-13:30');

        Carbon::setTestNow(Carbon::parse('2026-10-24 14:00:00'));
        $this->assertFalse($before->fresh()->isWithinWindow(), '14:00 nằm ngoài khung 11:00-13:30');

        Carbon::setTestNow();
    }

    public function test_khung_gio_vang_qua_dem(): void
    {
        // Khung qua đêm: 22:00 → 02:00. 23:30 phải nằm trong cửa sổ.
        Carbon::setTestNow(Carbon::parse('2026-10-24 23:30:00'));
        $campaign = $this->campaign(['window_start' => '22:00', 'window_end' => '02:00']);
        $this->assertTrue($campaign->isWithinWindow(), '23:30 nằm trong khung qua đêm');

        Carbon::setTestNow(Carbon::parse('2026-10-24 01:00:00'));
        $this->assertTrue($campaign->fresh()->isWithinWindow(), '01:00 nằm trong khung qua đêm');

        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        $this->assertFalse($campaign->fresh()->isWithinWindow(), '12:00 nằm ngoài khung qua đêm');

        Carbon::setTestNow();
    }

    /* ============================================================ */
    /* Gửi ưu đãi                                                */
    /* ============================================================ */

    public function test_gui_uu_dai_cho_khach_thanh_thiet(): void
    {
        Event::fake([CustomerPromoNotificationCreated::class]);

        $this->voucher();
        $customer = $this->customer();
        $this->campaign();

        $sent = app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        $this->assertSame(1, $sent, 'Phải gửi cho 1 khách thân thiết');
        Event::assertDispatched(CustomerPromoNotificationCreated::class, 1);
    }

    public function test_khong_gui_cho_khach_chua_chi_tien(): void
    {
        Event::fake([CustomerPromoNotificationCreated::class]);

        $this->voucher();
        $this->customer('new@example.com', 0); // chưa chi tiền
        $this->campaign();

        $sent = app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        $this->assertSame(0, $sent, 'Khách chưa chi tiền không phải khách thân thiết');
        Event::assertNotDispatched(CustomerPromoNotificationCreated::class);
    }

    public function test_khong_gui_cho_tai_khoan_bi_khoa(): void
    {
        Event::fake([CustomerPromoNotificationCreated::class]);

        $this->voucher();
        $this->customer('locked@example.com', 100000, false);
        $this->campaign();

        $sent = app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        $this->assertSame(0, $sent);
        Event::assertNotDispatched(CustomerPromoNotificationCreated::class);
    }

    public function test_khach_chi_nhan_1_uu_dai_trong_24h(): void
    {
        Event::fake([CustomerPromoNotificationCreated::class]);

        $this->voucher();
        $customer = $this->customer();
        $this->campaign();

        app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        // Campaign thứ 2 ngay sau đó.
        $this->voucher('MI-CAY-30');
        $this->campaign(['voucher_code' => 'MI-CAY-30', 'name' => 'Campaign 2']);
        $second = MarketingCampaign::orderByDesc('id')->first();

        $sent = app(MarketingCampaignService::class)->send($second);

        $this->assertSame(0, $sent, 'Khách đã nhận ưu đãi trong 24h thì không nhận thêm');
    }

    public function test_campaign_chi_gui_mot_lan(): void
    {
        $this->voucher();
        $this->customer();
        $this->campaign();

        $campaign = MarketingCampaign::first();
        app(MarketingCampaignService::class)->send($campaign);

        $this->assertSame(MarketingCampaign::STATUS_SENT, $campaign->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->recipient_count);
        $this->assertNotNull($campaign->fresh()->sent_at);
    }

    public function test_gui_campaign_da_gui_bi_tu_choi(): void
    {
        $this->voucher();
        $this->customer();
        $campaign = $this->campaign(['status' => MarketingCampaign::STATUS_SENT]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(MarketingCampaignService::class)->send($campaign);
    }

    /* ============================================================ */
    /* API khách hàng                                            */
    /* ============================================================ */

    public function test_khach_xem_danh_sach_thong_bao(): void
    {
        $this->voucher();
        $customer = $this->customer();
        $this->campaign();
        app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.data.0.data.voucher_code', 'MI-CAY-20');
    }

    public function test_khach_danh_dau_da_doc(): void
    {
        $this->voucher();
        $customer = $this->customer();
        $this->campaign();
        app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        Sanctum::actingAs($customer);

        $id = $this->getJson('/api/notifications')->json('data.data.0.id');

        $this->patchJson("/api/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_khach_khong_doc_duoc_thong_bao_cua_nguoi_khac(): void
    {
        $this->voucher();
        $other = $this->customer('other@example.com');
        $this->campaign();
        app(MarketingCampaignService::class)->send(MarketingCampaign::first());

        $id = $this->getJson('/api/notifications')->json('data.data.0.id');
        Sanctum::actingAs($other);

        $this->patchJson("/api/notifications/{$id}/read")->assertNotFound();
    }

    public function test_banner_uu_dai_public_khong_can_dang_nhap(): void
    {
        $this->voucher();
        $this->campaign(['send_at' => now()->subHour()]);

        $response = $this->getJson('/api/promotions/featured');

        $response->assertOk()
            ->assertJsonPath('data.0.voucher_code', 'MI-CAY-20')
            ->assertJsonPath('data.0.discount_label', 'Giảm 20%');
    }

    /* ============================================================ */
    /* Cron                                                       */
    /* ============================================================ */

    public function test_cron_dispatch_job_cho_campaign_den_han(): void
    {
        $this->voucher();
        $this->campaign(['send_at' => now()->subMinute()]);

        \Illuminate\Support\Facades\Bus::fake();

        $this->artisan('marketing:send-campaigns')->assertSuccessful();

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendMarketingCampaignJob::class, 1);
    }

    public function test_cron_khong_dispatch_khi_campaign_chua_den_han(): void
    {
        $this->voucher();
        $this->campaign(['send_at' => now()->addHour()]);

        \Illuminate\Support\Facades\Bus::fake();

        $this->artisan('marketing:send-campaigns')->assertSuccessful();

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SendMarketingCampaignJob::class);
    }
}
