<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('supplier_price', 14, 2)->nullable();
        });
        Schema::table('ingredient_packagings', function (Blueprint $table) {
            $table->decimal('supplier_price', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_packagings', fn (Blueprint $table) => $table->dropColumn('supplier_price'));
        Schema::table('ingredients', fn (Blueprint $table) => $table->dropColumn('supplier_price'));
    }
};
