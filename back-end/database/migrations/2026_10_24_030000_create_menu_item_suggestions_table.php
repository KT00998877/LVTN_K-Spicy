<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Combo nước uống do quản lý cấu hình (Phase 3).
 *
 * Bảng mới:
 *  - menu_item_suggestions: liên kết 1 món chính với 1 món gợi ý (thường là
 *    đồ uống). Quản lý tự tạo — không hard code trong code.
 *
 *  - combo_price: giá khi mua cả món chính + món gợi ý. NULL = chỉ gợi ý,
 *    không có ưu đãi. Số tiền này dùng để tính tiết kiệm khi thanh toán.
 *  - is_active: tắt/tạm ẩn gợi ý mà không cần xoá.
 *  - sort_order: thứ tự hiển thị trong modal món.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_suggestions', function (Blueprint $table) {
            $table->id();

            // Món chính — món khách đang xem.
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();

            // Món gợi ý (đồ uống/topping/món phụ).
            $table->foreignId('suggested_item_id')->constrained('menu_items')->cascadeOnDelete();

            // Nội dung hiển thị. title = null → sinh tự động "Combo {món chính} + {món gợi ý}".
            $table->string('title', 150)->nullable();
            $table->string('message', 255)->nullable();

            // Giá combo (món chính + món gợi ý). NULL = không ưu đãi.
            $table->decimal('combo_price', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // 1 món chính chỉ gợi ý 1 món gợi ý (tránh trùng).
            $table->unique(['menu_item_id', 'suggested_item_id']);
            $table->index(['menu_item_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_suggestions');
    }
};
