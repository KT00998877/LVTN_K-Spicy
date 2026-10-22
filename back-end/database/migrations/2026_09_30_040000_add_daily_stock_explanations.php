<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_stock_sheet_items', function (Blueprint $table) {
            $table->string('explanation', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_stock_sheet_items', fn (Blueprint $table) => $table->dropColumn('explanation'));
    }
};
