<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$total = DB::table('menu_items')->count();
$withImg = DB::table('menu_items')->whereNotNull('image_url')->where('image_url', '!=', '')->count();
$noImg = $total - $withImg;

echo "Tong mon: $total\n";
echo "Co anh: $withImg\n";
echo "Khong anh: $noImg\n";
echo "--- Theo danh muc ---\n";

$rows = DB::table('menu_items')
    ->join('categories', 'categories.id', '=', 'menu_items.category_id')
    ->select('categories.name as cat', DB::raw('count(*) as total'), DB::raw('sum(case when menu_items.image_url is not null then 1 else 0 end) as with_img'), 'categories.sort_order')
    ->groupBy('categories.name', 'categories.sort_order')
    ->orderBy('categories.sort_order')
    ->get();

foreach ($rows as $r) {
    echo "{$r->cat}: {$r->total} mon ({$r->with_img} co anh)\n";
}

echo "--- Mon moi them ---\n";
$newOnes = DB::table('menu_items')
    ->whereIn('slug', ['7-up', 'sting', 'soda-dau'])
    ->select('name', 'slug', 'image_url')
    ->get();
foreach ($newOnes as $n) {
    echo "{$n->name} ({$n->slug}) -> {$n->image_url}\n";
}

echo "--- Mon con thieu anh (neu co) ---\n";
$missing = DB::table('menu_items')
    ->where(function ($q) {
        $q->whereNull('image_url')->orWhere('image_url', '');
    })
    ->select('name', 'slug')
    ->get();
if ($missing->isEmpty()) {
    echo "(Khong co)\n";
} else {
    foreach ($missing as $m) {
        echo "- {$m->name} ({$m->slug})\n";
    }
}
