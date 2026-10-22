<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('invoices')->where('code', 'like', 'INV-DEMO-%')->orderBy('id')->get() as $invoice) {
                $code = 'HD-' . Carbon::parse($invoice->issued_at)->format('Ymd') . '-'
                    . str_pad((string) $invoice->order_id, 6, '0', STR_PAD_LEFT);
                if (DB::table('invoices')->where('code', $code)->where('id', '!=', $invoice->id)->exists()) {
                    throw new RuntimeException('Mã hóa đơn bị trùng: ' . $code);
                }
                DB::table('invoices')->where('id', $invoice->id)->update(['code' => $code]);
            }
        });
    }

    public function down(): void
    {
        // Giữ nguyên mã đã xuất cho khách khi rollback.
    }
};
