<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['name' => 'Quản lý nhà hàng', 'email' => 'manager@micay.local',  'role' => 'MANAGER'],
            ['name' => 'Phục vụ Hưng',     'email' => 'waiter@micay.local',   'role' => 'WAITER'],
            ['name' => 'Bếp trưởng Linh',  'email' => 'kitchen@micay.local',  'role' => 'KITCHEN'],
            ['name' => 'Thu ngân Mai',      'email' => 'cashier@micay.local',  'role' => 'CASHIER'],
            ['name' => 'Giao hàng Minh',   'email' => 'shipper@micay.local',  'role' => 'SHIPPER'],
            ['name' => 'Khách Thanh',      'email' => 'customer@micay.local', 'role' => 'CUSTOMER'],
            ['name' => 'Nhà cung cấp K',   'email' => 'supplier@micay.local', 'role' => 'SUPPLIER'],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'full_name' => $account['name'],
                    'password' => Hash::make('00998877'),
                    'is_active' => true,
                    'can_close_daily_stock' => $account['role'] === 'KITCHEN',
                    'supplier_id' => $account['role'] === 'SUPPLIER'
                        ? DB::table('suppliers')->where('code', 'SUPPLIER_K')->value('id')
                        : null,
                    'email_verified_at' => now(),
                ]
            );

            $roleId = DB::table('roles')->where('code', $account['role'])->value('id');
            if ($roleId) {
                DB::table('user_role')->updateOrInsert(
                    ['user_id' => $user->id, 'role_id' => $roleId],
                    ['assigned_at' => now()]
                );
            }
        }
    }
}
