<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->enum('status', [
                'UNPAID',
                'PENDING',
                'PAID',
                'FAILED',
                'REFUNDED',
            ])->default('UNPAID');
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'issued_at']);
            $table->index(['customer_id', 'issued_at']);
            $table->index('order_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->enum('method', ['CASH', 'BANK_TRANSFER', 'QR', 'ONLINE_GATEWAY', 'OTHER']);
            $table->decimal('amount', 14, 2);
            $table->string('transaction_ref', 100)->nullable();
            $table->enum('status', ['PENDING', 'SUCCESS', 'FAILED', 'REFUNDED'])->default('PENDING');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->useCurrent();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
            $table->index(['method', 'status']);
            $table->index('transaction_ref');
        });

        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->string('field_name', 60);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->timestamp('adjusted_at')->useCurrent();
            $table->index(['invoice_id', 'adjusted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};