<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P2 v1.2.2 — Bổ sung CAS (compare-and-swap) cho idempotency.
 *
 * Thay đổi so với v1.2:
 *  - Thêm cột `request_id` (UUID) để log 2 request cùng key (debug race).
 *  - Thêm cột `state_version` (int) để CAS: chỉ UPDATE khi version match.
 *  - Thêm cột `committed_at` (timestamp nullable) đánh dấu key đã commit.
 *  - Thêm unique index `(session_id, tool, args_hash)` để chống cùng args bị commit 2 lần.
 *
 * Cơ chế ATOMIC IDEMPOTENCY cho `add_to_cart`:
 *  1. Phase 1: SELECT * FROM idempotency_keys WHERE key = ? AND expires_at > NOW()
 *     - Nếu row.response IS NOT NULL (cached) → trả cached.
 *     - Nếu row.committed_at IS NOT NULL (đã commit) → trả cached.
 *     - Nếu không có row hoặc expired → tạo row mới với state_version = X.
 *  2. Sau khi gọi FastAPI: UPDATE idempotency_keys SET response = ?, state_version = X+1
 *     WHERE key = ? AND state_version = X  (CAS — nếu 0 rows affected → có request khác đã commit).
 *  3. Tool `cart_add_item` cũng check: SELECT FOR UPDATE trên cart_items với
 *     (session_id, menu_item_id, idempotency_key) → chỉ insert nếu chưa có.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            // Cột cho CAS.
            if (!Schema::hasColumn('idempotency_keys', 'request_id')) {
                $table->string('request_id', 64)->nullable()->after('key')
                    ->comment('UUID của request tạo key này. Dùng để log race.');
            }
            if (!Schema::hasColumn('idempotency_keys', 'state_version')) {
                $table->unsignedInteger('state_version')->default(0)->after('request_id')
                    ->comment('Version cho CAS. UPDATE chỉ thành công nếu version match.');
            }
            if (!Schema::hasColumn('idempotency_keys', 'committed_at')) {
                $table->timestamp('committed_at')->nullable()->after('expires_at')
                    ->comment('Timestamp khi tool đã thực thi xong. NULL = pending.');
            }
        });

        // Unique constraint: cùng (session, tool, args_hash) chỉ có 1 row.
        // Dù unique index có thể thêm trực tiếp vì schema đã có các cột.
        try {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->unique(
                    ['session_id', 'tool', 'args_hash'],
                    'uniq_idem_session_tool_args',
                );
            });
        } catch (\Throwable $e) {
            // Index đã tồn tại — bỏ qua.
        }
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('uniq_idem_session_tool_args');
            $table->dropColumn(['request_id', 'state_version', 'committed_at']);
        });
    }
};
