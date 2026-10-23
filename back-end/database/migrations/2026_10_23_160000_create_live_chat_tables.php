<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tư vấn trực tiếp (live chat) — hàng chờ khách + phiên + tin nhắn + quick reply.
 *
 * Tách hoàn toàn khỏi `chat_sessions` của chatbot: hai luồng chạy song song,
 * dùng chung tệp khách hàng nhưng không dùng chung bảng.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('live_chat_sessions')) {
            Schema::create('live_chat_sessions', function (Blueprint $table) {
                $table->id();
                // Khách đã đăng nhập sở hữu phiên; cột khách để trống cho khách vãng lai.
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('guest_token', 64)->nullable();
                $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('customer_name', 120);
                $table->string('customer_phone', 30)->nullable();
                $table->string('status', 20)->default('waiting');
                // Chủ đề khách nêu khi mở phiên.
                $table->string('subject', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['assigned_staff_id', 'status']);
                $table->index('guest_token');
            });
        }

        if (!Schema::hasTable('live_chat_messages')) {
            Schema::create('live_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('live_chat_sessions')->cascadeOnDelete();
                // customer · staff · system
                $table->string('sender_type', 20);
                $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('content');
                // Tin nhắn nhân viên bấm từ gợi ý nhanh.
                $table->boolean('from_suggestion')->default(false);
                $table->timestamps();

                $table->index(['session_id', 'id']);
            });
        }

        if (!Schema::hasTable('live_chat_quick_replies')) {
            Schema::create('live_chat_quick_replies', function (Blueprint $table) {
                $table->id();
                $table->text('content');
                // Nhóm gợi ý để nhân viên lọc nhanh.
                $table->string('category', 60)->default('chung');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_quick_replies');
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chat_sessions');
    }
};