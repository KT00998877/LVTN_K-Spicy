<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_imports', function (Blueprint $table) {
            $table->string('supplier_document_code', 100)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unique(['supplier_id', 'supplier_document_code'], 'stock_import_supplier_document_unique');
        });
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('reason_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', fn (Blueprint $table) => $table->dropColumn('reason_code'));
        Schema::table('stock_imports', function (Blueprint $table) {
            $table->dropUnique('stock_import_supplier_document_unique');
            $table->dropColumn(['supplier_document_code', 'received_at']);
        });
    }
};
