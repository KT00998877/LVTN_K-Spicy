<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_close_daily_stock')->default(false);
        });
        Schema::create('daily_stock_sheets', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->enum('status', ['DRAFT', 'CLOSED'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('movement_version')->default(0);
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_stock_sheet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_stock_sheet_id')->constrained('daily_stock_sheets')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('opening_qty', 12, 2)->nullable();
            $table->decimal('incoming_qty', 12, 2)->default(0);
            $table->decimal('outgoing_qty', 12, 2)->default(0);
            $table->decimal('correction_qty', 12, 2)->default(0);
            $table->decimal('counted_qty', 12, 2)->nullable();
            $table->decimal('used_qty', 12, 2)->nullable();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['daily_stock_sheet_id', 'ingredient_id'], 'daily_sheet_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stock_sheet_items');
        Schema::dropIfExists('daily_stock_sheets');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_close_daily_stock');
        });
    }
};
