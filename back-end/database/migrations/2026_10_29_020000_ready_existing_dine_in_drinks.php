<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_items')
            ->where('status', 'PENDING')
            ->whereIn('order_id', DB::table('orders')->select('id')
                ->where('order_type', 'DINE_IN')
                ->whereNotIn('status', ['COMPLETED', 'CANCELLED']))
            ->whereIn('menu_item_id', DB::table('menu_items')->select('id')
                ->whereIn('category_id', DB::table('categories')->select('id')->where('slug', 'do-uong')))
            ->update(['status' => 'READY', 'ready_at' => now()]);
    }

    public function down(): void
    {
        // Existing service state must not be reset during rollback.
    }
};
