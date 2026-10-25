<?php

namespace Tests\Feature;

use App\Services\Period;
use App\Services\StatisticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagerCustomerStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });
        Schema::create('user_role', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('color');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status');
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('status');
            $table->decimal('amount', 14, 2);
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function test_customer_report_separates_period_spending_from_accumulated_loyalty(): void
    {
        $now = now()->toDateTimeString();
        $tierId = DB::table('loyalty_tiers')->insertGetId([
            'code' => 'GOLD', 'name' => 'Vàng', 'color' => '#aa8800', 'icon' => '⭐', 'is_active' => true, 'sort_order' => 2,
        ]);
        $customerId = DB::table('users')->insertGetId([
            'name' => 'Khách Vàng', 'phone' => null, 'email' => null, 'loyalty_points' => 75,
            'loyalty_tier_id' => $tierId, 'total_spent' => 2500000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $customerRole = DB::table('roles')->insertGetId(['code' => 'CUSTOMER']);
        DB::table('user_role')->insert(['user_id' => $customerId, 'role_id' => $customerRole]);
        $orderId = DB::table('orders')->insertGetId(['customer_id' => $customerId, 'status' => 'COMPLETED', 'completed_at' => '2026-10-24 08:00:00']);
        $invoiceId = DB::table('invoices')->insertGetId(['order_id' => $orderId]);
        DB::table('payments')->insert(['invoice_id' => $invoiceId, 'status' => 'SUCCESS', 'amount' => 125000, 'processed_at' => '2026-10-24 08:00:00']);

        $period = new Period(
            Carbon::parse('2026-10-24 00:00:00', 'Asia/Ho_Chi_Minh'),
            Carbon::parse('2026-10-24 23:59:59', 'Asia/Ho_Chi_Minh'),
        );
        $data = app(StatisticsService::class)->customerStats($period, $period->previous());

        $this->assertSame(125000.0, $data['top_customers'][0]['total_spent']);
        $this->assertSame(2500000.0, $data['top_loyalty_customers'][0]['total_spent']);
        $this->assertSame(75, $data['top_loyalty_customers'][0]['loyalty_points']);
        $this->assertSame(1, $data['loyalty_tiers'][0]['customer_count']);
        $this->assertSame('Vàng', $data['loyalty_tiers'][0]['name']);
    }
}
