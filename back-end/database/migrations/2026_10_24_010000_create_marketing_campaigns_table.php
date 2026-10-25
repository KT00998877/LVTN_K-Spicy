<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Chiến dịch ưu đãi gửi tới khách hàng (Phase 1).
 *
 * Bảng mới:
 *  - marketing_campaigns: chiến dịch ưu đãi có "khung giờ vàng" (send_at +
 *    window_start/window_end) để hệ thống tự gửi thông báo cho khách thân thiết.
 *
 * Không đụng bảng `campaigns` (đang chạy cho trang public) và `vouchers`
 * (đã có đủ usage_limit / max_discount_amount). Ở đây chỉ tham chiếu
 * `voucher_code` trỏ sang vouchers.code để khách copy mã.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();

            // Nội dung hiển thị trong chuông thông báo của khách.
            $table->string('name', 150)->comment('Tên chiến dịch, ví dụ "Flash deal trưa"');
            $table->text('message')->comment('Nội dung chuông: "Mì cay giảm 20% từ 11h-13h30!"');

            // Mã voucher khách copy (liên kết tới vouchers.code).
            $table->string('voucher_code', 50)->comment('Mã voucher áp dụng, trỏ sang vouchers.code');
            $table->foreign('voucher_code')->references('code')->on('vouchers')->cascadeOnUpdate();

            // Banner: có ảnh thì dùng ảnh, không có thì fallback gradient theo accent_color.
            $table->string('banner_image_url', 500)->nullable();
            $table->string('accent_color', 20)->default('#E83A14')->comment('Màu nhấn khi không có ảnh banner');
            $table->string('cta_text', 60)->default('Nhận ưu đãi')->comment('Nhãn nút kêu gọi hành động');
            $table->string('landing_url', 500)->nullable()->comment('Link điều hướng khi khách bấm banner');

            // Đối tượng nhận: null/rỗng = tất cả khách, có giá trị = giới hạn theo hạng.
            $table->json('target_tier_ids')->nullable()->comment('Danh sách loyalty_tier_ids được nhận, null = tất cả');

            // Khung giờ vàng: send_at là mốc mong muốn, window là cửa sổ cho phép gửi.
            $table->timestamp('send_at')->comment('Thời điểm mong muốn gửi');
            $table->time('window_start')->default('11:00')->comment('Bắt đầu khung giờ vàng, vd 11:00');
            $table->time('window_end')->default('13:30')->comment('Kết thúc khung giờ vàng, vd 13:30');

            $table->enum('status', ['SCHEDULED', 'SENT', 'SKIPPED', 'CANCELLED'])->default('SCHEDULED');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0)->comment('Số khách đã nhận ưu đãi');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index('voucher_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
