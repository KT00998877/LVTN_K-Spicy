<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Order ↔ Inventory linkage.
 *
 * Thêm cờ vào bảng `orders` để:
 *  - inventory_processed_at: lần đầu trừ kho thành công (idempotency cho SALE).
 *  - inventory_processed_user_id: nhân viên / actor đã trigger trừ kho.
 *  - inventory_processed_event: mã sự kiện (vd: "CONFIRMED") ghi vào order_events.
 *  - inventory_reversed_at: thời điểm hoàn kho (khi đơn CANCELLED trước khi bếp nấu).
 *  - inventory_reversed_user_id: actor đã chạy hoàn kho.
 *  - inventory_reversal_reason: lý do hoàn kho.
 *
 * Quy tắc idempotency:
 *  - Chỉ trừ kho khi inventory_processed_at IS NULL.
 *  - Sau khi trừ, set inventory_processed_at = NOW().
 *  - Lần gọi lại (retry/refresh) thấy inventory_processed_at != NULL → bỏ qua.
 *
 * Quy tắc hoàn kho (an toàn):
 *  - Chỉ hoàn khi order CANCELLED trước khi có món PREPARING/READY/SERVED.
 *  - Sau khi hoàn, set inventory_reversed_at = NOW(); KHÔNG reset
 *    inventory_processed_at để giữ audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('inventory_processed_at')->nullable()->after('completed_at');
            $table->unsignedBigInteger('inventory_processed_user_id')->nullable()->after('inventory_processed_at');
            $table->string('inventory_processed_event', 30)->nullable()->after('inventory_processed_user_id');

            $table->timestamp('inventory_reversed_at')->nullable()->after('inventory_processed_event');
            $table->unsignedBigInteger('inventory_reversed_user_id')->nullable()->after('inventory_reversed_at');
            $table->string('inventory_reversal_reason', 500)->nullable()->after('inventory_reversed_user_id');

            $table->index('inventory_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['inventory_processed_at']);
            $table->dropColumn([
                'inventory_processed_at',
                'inventory_processed_user_id',
                'inventory_processed_event',
                'inventory_reversed_at',
                'inventory_reversed_user_id',
                'inventory_reversal_reason',
            ]);
        });
    }
};
