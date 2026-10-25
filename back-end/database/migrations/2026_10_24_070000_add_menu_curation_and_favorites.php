<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('is_best_seller')->default(false)->after('is_featured')->index();
        });

        Schema::create('user_favorite_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorite_menu_items');

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropColumn('is_best_seller');
        });
    }
};
