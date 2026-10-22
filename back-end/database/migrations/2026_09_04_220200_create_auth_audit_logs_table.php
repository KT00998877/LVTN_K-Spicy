<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng ghi log đăng nhập / đăng ký / đăng xuất cho mục đích audit & debug.
     */
    public function up(): void
    {
        Schema::create('auth_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);                // login_success | login_failed | logout | registered | ...
            $table->string('identifier', 191)->nullable(); // email hoặc số điện thoại đã thử
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();      // extra context (reason, device, ...)
            $table->boolean('success')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_audit_logs');
    }
};
