<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DANH SÁCH BÀN ===\n\n";
$tables = DB::table('tables')
    ->orderBy('location')
    ->orderBy('code')
    ->get();

$byLocation = $tables->groupBy('location');
foreach ($byLocation as $location => $group) {
    echo "--- $location ---\n";
    $totalCapacity = 0;
    foreach ($group as $t) {
        printf("  %-4s  %-15s  %2d chỗ  |  trạng thái: %s\n",
            $t->code, $t->name, $t->capacity, $t->status);
        $totalCapacity += $t->capacity;
    }
    echo sprintf("  → %d bàn, tổng %d chỗ\n\n", $group->count(), $totalCapacity);
}

echo "=== TỔNG KẾT ===\n";
echo "Tổng bàn: " . $tables->count() . "\n";
echo "Tổng sức chứa: " . $tables->sum('capacity') . " khách\n";
