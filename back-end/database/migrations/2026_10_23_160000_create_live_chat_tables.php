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
        if (Schema::hasTable('live_chat_sessions')) {
            return;
        }

        Schema::create('live_chat_sessions', function (Blueprint $table) {
            $table->id();
            // Chỉ khách đã đăng nhập mới được tư vấn trực tiếp.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Nhân viên đã nhận phiên (null = đang chờ trong hàng chờ).
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 160)->nullable();
            // waiting: chờ nhân viên nhận · accepted: đang tư vấn · closed: kết thúc
            $table->string('status', 20)->default('waiting');
            $table->text('topic')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_staff_id', 'status']);
        });

        Schema::create('live_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('live_chat_sessions')->cascadeOnDelete();
            // customer · staff · system
            $table->string('sender_type', 20);
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->timestamp('created_at')->nullable();

            $table->index(['session_id', 'id']);
        });

        Schema::create('live_chat_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('content');
            // Nhóm gợi ý để nhân viên lọc nhanh (ví dụ "Hỏi về món", "Hỏi về đơn").
            $table->string('category', 60)->default('general');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_quick_replies');
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chat_sessions');
    }
};
