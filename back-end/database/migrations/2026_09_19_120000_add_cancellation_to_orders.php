<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm cột huỷ đơn (customerCancel) cho bảng orders:
 *  - cancelled_by_user_id: ai là người huỷ (customer hoặc staff)
 *  - cancelled_at: thời điểm huỷ
 *  - cancellation_reason: lý do huỷ
 *
 * order_items đã có sẵn cancelled_by + cancellation_reason (xem
 * 2026_08_28_010500_create_orders_tables.php).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('completed_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_user_id');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by_user_id', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
