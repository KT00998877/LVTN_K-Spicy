<?php
/**
 * Migration: thêm các trường cho luồng COD + 3 bước shipper.
 *
 * 1. orders.payment_method     — 'PREPAID' | 'COD' (mặc định COD)
 * 2. orders.picked_up_at       — timestamp shipper đã lấy món
 * 3. orders.arrived_at        — timestamp shipper đã tới điểm giao
 * 4. Sửa orders.status enum thành 8 trạng thái (thêm READY_NOTIFY_SHIPPER)
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Thêm 3 cột mới vào bảng orders
        Schema::table('orders', function (Blueprint $table) {
            // Thanh toán: COD (mặc định) hoặc PREPAID (khách thanh toán online trước)
            $table->enum('payment_method', ['PREPAID', 'COD'])->default('COD')->after('total_amount');

            // Bước 1: Shipper đã lấy món từ bếp
            $table->timestamp('picked_up_at')->nullable()->after('handed_over_at');

            // Bước 2: Shipper đã tới nơi giao
            $table->timestamp('arrived_at')->nullable()->after('picked_up_at');
        });

        // Đổi enum status thành 8 giá trị (thêm READY_NOTIFY_SHIPPER)
        // Đọc enum hiện tại để đảm bảo tương thích
        $this->migrateEnum('orders', 'status', [
            'PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER',
            'DELIVERING', 'COMPLETED', 'CANCELLED',
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'picked_up_at', 'arrived_at']);
        });

        // Khôi phục lại enum cũ (7 giá trị, không có READY_NOTIFY_SHIPPER)
        $this->migrateEnum('orders', 'status', [
            'PENDING', 'CONFIRMED', 'PREPARING', 'READY',
            'DELIVERING', 'COMPLETED', 'CANCELLED',
        ]);
    }

    /**
     * Hàm helper chuyển đổi enum column (MySQL/MariaDB).
     * Lấy type hiện tại của column, thay thế enum definition.
     */
    private function migrateEnum(string $table, string $column, array $values): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->enum($column, $values)->default('PENDING')->change());
    }
};
