<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'MANAGER',  'name' => 'Quản lý nhà hàng', 'description' => 'Toàn quyền quản trị hệ thống'],
            ['code' => 'SHIPPER',  'name' => 'Giao hàng', 'description' => 'Nhân viên giao hàng'],
            ['code' => 'CUSTOMER', 'name' => 'Khách hàng',        'description' => 'Đặt món, xem menu, thanh toán trực tuyến'],
            ['code' => 'WAITER',   'name' => 'Nhân viên phục vụ', 'description' => 'Phục vụ bàn, lên món, tạo hóa đơn'],
            ['code' => 'KITCHEN',  'name' => 'Nhân viên bếp',     'description' => 'Chế biến món, cập nhật trạng thái'],
            ['code' => 'CASHIER',  'name' => 'Thu ngân',          'description' => 'Quản lý thanh toán, đặt bàn, giờ công'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['code' => $role['code']], $role + ['created_at' => now(), 'updated_at' => now()]);
        }

        $permissions = [
            ['code' => 'MENU_VIEW',         'module' => 'MENU',      'description' => 'Xem menu'],
            ['code' => 'MENU_MANAGE',       'module' => 'MENU',      'description' => 'Thêm/sửa/xóa món ăn và danh mục'],
            ['code' => 'CATEGORY_MANAGE',   'module' => 'CATEGORY',  'description' => 'Quản lý danh mục món ăn'],

            ['code' => 'INGREDIENT_VIEW',   'module' => 'INGREDIENT','description' => 'Xem nguyên liệu'],
            ['code' => 'INGREDIENT_MANAGE', 'module' => 'INGREDIENT','description' => 'Quản lý nguyên liệu'],
            ['code' => 'INVENTORY_VIEW',    'module' => 'INVENTORY', 'description' => 'Xem tồn kho'],
            ['code' => 'INVENTORY_MANAGE',  'module' => 'INVENTORY', 'description' => 'Nhập/xuất/điều chỉnh kho'],

            ['code' => 'TABLE_VIEW',        'module' => 'TABLE',     'description' => 'Xem danh sách bàn'],
            ['code' => 'TABLE_MANAGE',      'module' => 'TABLE',     'description' => 'Mở/hủy bàn, tạo phiên phục vụ'],
            ['code' => 'RESERVATION_MANAGE','module' => 'TABLE',     'description' => 'Quản lý đặt bàn trước'],

            ['code' => 'ORDER_CREATE',      'module' => 'ORDER',     'description' => 'Tạo đơn hàng'],
            ['code' => 'ORDER_VIEW',        'module' => 'ORDER',     'description' => 'Xem đơn hàng'],
            ['code' => 'ORDER_UPDATE',      'module' => 'ORDER',     'description' => 'Cập nhật trạng thái đơn hàng'],
            ['code' => 'ORDER_ITEM_UPDATE', 'module' => 'ORDER',     'description' => 'Cập nhật trạng thái món trong đơn'],

            ['code' => 'INVOICE_CREATE',    'module' => 'INVOICE',   'description' => 'Tạo hóa đơn'],
            ['code' => 'INVOICE_VIEW',      'module' => 'INVOICE',   'description' => 'Xem hóa đơn'],
            ['code' => 'INVOICE_ADJUST',    'module' => 'INVOICE',   'description' => 'Điều chỉnh hóa đơn (BR07)'],

            ['code' => 'PAYMENT_PROCESS',   'module' => 'PAYMENT',   'description' => 'Xử lý thanh toán'],

            ['code' => 'BLOG_VIEW',         'module' => 'BLOG',      'description' => 'Xem bài viết'],
            ['code' => 'BLOG_MANAGE',       'module' => 'BLOG',      'description' => 'Quản lý bài viết'],

            ['code' => 'USER_MANAGE',       'module' => 'USER',      'description' => 'Quản lý tài khoản'],
            ['code' => 'ROLE_MANAGE',       'module' => 'ROLE',      'description' => 'Quản lý phân quyền'],

            ['code' => 'WORKSHIFT_MANAGE',  'module' => 'WORKSHIFT', 'description' => 'Quản lý giờ công'],

            ['code' => 'REPORT_VIEW',       'module' => 'REPORT',    'description' => 'Xem báo cáo thống kê'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(['code' => $perm['code']], $perm + ['created_at' => now(), 'updated_at' => now()]);
        }

        $rolePermissions = [
            'MANAGER' => [
                'MENU_VIEW','MENU_MANAGE','CATEGORY_MANAGE',
                'INGREDIENT_VIEW','INGREDIENT_MANAGE','INVENTORY_VIEW','INVENTORY_MANAGE',
                'TABLE_VIEW','TABLE_MANAGE','RESERVATION_MANAGE',
                'ORDER_CREATE','ORDER_VIEW','ORDER_UPDATE','ORDER_ITEM_UPDATE',
                'INVOICE_CREATE','INVOICE_VIEW','INVOICE_ADJUST',
                'PAYMENT_PROCESS',
                'BLOG_VIEW','BLOG_MANAGE',
                'USER_MANAGE','ROLE_MANAGE',
                'WORKSHIFT_MANAGE',
                'REPORT_VIEW',
            ],
            'CUSTOMER' => [
                'MENU_VIEW','ORDER_CREATE','ORDER_VIEW','INVOICE_VIEW','PAYMENT_PROCESS','BLOG_VIEW',
            ],
            'WAITER' => [
                'MENU_VIEW','TABLE_VIEW','TABLE_MANAGE',
                'ORDER_CREATE','ORDER_VIEW','ORDER_UPDATE','ORDER_ITEM_UPDATE',
                'INVOICE_CREATE','INVOICE_VIEW','PAYMENT_PROCESS',
                'RESERVATION_MANAGE',
            ],
            'KITCHEN' => [
                'MENU_VIEW','INGREDIENT_VIEW','INVENTORY_VIEW',
                'ORDER_VIEW','ORDER_ITEM_UPDATE','REPORT_VIEW',
            ],
            'CASHIER' => [
                'MENU_VIEW','TABLE_VIEW','RESERVATION_MANAGE',
                'ORDER_VIEW','INVOICE_CREATE','INVOICE_VIEW','PAYMENT_PROCESS',
                'WORKSHIFT_MANAGE','REPORT_VIEW',
            ],
        ];

        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permIds = DB::table('permissions')->pluck('id', 'code');

        foreach ($rolePermissions as $roleCode => $permCodes) {
            $roleId = $roleIds[$roleCode] ?? null;
            if (!$roleId) {
                continue;
            }
            foreach ($permCodes as $permCode) {
                $permId = $permIds[$permCode] ?? null;
                if (!$permId) {
                    continue;
                }
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    []
                );
            }
        }
    }
};
