<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 — Blog enhancements.
 *
 * Bổ sung:
 *  - reading_time: số phút đọc ước lượng (tính từ content) — hiển thị ở public detail.
 *  - gallery: mảng đường dẫn ảnh bổ sung (ngoài cover_image) cho lightbox/carousel.
 *    Admin có thể upload ảnh vào content (qua TinyMCE) — ảnh sẽ tự lưu path vào content HTML,
 *    nhưng gallery giúp frontend có 1 danh sách "ảnh chính" để render gallery riêng nếu muốn.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->unsignedSmallInteger('reading_time')->nullable()->after('content');
            $table->json('gallery')->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn(['reading_time', 'gallery']);
        });
    }
};