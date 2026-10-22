<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * VoucherSeeder — chèn các mã khuyến mãi mẫu để demo & test.
 *
 * Idempotent: dùng updateOrCreate theo `code`.
 */
class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::where('email', 'manager@micay.local')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('code', 'MANAGER'))->first();

        $now = now();
        $endOfMonth = $now->copy()->endOfMonth();

        $vouchers = [
            [
                'code'             => 'WELCOME10',
                'name'             => 'Chào khách mới - Giảm 10%',
                'description'      => 'Giảm 10% tổng đơn (tối đa 30.000đ), áp dụng cho đơn từ 100.000đ.',
                'type'             => 'PERCENT',
                'value'            => 10,
                'max_discount_amount' => 30000,
                'min_order_subtotal'  => 100000,
                'usage_limit'         => 200,
                'usage_limit_per_user'=> 1,
                'is_active'           => true,
            ],
            [
                'code'             => 'SPICY20K',
                'name'             => 'Giảm ngay 20K',
                'description'      => 'Giảm cố định 20.000đ cho đơn từ 150.000đ.',
                'type'             => 'FIXED',
                'value'            => 20000,
                'max_discount_amount' => null,
                'min_order_subtotal'  => 150000,
                'usage_limit'         => null,
                'usage_limit_per_user'=> 2,
                'is_active'           => true,
            ],
            [
                'code'             => 'FREESHIP',
                'name'             => 'Freeship đến 15K',
                'description'      => 'Giảm 15.000đ tiền ship cho đơn ONLINE từ 80.000đ.',
                'type'             => 'FIXED',
                'value'            => 15000,
                'max_discount_amount' => null,
                'min_order_subtotal'  => 80000,
                'usage_limit'         => 50,
                'usage_limit_per_user'=> 1,
                'is_active'           => true,
                'starts_at'           => $now->copy()->subDays(7),
                'ends_at'             => $endOfMonth,
            ],
            [
                'code'             => 'WEEKEND15',
                'name'             => 'Cuối tuần - Giảm 15%',
                'description'      => 'Giảm 15% (tối đa 50K) cho đơn từ 200K. Có hạn cuối tuần.',
                'type'             => 'PERCENT',
                'value'            => 15,
                'max_discount_amount' => 50000,
                'min_order_subtotal'  => 200000,
                'usage_limit'         => 100,
                'usage_limit_per_user'=> 1,
                'is_active'           => true,
            ],
        ];

        foreach ($vouchers as $payload) {
            $payload['created_by'] = $manager?->id;
            Voucher::updateOrCreate(['code' => $payload['code']], $payload);
        }
    }
}