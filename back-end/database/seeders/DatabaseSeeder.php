<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CategoriesAndMenuItemsSeeder::class,
            InventorySeeder::class,
            TablesSeeder::class,
            CancellationsSeeder::class,
            VoucherSeeder::class,
        ]);

        $this->seedDefaultAccounts();
    }

    private function seedDefaultAccounts(): void
    {
        $accounts = [
            ['name' => 'Quản lý nhà hàng', 'email' => 'manager@micay.local',  'role' => 'MANAGER'],
            ['name' => 'Phục vụ Hưng',     'email' => 'waiter@micay.local',   'role' => 'WAITER'],
            ['name' => 'Bếp trưởng Linh',  'email' => 'kitchen@micay.local',  'role' => 'KITCHEN'],
            ['name' => 'Thu ngân Mai',      'email' => 'cashier@micay.local',  'role' => 'CASHIER'],
            ['name' => 'Giao hàng Minh',   'email' => 'shipper@micay.local',  'role' => 'SHIPPER'],
            ['name' => 'Khách Thanh',      'email' => 'customer@micay.local', 'role' => 'CUSTOMER'],
        ];

        foreach ($accounts as $acc) {
            $user = User::updateOrCreate(
                ['email' => $acc['email']],
                [
                    'name' => $acc['name'],
                    'full_name' => $acc['name'],
                    'password' => Hash::make('00998877'),
                    'is_active' => true,
                    'can_close_daily_stock' => $acc['role'] === 'KITCHEN',
                    'email_verified_at' => now(),
                ]
            );

            $roleId = DB::table('roles')->where('code', $acc['role'])->value('id');
            if ($roleId) {
                DB::table('user_role')->updateOrInsert(
                    ['user_id' => $user->id, 'role_id' => $roleId],
                    ['assigned_at' => now()]
                );
            }
        }
    }
};
