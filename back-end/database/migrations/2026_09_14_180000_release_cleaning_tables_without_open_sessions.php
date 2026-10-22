<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('tables')->where('status', 'CLEANING')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('table_sessions')
                ->whereColumn('table_sessions.table_id', 'tables.id')->where('status', 'OPEN'))
            ->update(['status' => 'AVAILABLE', 'updated_at' => now()]);
    }
    public function down(): void
    {
        // Không khôi phục trạng thái bàn cũ vì có thể đã mở phiên mới.
    }
};
