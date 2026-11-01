<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LoyaltyTiersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $tiers = [
            [
                'code' => 'BRONZE',
                'name' => 'Đồng',
                'min_spent' => 0,
                'color' => '#B45309',
                'icon' => '🥉',
                'benefits' => [
                    'point_multiplier' => 1.0,
                    'free_ship_threshold' => null,
                    'extra_discount_pct' => 0,
                    'description' => 'Tích điểm cơ bản 1đ/10.000đ. Ưu đãi sinh nhật hằng năm.',
                ],
                'sort_order' => 1,
            ],
            [
                'code' => 'SILVER',
                'name' => 'Bạc',
                'min_spent' => 2_000_000,
                'color' => '#94A3B8',
                'icon' => '🥈',
                'benefits' => [
                    'point_multiplier' => 1.2,
                    'free_ship_threshold' => 100_000,
                    'extra_discount_pct' => 3,
                    'description' => 'Tích điểm x1.2, miễn phí ship đơn từ 100.000đ, giảm thêm 3% vào dịp lễ.',
                ],
                'sort_order' => 2,
            ],
            [
                'code' => 'GOLD',
                'name' => 'Vàng',
                'min_spent' => 5_000_000,
                'color' => '#D71920',
                'icon' => '🥇',
                'benefits' => [
                    'point_multiplier' => 1.5,
                    'free_ship_threshold' => 50_000,
                    'extra_discount_pct' => 5,
                    'description' => 'Tích điểm x1.5, miễn phí ship đơn từ 50.000đ, giảm thêm 5% cuối tuần.',
                ],
                'sort_order' => 3,
            ],
            [
                'code' => 'VIP',
                'name' => 'VIP',
                'min_spent' => 15_000_000,
                'color' => '#111111',
                'icon' => '♛',
                'benefits' => [
                    'point_multiplier' => 2.0,
                    'free_ship_threshold' => 0,
                    'extra_discount_pct' => 10,
                    'description' => 'Tích điểm x2, miễn phí ship mọi đơn, giảm thêm 10% trọn đời.',
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($tiers as $tier) {
            DB::table('loyalty_tiers')->updateOrInsert(
                ['code' => $tier['code']],
                [
                    ...$tier,
                    'benefits' => json_encode($tier['benefits'], JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
