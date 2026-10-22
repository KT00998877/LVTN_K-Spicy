<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconciliation only — chat_sessions.guest_token already exists in the live
     * database (varchar(64) nullable) from the original Phase 11 schema, which
     * this repository no longer carries. Adding it again would be a duplicate
     * column error; the existing column is already indexed and is what the
     * controller reads.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('chat_sessions', 'guest_token')) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->char('guest_token', 64)->nullable()->after('user_id');
                $table->index('guest_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_sessions', 'guest_token')) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->dropIndex(['guest_token']);
                $table->dropColumn('guest_token');
            });
        }
    }
};