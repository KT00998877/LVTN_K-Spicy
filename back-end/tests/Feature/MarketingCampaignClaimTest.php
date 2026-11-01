<?php

namespace Tests\Feature;

use App\Models\MarketingCampaign;
use App\Jobs\SendMarketingCampaignJob;
use App\Services\MarketingCampaignService;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingCampaignClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->decimal('value', 14, 2);
            $table->decimal('max_discount_amount', 14, 2)->nullable();
            $table->decimal('min_order_subtotal', 14, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('message');
            $table->string('voucher_code');
            $table->string('banner_image_url')->nullable();
            $table->string('accent_color')->default('#E83A14');
            $table->string('cta_text')->default('Nhận ưu đãi');
            $table->string('landing_url')->nullable();
            $table->json('target_tier_ids')->nullable();
            $table->timestamp('send_at');
            $table->time('window_start')->default('11:00');
            $table->time('window_end')->default('13:30');
            $table->string('status')->default('SCHEDULED');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('marketing_campaign_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_campaign_id');
            $table->unsignedBigInteger('user_id');
            $table->string('voucher_code');
            $table->timestamp('claimed_at');
            $table->timestamps();
            $table->unique(['marketing_campaign_id', 'user_id']);
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->string('deduplication_key')->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('voucher_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('voucher_id');
            $table->unsignedBigInteger('user_id');
        });
    }

    public function test_customer_claim_is_idempotent_and_visible_in_personal_list(): void
    {
        $user = $this->user(2);
        $campaign = $this->campaign([2]);
        Sanctum::actingAs($user);

        $this->postJson("/api/promotions/{$campaign->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.voucher_code', 'SPICY20');
        $this->postJson("/api/promotions/{$campaign->id}/claim")->assertOk();

        $this->assertDatabaseCount('marketing_campaign_claims', 1);
        $this->getJson('/api/promotions/me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.campaign_id', $campaign->id);
    }

    public function test_deleted_sent_campaign_preserves_claimed_voucher_history(): void
    {
        $user = $this->user(2);
        $campaign = $this->campaign([2]);
        Sanctum::actingAs($user);

        $this->postJson("/api/promotions/{$campaign->id}/claim")->assertOk();
        $campaign->delete();

        $this->assertSoftDeleted('marketing_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseCount('marketing_campaign_claims', 1);
        $this->getJson('/api/promotions/me')
            ->assertOk()
            ->assertJsonPath('data.0.name', $campaign->name)
            ->assertJsonPath('data.0.voucher_code', 'SPICY20');
    }

    public function test_expired_voucher_skips_due_campaign_without_notifying_customers(): void
    {
        $campaign = $this->campaign([2]);
        $campaign->forceFill(['status' => MarketingCampaign::STATUS_SCHEDULED])->save();
        Voucher::where('code', 'SPICY20')->update(['ends_at' => now()->subMinute()]);

        (new SendMarketingCampaignJob($campaign->id))->handle(app(MarketingCampaignService::class));

        $this->assertSame(MarketingCampaign::STATUS_SKIPPED, $campaign->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_due_campaign_does_not_wait_for_old_time_window(): void
    {
        $campaign = $this->campaign([2]);
        $campaign->forceFill([
            'status' => MarketingCampaign::STATUS_SCHEDULED,
            'window_start' => '00:00',
            'window_end' => '00:01',
        ])->save();

        $this->assertTrue(app(MarketingCampaignService::class)->dueCampaigns()->contains('id', $campaign->id));
    }

    public function test_send_now_with_inactive_voucher_keeps_campaign_scheduled(): void
    {
        $campaign = $this->campaign([2]);
        $campaign->forceFill(['status' => MarketingCampaign::STATUS_SCHEDULED])->save();
        Voucher::where('code', 'SPICY20')->update(['is_active' => false]);

        try {
            app(MarketingCampaignService::class)->send($campaign);
            $this->fail('Expected an invalid voucher response.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(MarketingCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    public function test_received_voucher_appears_in_my_vouchers_without_duplicate_after_claim(): void
    {
        $user = $this->user(2);
        $other = $this->user(1);
        $campaign = $this->campaign([2]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'customer.promo',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['event' => 'PROMO_CAMPAIGN', 'name' => 'Giờ vàng', 'message' => 'Giảm 20%', 'voucher_code' => 'SPICY20']),
            'deduplication_key' => 'promo:'.$campaign->id.':'.$user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/vouchers/me')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voucher_code', 'SPICY20')
            ->assertJsonPath('data.0.is_usable', true);

        $this->postJson("/api/promotions/{$campaign->id}/claim")->assertOk();
        $this->getJson('/api/vouchers/me')->assertJsonCount(1, 'data');

        DB::table('voucher_usages')->insert(['voucher_id' => Voucher::where('code', 'SPICY20')->value('id'), 'user_id' => $user->id]);
        $this->getJson('/api/vouchers/me')->assertJsonPath('data.0.is_usable', false);

        Sanctum::actingAs($other);
        $this->getJson('/api/vouchers/me')->assertJsonCount(0, 'data');
    }

    public function test_customer_outside_target_tier_cannot_claim(): void
    {
        Sanctum::actingAs($this->user(1));
        $campaign = $this->campaign([2]);

        $this->postJson("/api/promotions/{$campaign->id}/claim")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('promotion');
    }

    private function user(int $tierId): User
    {
        $user = User::create([
            'name' => 'Khách hàng',
            'email' => "tier{$tierId}@example.com",
            'password' => 'secret123',
        ]);
        $user->forceFill(['loyalty_tier_id' => $tierId])->save();

        return $user;
    }

    /** @param list<int> $tierIds */
    private function campaign(array $tierIds): MarketingCampaign
    {
        Voucher::create([
            'code' => 'SPICY20',
            'name' => 'Giảm 20%',
            'type' => Voucher::TYPE_PERCENT,
            'value' => 20,
            'min_order_subtotal' => 0,
            'usage_limit' => 100,
            'used_count' => 0,
            'usage_limit_per_user' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        return MarketingCampaign::create([
            'name' => 'Giờ vàng',
            'message' => 'Giảm 20% cho món cay.',
            'voucher_code' => 'SPICY20',
            'target_tier_ids' => $tierIds,
            'send_at' => now()->subMinute(),
            'window_start' => '00:00',
            'window_end' => '23:59',
            'status' => MarketingCampaign::STATUS_SENT,
        ]);
    }
}
