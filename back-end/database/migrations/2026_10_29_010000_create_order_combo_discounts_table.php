<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_combo_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('suggestion_id')->nullable();
            $table->unsignedBigInteger('main_item_id');
            $table->unsignedBigInteger('suggested_item_id');
            $table->string('main_item_name', 150);
            $table->string('suggested_item_name', 150);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('original_unit_price', 12, 2);
            $table->decimal('combo_unit_price', 12, 2);
            $table->decimal('discount_amount', 14, 2);
            $table->timestamps();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_combo_discounts');
    }
};
