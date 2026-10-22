<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->default(0);
        });

        Schema::create('checkout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('order_code_sequences', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_requests');
        Schema::dropIfExists('order_code_sequences');
        Schema::table('carts', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
