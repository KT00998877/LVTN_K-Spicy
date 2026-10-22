<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung hệ số hao hụt + đơn vị override cho công thức.
 *
 *  - waste_factor (decimal 5,3, default 1.000): hệ số nhân lượng nguyên liệu khi trừ kho.
 *      VD: 1.050 = +5% hao hụt khi chiên/rang; 1.000 = không hao hụt.
 *  - unit_override (string 20, nullable): nếu đơn vị trong công thức khác đơn vị của ingredient
 *      (vd recipe ghi "gói" nhưng ingredient đang ở "kg"). Phase này chưa xử lý chuyển đổi đơn vị
 *      tự động — chỉ lưu để UI hiển thị.
 *
 * Vì khóa chính là cặp (menu_item_id, ingredient_id) unique, ta KHÔNG thêm UNIQUE ở đây.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_item_ingredient', function (Blueprint $table) {
            $table->decimal('waste_factor', 5, 3)->default(1.000)
                ->after('quantity_required')
                ->comment('Hệ số hao hụt: 1.000 = không hao hụt, 1.050 = +5%');
            $table->string('unit_override', 20)->nullable()
                ->after('waste_factor')
                ->comment('NULL = dùng đơn vị gốc của nguyên liệu');
        });
    }

    public function down(): void
    {
        Schema::table('menu_item_ingredient', function (Blueprint $table) {
            $table->dropColumn(['waste_factor', 'unit_override']);
        });
    }
};
