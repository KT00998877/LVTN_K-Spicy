<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // VNPAY uses 0 when no provider transaction exists (e.g. customer cancels).
        DB::table('payments')->where('provider', 'vnpay')->where('status', 'FAILED')
            ->whereIn('provider_transaction_id', ['', '0'])->update(['provider_transaction_id' => null]);
        $duplicates = DB::table('payments')->whereNotNull('provider')->whereNotNull('provider_transaction_id')
            ->select('provider', 'provider_transaction_id')
            ->groupBy('provider', 'provider_transaction_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Duplicate gateway transactions require reconciliation before adding the unique index.');
        }
        Schema::table('payments', fn (Blueprint $table) =>
            $table->unique(['provider', 'provider_transaction_id'], 'payments_provider_transaction_unique'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_provider_transaction_unique'));
    }
};
