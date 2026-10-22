<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Hệ thống Review món ăn.
 *
 * Bảng mới:
 *  - reviews        : đánh giá của khách cho 1 món (rating + comment + photos[])
 *  - review_photos  : ảnh đính kèm (1 review có thể có 0..5 ảnh)
 *
 * Quy tắc BR-REVIEW-* (chi tiết trong ReviewService):
 *  - BR-REVIEW-01: Chỉ khách đã mua món (order COMPLETED + có order_item.menu_item_id) mới review được.
 *  - BR-REVIEW-02: 1 user + 1 menu_item chỉ review được 1 lần (unique). Có thể sửa sau.
 *  - BR-REVIEW-03: Rating ∈ [1, 5]. Comment tối đa 1000 ký tự.
 *  - BR-REVIEW-04: Ảnh JPG/PNG/WEBP, ≤ 4MB / ảnh, tối đa 5 ảnh / review.
 *  - BR-REVIEW-05: Review có `is_approved` (manager duyệt), chỉ approved hiển thị public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->unsignedTinyInteger('rating');          // 1..5
            $table->text('comment')->nullable();            // tối đa 1000 ký tự
            $table->boolean('is_approved')->default(true);  // manager duyệt
            $table->boolean('is_hidden')->default(false);   // ẩn do vi phạm
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // BR-REVIEW-02: 1 user + 1 menu_item → 1 review.
            $table->unique(['user_id', 'menu_item_id']);
            $table->index(['menu_item_id', 'is_approved', 'is_hidden', 'created_at']);
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();

            $table->string('path', 255);             // đường dẫn tương đối trong disk public
            $table->string('url', 255);              // URL public truy cập được
            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('size_kb')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->index(['review_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
        Schema::dropIfExists('reviews');
    }
};