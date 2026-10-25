<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Vị trí shipper + gán shipper tự động (Phase 2).
 *
 * Bảng mới:
 *  - shipper_locations: vị trí hiện tại của shipper, cập nhật định kỳ từ
 *    app. Dùng để tìm shipper gần nhà hàng nhất khi duyệt đơn ONLINE.
 *
 * Cột thêm trên `orders`:
 *  - shipper_accepted_at: thời điểm shipper xác nhận nhận đơn (chống 2 shipper
 *    cùng nhận 1 đơn). NULL = đơn đang chờ shipper nhận.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipper_locations', function (Blueprint $table) {
            $table->id();

            // 1 shipper = 1 dòng vị trí (upsert theo user_id).
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable()->comment('Độ chính xác GPS (mét)');

            // Shipper đang rảnh để nhận đơn mới.
            $table->boolean('is_available')->default(true);

            // Lần cập nhật vị trí gần nhất — dùng để lọc shipper "còn hoạt động".
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->index(['is_available', 'last_seen_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('shipper_accepted_at')->nullable()->after('shipper_id');
            $table->index(['shipper_id', 'shipper_accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shipper_id', 'shipper_accepted_at']);
            $table->dropColumn('shipper_accepted_at');
        });

        Schema::dropIfExists('shipper_locations');
    }
};
