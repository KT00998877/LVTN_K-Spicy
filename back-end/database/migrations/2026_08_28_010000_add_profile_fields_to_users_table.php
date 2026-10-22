<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 100)->nullable()->after('name');
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropUnique(['phone']);
            $table->dropColumn(['full_name', 'phone', 'is_active', 'locked_at']);
        });
    }
};