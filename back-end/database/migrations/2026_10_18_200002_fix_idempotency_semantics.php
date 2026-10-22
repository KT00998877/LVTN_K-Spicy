<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P2 v1.2.3 — Refactor idempotency cho ĐÚNG semantics.
 *
 * v1.2.2 sai: thêm unique (session_id, tool, args_hash) → chặn 2 operations hợp lệ
 *   có cùng args nhưng khác idempotency_key. Ví dụ: user A thêm "mì cay bò cấp 3"
 *   với key=K1, user A click "thêm lại" với key=K2 → cả 2 phải add vào cart,
 *   nhưng unique constraint ép request 2 trả stale_claim.
 *
 * v1.2.3 đúng: bỏ unique (session, tool, args_hash). CHỈ idempotency_key là unique
 *   (đã là primary key). CAS state_version chỉ áp dụng trên CÙNG key.
 *
 * - Cùng idempotency_key, nhiều request: CAS đảm bảo chỉ 1 commit, các request khác
 *   nhận cached response.
 * - Khác idempotency_key, cùng args: 2 operations hợp lệ → 2 commit OK (user mua 2 lần).
 * - Race trên cùng key: state_version CAS chặn double commit.
 */
return new class extends Migration {
    public function up(): void
    {
        // Xóa unique (session_id, tool, args_hash) nếu có.
        // Migration an toàn để chạy nhiều lần.
        try {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->dropUnique('uniq_idem_session_tool_args');
            });
        } catch (\Throwable $e) {
            // Index không tồn tại — bỏ qua (migration chạy lần đầu sau v1.2.2).
        }

        // Thêm index (session_id, tool, expires_at) để query lookup cache.
        // Không unique — chỉ để tăng tốc SELECT.
        try {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->index(['session_id', 'tool', 'expires_at'], 'idx_idem_session_tool_expires');
            });
        } catch (\Throwable $e) {
            // Index đã tồn tại — bỏ qua.
        }
    }

    public function down(): void
    {
        // Không restore unique constraint vì nó sai semantics.
        // Chỉ drop index nếu có.
        try {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->dropIndex('idx_idem_session_tool_expires');
            });
        } catch (\Throwable) {
            // ignore
        }
    }
};

