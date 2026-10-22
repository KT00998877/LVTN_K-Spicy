<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_item_ingredient', function (Blueprint $table) {
            $table->boolean('availability_required')->default(false);
        });
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('auto_availability_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn('auto_availability_enabled'));
        Schema::table('menu_item_ingredient', fn (Blueprint $table) => $table->dropColumn('availability_required'));
    }
};
