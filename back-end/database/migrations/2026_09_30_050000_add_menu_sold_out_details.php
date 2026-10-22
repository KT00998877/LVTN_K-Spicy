<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('sold_out_reason', 255)->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->foreignId('sold_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recipe_managed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_out_by');
            $table->dropColumn(['sold_out_reason', 'sold_out_at', 'recipe_managed_at']);
        });
    }
};
