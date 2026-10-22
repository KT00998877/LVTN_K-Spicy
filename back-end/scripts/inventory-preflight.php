<?php

// Read-only inventory rollout check. Does not create a baseline or modify stock.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$migrations = [
    '2026_09_30_010000_create_daily_stock_sheets',
    '2026_09_30_020000_add_stock_document_details',
    '2026_09_30_030000_add_recipe_snapshot_to_order_items',
    '2026_09_30_040000_add_daily_stock_explanations',
];
$applied = DB::table('migrations')->whereIn('migration', $migrations)->pluck('migration')->all();
$pending = array_values(array_diff($migrations, $applied));
$hasSheets = Schema::hasTable('daily_stock_sheets');
$hasLeadFlag = Schema::hasColumn('users', 'can_close_daily_stock');
$closedCount = $hasSheets ? DB::table('daily_stock_sheets')->where('status', 'CLOSED')->count() : 0;
$kitchenLeads = $hasLeadFlag ? DB::table('users as u')
    ->join('user_role as ur', 'ur.user_id', '=', 'u.id')
    ->join('roles as r', 'r.id', '=', 'ur.role_id')
    ->where('r.code', 'KITCHEN')->where('u.is_active', true)
    ->where('u.can_close_daily_stock', true)->distinct()->count('u.id') : 0;

echo json_encode([
    'database' => DB::connection()->getDatabaseName(),
    'pending_inventory_migrations' => $pending,
    'active_ingredients' => DB::table('ingredients')->where('is_active', true)->count(),
    'legacy_order_stock_transactions' => DB::table('inventory_transactions')->where('reference_type', 'order')->count(),
    'legacy_stocktake_adjustments' => DB::table('inventory_transactions')->where('reference_type', 'stocktake')->count(),
    'closed_daily_sheets' => $closedCount,
    'active_kitchen_leads' => $kitchenLeads,
    'ready_for_daily_operation' => $pending === [] && $closedCount > 0 && $kitchenLeads > 0,
    'database_modified' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
