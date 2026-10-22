<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('table_sessions')->nullOnDelete();
            $table->enum('order_type', ['DINE_IN', 'TAKEAWAY', 'ONLINE'])->default('DINE_IN');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->enum('status', [
                'PENDING',
                'CONFIRMED',
                'PREPARING',
                'READY',
                'SERVED',
                'COMPLETED',
                'CANCELLED',
            ])->default('PENDING');
            $table->timestamp('placed_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'placed_at']);
            $table->index(['customer_id', 'placed_at']);
            $table->index('table_session_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->restrictOnDelete();
            $table->string('item_name', 150);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('subtotal', 14, 2);
            $table->text('note')->nullable();
            $table->enum('status', [
                'PENDING',
                'PREPARING',
                'READY',
                'SERVED',
                'CANCELLED',
            ])->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('order_item_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('cancellation_id')->constrained('cancellations')->restrictOnDelete();
            $table->foreignId('cancelled_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason_note')->nullable();
            $table->timestamp('cancelled_at')->useCurrent();
            $table->index(['order_item_id', 'cancelled_at']);
            $table->index('cancellation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_cancellations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};