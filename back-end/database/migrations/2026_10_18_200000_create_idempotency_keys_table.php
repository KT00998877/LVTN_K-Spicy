<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P2 (v1.2) — Bảng idempotency_keys cho write operations.
 *
 * - Laravel sinh UUID v4 cho mỗi write intent (add_to_cart) sau khi FastAPI
 *   trả về `next_action = ask_confirm`.
 * - Trước khi thực thi tool, Laravel check `IdempotencyKey::exists($key)`.
 *   Nếu tồn tại → trả cached response (không gọi lại tool).
 * - Bảng có TTL qua `expires_at`. Cron job dọn key hết hạn (P8).
 *
 * KHÔNG sửa schema `chat_sessions` / `chat_messages` — dùng cột `metadata`
 * JSON có sẵn để lưu Context State.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->char('key', 36)->primary()
                ->comment('UUID v4 do Laravel sinh, share với FastAPI qua ChatRequest.');

            $table->foreignId('session_id')
                ->nullable()
                ->constrained('chat_sessions')
                ->cascadeOnDelete();

            $table->string('tool', 64)
                ->comment('Tool name sẽ thực thi, vd: cart_add_item.');

            $table->string('args_hash', 64)
                ->comment('SHA256 hex của JSON args, để phát hiện cùng key nhưng args khác.');

            $table->json('args')
                ->nullable()
                ->comment('Args đã hash, lưu để debug/audit (optional).');

            $table->json('response')
                ->nullable()
                ->comment('Cached response từ lần thực thi đầu tiên.');

            $table->timestamp('expires_at')
                ->index()
                ->comment('Cron dọn key hết hạn (mặc định +30 phút).');

            $table->timestamps();

            $table->index(['session_id', 'tool'], 'idx_idem_session_tool');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
