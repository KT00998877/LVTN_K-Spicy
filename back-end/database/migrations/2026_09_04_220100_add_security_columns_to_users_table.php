<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thêm các cột phục vụ bảo mật nâng cao cho bảng users.
     *  - failed_login_count: đếm số lần đăng nhập sai liên tiếp
     *  - last_failed_login_at: thời điểm sai gần nhất (dùng để reset counter)
     *  - locked_until: thời điểm mở khóa (null = không khóa)
     *  - password_changed_at: thời điểm đổi mật khẩu gần nhất
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_count')->default(0)->after('locked_at');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_count');
            $table->timestamp('locked_until')->nullable()->after('last_failed_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('locked_until');

            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['locked_until']);
            $table->dropColumn([
                'failed_login_count',
                'last_failed_login_at',
                'locked_until',
                'password_changed_at',
            ]);
        });
    }
};
