<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipper_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->foreignId('shipper_id')->constrained('users');
            $table->decimal('actual_distance_km', 8, 3)->nullable();
            $table->decimal('billable_distance_km', 8, 3);
            $table->unsignedInteger('rate_per_km');
            $table->unsignedInteger('amount');
            $table->timestamp('earned_at');
            $table->timestamps();
            $table->index(['shipper_id', 'earned_at']);
        });

        // Existing delivered orders need the same snapshot as new deliveries.
        DB::table('orders')
            ->whereIn('status', ['DELIVERED', 'COMPLETED'])
            ->whereNotNull('shipper_id')
            ->whereNotNull('delivered_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                $rows = [];
                foreach ($orders as $order) {
                    $actual = $order->delivery_distance_km !== null
                        ? max(0, (float) $order->delivery_distance_km) : null;
                    $billable = max(3, $actual ?? 0);
                    $rows[] = [
                        'order_id' => $order->id,
                        'shipper_id' => $order->shipper_id,
                        'actual_distance_km' => $actual,
                        'billable_distance_km' => $billable,
                        'rate_per_km' => 3500,
                        'amount' => (int) (round($billable * 3500 / 100) * 100),
                        'earned_at' => $order->delivered_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('shipper_earnings')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipper_earnings');
    }
};
