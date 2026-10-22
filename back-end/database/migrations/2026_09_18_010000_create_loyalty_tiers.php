<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Hạng thành viên (Loyalty Tiers)
 *
 * Mục đích:
 *  - Phân hạng khách hàng dựa trên TỔNG CHI TIÊU tích lũy (lifetime spend) trên đơn PAID/COMPLETED.
 *  - Mỗi hạng có nhiều đặc quyền (benefits) — phương án 2 (rõ ràng, dễ mở rộng):
 *      + point_multiplier     : hệ số nhân điểm EARN (mặc định 1.0)
 *      + free_ship_threshold  : ngưỡng đơn (VND) để được free ship; null = không có
 *      + extra_discount_pct   : % giảm thêm trên subtotal (mặc định 0)
 *
 *  - Bảng `loyalty_tiers` định nghĩa các hạng (code, name, min_spent, color, icon, benefits).
 *  - Bảng `users` bổ sung: loyalty_tier_id (FK), total_spent (VND), tier_updated_at.
 *
 * Lưu ý:
 *  - Hạng "Mới" / "Đồng" có min_spent = 0 (mọi khách đều thuộc hạng Đồng khi mới đăng ký).
 *  - Khi chuyển hạng: cập nhật loyalty_tier_id + ghi ledger POINTS_AWARDED event riêng (xem LoyaltyTierService).
 *  - Không tự động hạ bậc khi spend giảm — giữ hạng cao nhất đã đạt (chiều đi lên 1 chiều).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('Mã nội bộ, vd: BRONZE, SILVER, GOLD, DIAMOND');
            $table->string('name', 64)->comment('Tên tiếng Việt hiển thị');
            $table->unsignedBigInteger('min_spent')->default(0)->comment('Ngưỡng tổng chi tiêu tối thiểu (VND)');

            // Visual tokens — phục vụ FE
            $table->string('color', 16)->default('#A80F17')->comment('Màu chủ đạo (hex/rgb)');
            $table->string('icon', 64)->nullable()->comment('Emoji / SVG key dùng cho badge');

            // Benefits (JSON) — đóng gói để dễ mở rộng mà không cần ALTER TABLE nhiều lần.
            $table->json('benefits')->comment('{
                "point_multiplier": 1.0,
                "free_ship_threshold": null,
                "extra_discount_pct": 0,
                "description": "Mô tả ngắn"
            }');

            $table->unsignedInteger('sort_order')->default(0)->comment('Thứ tự hiển thị, nhỏ trước');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('min_spent');
        });

        // Seed mặc định 4 hạng cho hệ thống.
        $now = now();
        DB::table('loyalty_tiers')->insert([
            [
                'code' => 'BRONZE',
                'name' => 'Đồng',
                'min_spent' => 0,
                'color' => '#B45309', // amber-700
                'icon' => '🥉',
                'benefits' => json_encode([
                    'point_multiplier' => 1.0,
                    'free_ship_threshold' => null,
                    'extra_discount_pct' => 0,
                    'description' => 'Tích điểm cơ bản 1đ/10.000đ. Ưu đãi sinh nhật hằng năm.',
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'SILVER',
                'name' => 'Bạc',
                'min_spent' => 2_000_000, // 2 triệu
                'color' => '#94A3B8', // slate-400
                'icon' => '🥈',
                'benefits' => json_encode([
                    'point_multiplier' => 1.2,
                    'free_ship_threshold' => 100_000, // free ship đơn từ 100k
                    'extra_discount_pct' => 3,
                    'description' => 'Tích điểm x1.2, miễn phí ship đơn từ 100.000đ, giảm thêm 3% vào dịp lễ.',
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'GOLD',
                'name' => 'Vàng',
                'min_spent' => 5_000_000, // 5 triệu
                'color' => '#D71920', // chili-red (thương hiệu)
                'icon' => '🥇',
                'benefits' => json_encode([
                    'point_multiplier' => 1.5,
                    'free_ship_threshold' => 50_000, // free ship đơn từ 50k
                    'extra_discount_pct' => 5,
                    'description' => 'Tích điểm x1.5, miễn phí ship đơn từ 50.000đ, giảm thêm 5% cuối tuần.',
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'DIAMOND',
                'name' => 'Kim Cương',
                'min_spent' => 15_000_000, // 15 triệu
                'color' => '#0EA5E9', // sky-500
                'icon' => '💎',
                'benefits' => json_encode([
                    'point_multiplier' => 2.0,
                    'free_ship_threshold' => 0, // free ship mọi đơn
                    'extra_discount_pct' => 10,
                    'description' => 'Tích điểm x2, miễn phí ship mọi đơn, giảm thêm 10% trọn đời.',
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('users', function (Blueprint $table) {
            // FK nullable — luôn có hạng (mặc định BRONZE) nhưng FK cho phép null khi record cũ chưa migrate.
            $table->foreignId('loyalty_tier_id')
                ->nullable()
                ->after('loyalty_points')
                ->constrained('loyalty_tiers')
                ->nullOnDelete();

            $table->unsignedBigInteger('total_spent')
                ->default(0)
                ->after('loyalty_tier_id')
                ->comment('Tổng chi tiêu tích lũy (VND) trên các đơn COMPLETED');

            $table->timestamp('tier_updated_at')
                ->nullable()
                ->after('total_spent');

            $table->index(['loyalty_tier_id', 'total_spent']);
        });

        // Backfill: mọi user hiện tại thuộc hạng Đồng (BRONZE) với total_spent = 0.
        // Sau này tier sẽ được đánh giá lại qua LoyaltyTierService::evaluateAndUpgrade()
        // mỗi lần khách xác nhận đơn (xem customerConfirmReceipt).
        $bronzeId = DB::table('loyalty_tiers')->where('code', 'BRONZE')->value('id');
        if ($bronzeId) {
            DB::table('users')->whereNull('loyalty_tier_id')->update([
                'loyalty_tier_id' => $bronzeId,
                'tier_updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['loyalty_tier_id']);
            $table->dropIndex(['loyalty_tier_id', 'total_spent']);
            $table->dropColumn(['loyalty_tier_id', 'total_spent', 'tier_updated_at']);
        });

        Schema::dropIfExists('loyalty_tiers');
    }
};
