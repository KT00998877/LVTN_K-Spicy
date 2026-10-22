<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CancellationsSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => 'KHACH_DOI_Y',         'name' => 'Khách đổi ý',                 'requires_manager' => false, 'sort_order' => 1],
            ['code' => 'KHACH_DI_VE',         'name' => 'Khách rời nhà hàng',          'requires_manager' => false, 'sort_order' => 2],
            ['code' => 'HET_NGUYEN_LIEU',     'name' => 'Hết nguyên liệu',             'requires_manager' => false, 'sort_order' => 3],
            ['code' => 'HET_THOI_GIAN',       'name' => 'Hết thời gian phục vụ',       'requires_manager' => true,  'sort_order' => 4],
            ['code' => 'NAU_SAI',             'name' => 'Bếp nấu sai món',             'requires_manager' => false, 'sort_order' => 5],
            ['code' => 'CHAT_LUONG_KHONG_DAT','name' => 'Chất lượng không đạt',       'requires_manager' => true,  'sort_order' => 6],
            ['code' => 'MON_DANG_CHE_BIEN',   'name' => 'Món đã bắt đầu chế biến',     'requires_manager' => true,  'sort_order' => 7],
            ['code' => 'LOI_HE_THONG',        'name' => 'Lỗi hệ thống',                'requires_manager' => true,  'sort_order' => 8],
            ['code' => 'KHAC',                'name' => 'Lý do khác',                  'requires_manager' => false, 'sort_order' => 99],
        ];

        foreach ($reasons as $r) {
            DB::table('cancellations')->updateOrInsert(
                ['code' => $r['code']],
                $r + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
};