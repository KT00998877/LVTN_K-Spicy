<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6.1 — Marketing Studio (AI content generation).
     *
     * Bốn bảng:
     *  - marketing_assets       : Mỗi asset là 1 chiến dịch nội dung (FB post, blog, IG caption, Zalo).
     *  - marketing_asset_versions: Lịch sử phiên bản (drafts) sinh ra từ AI. Mỗi asset có 1..n version,
     *                             manager duyệt version nào → đó là version được publish.
     *  - marketing_uploads      : File media đính kèm cho asset (ảnh, video) — không nhúng binary vào asset.
     *  - marketing_publishes    : Log mỗi lần publish ra kênh ngoài (Blog trước, FB/Zalo sau Phase 6.3+).
     */
    public function up(): void
    {
        Schema::create('marketing_assets', function (Blueprint $table) {
            $table->id();
            // Manager tạo/sở hữu asset
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();

            // Loại asset & kênh đích
            $table->enum('channel', ['FACEBOOK', 'INSTAGRAM', 'TIKTOK', 'ZALO', 'BLOG', 'EMAIL'])->index();
            $table->enum('content_type', ['POST', 'STORY', 'REEL_SCRIPT', 'BLOG_ARTICLE', 'EMAIL_BLAST'])->index();

            // Context đầu vào khi sinh content
            $table->string('subject', 200);                       // VD: "Mì cay bò Mỹ", "Khai trương CN2"
            $table->enum('tone', ['FUN', 'WARM', 'PREMIUM', 'CASUAL', 'PUNCHY'])->default('FUN');
            $table->json('context_payload')->nullable();         // Snapshot các tham chiếu (menu_item_id, promotion_id,...)

            // Trạng thái pipeline
            $table->enum('status', ['DRAFT', 'IN_REVIEW', 'APPROVED', 'SCHEDULED', 'PUBLISHED', 'REJECTED', 'ARCHIVED'])
                  ->default('DRAFT')
                  ->index();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index(['status', 'channel']);
            $table->index('created_by');
        });

        Schema::create('marketing_asset_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('marketing_assets')->cascadeOnDelete();

            // Phiên bản số (1 = draft đầu tiên)
            $table->unsignedTinyInteger('version_number');

            // Provider AI sinh ra version này
            $table->enum('provider', ['MOCK', 'GEMINI', 'OPENAI', 'HUMAN'])->default('MOCK')->index();
            $table->string('provider_model', 80)->nullable();    // e.g. "gemini-2.0-flash"

            // Kết quả content sinh ra
            $table->string('headline', 255)->nullable();
            $table->text('caption')->nullable();                 // Caption ngắn cho post
            $table->longText('body')->nullable();                // Body dài cho blog/email
            $table->json('hashtags')->nullable();
            $table->json('meta');                                // token, latency, ...
            $table->text('prompt_used')->nullable();             // Lưu lại prompt để audit

            $table->boolean('is_selected')->default(false);      // Version nào được manager duyệt
            $table->timestamps();
            $table->unique(['asset_id', 'version_number']);
        });

        Schema::create('marketing_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('marketing_assets')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            // File metadata
            $table->string('disk', 30)->default('public');        // Laravel filesystem disk
            $table->string('path', 255);                          // VD: marketing/2026/10/asset-1-hero.jpg
            $table->string('original_name', 200)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('width')->nullable();   // Cho ảnh/video
            $table->unsignedSmallInteger('height')->nullable();

            // Vai trò (ảnh chính, ảnh phụ, video ngắn,...)
            $table->enum('role', ['HERO', 'GALLERY', 'THUMBNAIL', 'VIDEO', 'DOC'])->default('GALLERY');
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
            $table->index(['asset_id', 'role']);
        });

        Schema::create('marketing_publishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('marketing_assets')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('marketing_asset_versions')->cascadeOnDelete();

            // Kênh publish thực tế (có thể khác asset.channel nếu cross-post)
            $table->enum('channel', ['FACEBOOK', 'INSTAGRAM', 'TIKTOK', 'ZALO', 'BLOG', 'EMAIL'])->index();

            // Kết quả publish
            $table->enum('status', ['PENDING', 'SUCCESS', 'FAILED'])->index();
            $table->json('response_payload')->nullable();        // Trả về từ API external / Blog local
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_publishes');
        Schema::dropIfExists('marketing_uploads');
        Schema::dropIfExists('marketing_asset_versions');
        Schema::dropIfExists('marketing_assets');
    }
};