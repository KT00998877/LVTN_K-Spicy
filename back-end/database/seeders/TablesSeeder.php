<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TablesSeeder — danh sách bàn của nhà hàng Mì Cay.
 *
 * Cấu trúc nhà hàng (2 tầng, mỗi tầng chia "Trước" / "Sau"):
 *
 *   Tầng 1 — Khu phổ thông (11 bàn)
 *   ┌──────────────────────────────────────────┐
 *   │  Trước          │   Sau                  │
 *   │  B01–B04 (4 bàn 2 người)                │
 *   │  B05–B06 (2 bàn 4 người)                │
 *   │  B07–B08 (2 bàn 4 người)                │
 *   │  B09–B10 (2 bàn 6 người)                │
 *   │  B11       (1 bàn 8 người — gần cửa)    │
 *   └──────────────────────────────────────────┘
 *
 *   Tầng 2 — Phòng VIP (3 phòng riêng, dành cho nhóm ăn cay)
 *   ┌──────────────────────────────────────────┐
 *   │  Trước          │   Sau                  │
 *   │  V01 (8 người)  │  V02 (10 người)        │
 *   │  V03 (12 người — gần cửa sổ)            │
 *   └──────────────────────────────────────────┘
 *
 * Tổng: 14 bàn, sức chứa ~78 khách.
 */
class TablesSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [
            // ===== Tầng 1 — Khu phổ thông =====
            // Cụm "Trước" — gần cửa ra vào
            ['code' => 'B01', 'name' => 'Bàn 1',  'capacity' => 2, 'location' => 'Tầng 1'],
            ['code' => 'B02', 'name' => 'Bàn 2',  'capacity' => 2, 'location' => 'Tầng 1'],
            ['code' => 'B03', 'name' => 'Bàn 3',  'capacity' => 2, 'location' => 'Tầng 1'],
            ['code' => 'B04', 'name' => 'Bàn 4',  'capacity' => 2, 'location' => 'Tầng 1'],
            // Cụm "Trước" — bàn 4 người
            ['code' => 'B05', 'name' => 'Bàn 5',  'capacity' => 4, 'location' => 'Tầng 1'],
            ['code' => 'B06', 'name' => 'Bàn 6',  'capacity' => 4, 'location' => 'Tầng 1'],
            // Cụm "Sau" — gần bếp
            ['code' => 'B07', 'name' => 'Bàn 7',  'capacity' => 4, 'location' => 'Tầng 1'],
            ['code' => 'B08', 'name' => 'Bàn 8',  'capacity' => 4, 'location' => 'Tầng 1'],
            // Cụm "Sau" — bàn lớn 6 người
            ['code' => 'B09', 'name' => 'Bàn 9',  'capacity' => 6, 'location' => 'Tầng 1'],
            ['code' => 'B10', 'name' => 'Bàn 10', 'capacity' => 6, 'location' => 'Tầng 1'],
            // Bàn gần cửa — bàn lớn 8 người (nhóm đông)
            ['code' => 'B11', 'name' => 'Bàn 11', 'capacity' => 8, 'location' => 'Tầng 1'],

            // ===== Tầng 2 — Phòng VIP =====
            ['code' => 'V01', 'name' => 'Phòng VIP 1', 'capacity' => 8,  'location' => 'Tầng 2'],
            ['code' => 'V02', 'name' => 'Phòng VIP 2', 'capacity' => 10, 'location' => 'Tầng 2'],
            ['code' => 'V03', 'name' => 'Phòng VIP 3', 'capacity' => 12, 'location' => 'Tầng 2'],
        ];

        foreach ($tables as $t) {
            DB::table('tables')->updateOrInsert(
                ['code' => $t['code']],
                $t + [
                    'status' => 'AVAILABLE',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command?->info('Đã seed ' . count($tables) . ' bàn (' .
            'Tầng 1: ' . count(array_filter($tables, fn($t) => $t['location'] === 'Tầng 1')) . ' bàn, ' .
            'Tầng 2: ' . count(array_filter($tables, fn($t) => $t['location'] === 'Tầng 2')) . ' phòng VIP).');
    }
}
