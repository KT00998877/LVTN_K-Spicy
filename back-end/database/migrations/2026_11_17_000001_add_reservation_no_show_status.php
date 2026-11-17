<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->enum('status', ['PENDING', 'CONFIRMED', 'ARRIVED', 'CANCELLED', 'COMPLETED', 'NO_SHOW'])->default('PENDING')->change();
        });
    }

    public function down(): void
    {
        DB::table('reservations')->where('status', 'NO_SHOW')->update(['status' => 'CANCELLED']);
        Schema::table('reservations', function (Blueprint $table): void {
            $table->enum('status', ['PENDING', 'CONFIRMED', 'ARRIVED', 'CANCELLED', 'COMPLETED'])->default('PENDING')->change();
        });
    }
};
