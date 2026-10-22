<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('delivery_distance_km', 8, 3)->nullable();
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->timestamp('points_refunded_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
            $table->string('payment_code', 40)->nullable()->unique();
            $table->string('provider_transaction_id', 100)->nullable();
            $table->string('bank_code', 30)->nullable();
            $table->string('response_code', 10)->nullable();
            $table->string('transaction_status', 10)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('payment_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['payment_code']);
            $table->dropColumn(['provider', 'payment_code', 'provider_transaction_id', 'bank_code', 'response_code', 'transaction_status', 'expired_at', 'paid_at', 'raw_response', 'payment_url']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['delivery_latitude', 'delivery_longitude', 'delivery_distance_km', 'points_redeemed', 'points_refunded_at']));
    }
};
