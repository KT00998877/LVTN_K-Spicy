<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->unique();
            $table->unsignedBigInteger('stock_before')->nullable();
            $table->unsignedBigInteger('stock_after')->nullable();
        });
    }
    public function down(): void {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'stock_before', 'stock_after']);
        });
    }
};
