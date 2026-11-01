<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('stock_import_id')->constrained('stock_imports');
            $table->foreignId('supplier_return_id')->nullable()->unique()->constrained('supplier_returns');
            $table->uuid('request_key')->unique();
            $table->string('status', 20);
            $table->text('reason');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('requested_at');
            $table->foreignId('supplier_responded_by')->nullable()->constrained('users');
            $table->timestamp('supplier_responded_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users');
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('supplier_return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_request_id')->constrained('supplier_return_requests')->cascadeOnDelete();
            $table->foreignId('stock_import_item_id')->constrained('stock_import_items');
            $table->unsignedBigInteger('quantity');
            $table->unique(['supplier_return_request_id', 'stock_import_item_id'], 'return_request_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_request_items');
        Schema::dropIfExists('supplier_return_requests');
    }
};
