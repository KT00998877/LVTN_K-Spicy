<?php

namespace Tests\Feature;

use App\Models\MarketingCampaign;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
