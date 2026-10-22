<?php
/**
 * Migration: Bổ sung luồng xác nhận đã nhận hàng từ khách + hệ thống điểm thưởng.
 *
 * Thay đổi:
 *  1. orders.status                          — bổ sung DELIVERED (shipper đã giao, chờ khách xác nhận)
 *  2. orders.delivered_at giữ nguyên (timestamp shipper bàn giao thực tế)
 *  3. orders.customer_received_at            — thời điểm khách xác nhận đã nhận
 *  4. orders.customer_received_by            — user khách xác nhận (FK -> users)
 *  5. orders.points_awarded                  — số điểm đã cộng cho khách khi nhận hàng
 *  6. users.loyalty_points                   — tổng điểm hiện tại của khách
 *  7. loyalty_point_ledger                   — lịch sử cộng/trừ điểm (mỗi dòng = 1 giao dịch)
 *
 * Luồng mới của shipper → khách:
 *   DELIVERING
 *      └─ shipper xác nhận "Đã giao xong" → status = DELIVERED (delivered_at = now)
 *                                            items[] chuyển SERVED (đã giao cho khách)
 *      └─ khách bấm "Tôi đã nhận hàng" → status = COMPLETED
 *                                         completed_at = now
 *                                         customer_received_at/by = now/user
 *                                         cộng điểm vào users.loyalty_points
 *                                         ghi order_event CUSTOMER_RECEIVED + POINTS_AWARDED
 *
 * Nếu khách không bấm: đơn vẫn ở DELIVERED; cửa hàng không cấp điểm (theo yêu cầu).
 * Sau N ngày có thể auto-complete (không thuộc phạm vi đồ án — chừa mở cho tương lai).
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Mở rộng enum status với DELIVERED
        Schema::table('orders', fn (Blueprint $table) => $table->enum('status', [
            'PENDING','CONFIRMED','PREPARING','READY','READY_NOTIFY_SHIPPER',
            'DELIVERING','DELIVERED','COMPLETED','SERVED','CANCELLED'
        ])->default('PENDING')->change());

        // 2-5. Bổ sung các cột cho luồng khách xác nhận + điểm thưởng
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('customer_received_at')->nullable()->after('delivered_at');
            $table->foreignId('customer_received_by')
                  ->nullable()
                  ->after('customer_received_at')
                  ->constrained('users')
                  ->nullOnDelete();
            $table->unsignedInteger('points_awarded')->default(0)->after('customer_received_by');
        });

        // 6. Thêm cột điểm hiện tại cho user (chỉ áp dụng cho CUSTOMER)
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0)->after('phone');
        });

        // 7. Sổ cái điểm thưởng: mỗi dòng = 1 phát sinh (cộng điểm khi nhận hàng / điều chỉnh)
        Schema::create('loyalty_point_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->enum('type', ['EARN', 'REDEEM', 'ADJUST'])->default('EARN');
            $table->integer('points'); // dương = cộng, âm = trừ
            $table->unsignedInteger('balance_after');
            $table->string('reason', 200)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_ledger');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_received_by');
            $table->dropColumn(['customer_received_at', 'points_awarded']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('loyalty_points');
        });

        // Trả enum về 8 giá trị (không còn DELIVERED)
        Schema::table('orders', fn (Blueprint $table) => $table->enum('status', [
            'PENDING','CONFIRMED','PREPARING','READY','READY_NOTIFY_SHIPPER',
            'DELIVERING','COMPLETED','SERVED','CANCELLED'
        ])->default('PENDING')->change());
    }
};
