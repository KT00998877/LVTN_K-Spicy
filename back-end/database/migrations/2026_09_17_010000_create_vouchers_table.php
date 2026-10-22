<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Hệ thống Voucher khuyến mãi.
 *
 * Bảng mới:
 *  - vouchers         : danh sách mã giảm giá (MANAGER CRUD)
 *  - voucher_usages   : lịch sử mỗi khách dùng mã nào, cho đơn nào (đếm usage_limit_per_user)
 *
 * Order & Invoice KHÔNG thêm cột mới — voucher được tính trong `orders.discount_amount`
 * (số tiền giảm) và log trong order_events để tra cứu. Lý do:
 *  - Khớp với logic hiện có (voucher giảm lồng vào discount_amount, không tách cột).
 *  - Tránh thay đổi schema hóa đơn / payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            // Mã voucher (viết HOA, không dấu, không khoảng trắng) — dùng để khách nhập.
            $table->string('code', 50)->unique();

            // Tên hiển thị + mô tả ngắn cho manager / khách.
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // Loại giảm giá: PERCENT (% đơn hàng) hoặc FIXED (số tiền cố định).
            $table->enum('type', ['PERCENT', 'FIXED'])->default('PERCENT');

            // Giá trị giảm:
            //  - PERCENT: 1..100 (%)
            //  - FIXED:   số tiền VND (minor units → DECIMAL(14,2) để đồng bộ với orders)
            $table->decimal('value', 14, 2);

            // Giảm tối đa (chỉ áp dụng cho PERCENT, trần tiền giảm).
            $table->decimal('max_discount_amount', 14, 2)->nullable();

            // Đơn hàng tối thiểu để áp dụng (phụ phí ship không tính).
            $table->decimal('min_order_subtotal', 14, 2)->default(0);

            // Tổng số lượt dùng tối đa (NULL = không giới hạn).
            $table->unsignedInteger('usage_limit')->nullable();
            // Số lượt đã dùng (denormalized để check nhanh).
            $table->unsignedInteger('used_count')->default(0);
            // Mỗi user được dùng tối đa bao nhiêu lần (NULL = không giới hạn).
            $table->unsignedInteger('usage_limit_per_user')->nullable();

            // Thời gian hiệu lực. NULL = không giới hạn đầu/cuối.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Trạng thái kích hoạt (manager bật/tắt thủ công).
            $table->boolean('is_active')->default(true);

            // Người tạo (audit).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Số tiền giảm thực tế áp dụng cho đơn này (VND).
            $table->decimal('discount_amount', 14, 2);

            $table->timestamp('created_at')->useCurrent();

            // Mỗi đơn chỉ áp 1 voucher → unique.
            $table->unique('order_id');
            $table->index(['voucher_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usages');
        Schema::dropIfExists('vouchers');
    }
};