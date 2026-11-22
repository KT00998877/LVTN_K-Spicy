<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LoyaltyTiersSeeder::class,
            CategoriesAndMenuItemsSeeder::class,
            MenuItemDescriptionsSeeder::class,
            InventorySeeder::class,
            PurchaseOrderPackagingSeeder::class,
            SupplierPricesSeeder::class,
            TablesSeeder::class,
            CancellationsSeeder::class,
            VoucherSeeder::class,
            LiveChatQuickReplySeeder::class,
            DemoAccountsSeeder::class,
        ]);
    }
}
