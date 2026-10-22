<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loyalty_tiers')->where('code', 'DIAMOND')->update([
            'code' => 'VIP',
            'name' => 'VIP',
            'color' => '#111111',
            'icon' => '♛',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('loyalty_tiers')->where('code', 'VIP')->update([
            'code' => 'DIAMOND',
            'name' => 'Kim Cương',
            'color' => '#0EA5E9',
            'icon' => '💎',
            'updated_at' => now(),
        ]);
    }
};
