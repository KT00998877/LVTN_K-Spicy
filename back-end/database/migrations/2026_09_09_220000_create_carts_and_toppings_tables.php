<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: tạo giỏ hàng (cart), topping cho order_item, và cột spicy_level_override.
 *
 * - carts          : 1 cart/user (chưa checkout)
 * - cart_items     : các dòng món trong cart
 * - order_item_toppings : quan hệ topping cho từng order_item
 *
 * Đồng thời thêm cột spicy_level_override vào order_items để lưu độ cay KH chọn
 * (khác với spicy_level mặc định của menu_items).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ====== 1. Carts ======
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();
            // Mỗi user chỉ có 1 cart active → unique user_id
            $table->unique('user_id');
        });

        // ====== 2. Cart Items ======
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')
                ->constrained('carts')
                ->cascadeOnDelete();
            $table->foreignId('menu_item_id')
                ->constrained('menu_items')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedTinyInteger('spicy_level')->default(0)
                ->comment('0-7: cấp độ cay KH chọn khi đặt (0=Không cay, 7=Cấp 7)');
            $table->text('note')->nullable()
                ->comment('Ghi chú của khách cho món này');
            $table->timestamps();
            $table->index(['cart_id']);
            $table->index(['menu_item_id']);
        });

        // ====== 3. Order Item Toppings ======
        Schema::create('order_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->cascadeOnDelete();
            $table->foreignId('topping_menu_item_id')
                ->constrained('menu_items')
                ->restrictOnDelete()
                ->comment('FK tới menu_items (category = topping-them)');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
            $table->index(['order_item_id']);
        });

        // ====== 4. Cart Item Toppings ======
        Schema::create('cart_item_toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')
                ->constrained('cart_items')
                ->cascadeOnDelete();
            $table->foreignId('topping_menu_item_id')
                ->constrained('menu_items')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            $table->index(['cart_item_id']);
        });

        // ====== 5. Thêm spicy_level_override vào order_items ======
        if (!Schema::hasColumn('order_items', 'spicy_level_override')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('spicy_level_override')->nullable()
                    ->after('note')
                    ->comment('0-7: cấp độ cay KH chọn (NULL = dùng menu_items.spicy_level)');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'spicy_level_override')) {
                $table->dropColumn('spicy_level_override');
            }
        });
        Schema::dropIfExists('cart_item_toppings');
        Schema::dropIfExists('order_item_toppings');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
