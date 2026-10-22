<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreignId('ingredient_category_id')->nullable()->after('supplier_id')
                ->constrained('ingredient_categories')->nullOnDelete();
        });

        Schema::create('ingredient_packagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purchase_unit', 30);
            $table->decimal('conversion_quantity', 12, 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['ingredient_id', 'supplier_id', 'purchase_unit'], 'ingredient_packaging_unique');
        });

        Schema::table('stock_import_items', function (Blueprint $table) {
            $table->foreignId('ingredient_packaging_id')->nullable()->after('ingredient_id')
                ->constrained('ingredient_packagings')->nullOnDelete();
            $table->decimal('purchase_quantity', 12, 3)->nullable()->after('quantity');
            $table->string('purchase_unit', 30)->nullable()->after('purchase_quantity');
            $table->decimal('conversion_quantity', 12, 3)->nullable()->after('purchase_unit');
            $table->decimal('purchase_unit_price', 14, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_import_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_packaging_id');
            $table->dropColumn(['purchase_quantity', 'purchase_unit', 'conversion_quantity', 'purchase_unit_price']);
        });
        Schema::dropIfExists('ingredient_packagings');
        Schema::table('ingredients', fn (Blueprint $table) => $table->dropConstrainedForeignId('ingredient_category_id'));
        Schema::dropIfExists('ingredient_categories');
    }
};
