<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('id')
                ->constrained('suppliers')->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', [
                'DRAFT',
                'SENT',
                'SUPPLIER_ACCEPTED',
                'SUPPLIER_REJECTED',
                'SHIPPED',
                'RECEIVED',
                'CANCELLED',
            ])->default('DRAFT');
            $table->date('requested_delivery_date')->nullable();
            $table->date('supplier_delivery_date')->nullable();
            $table->text('manager_note')->nullable();
            $table->text('supplier_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('supplier_document_code', 100)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('supplier_responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('supplier_responded_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->unique(['supplier_id', 'supplier_document_code'], 'purchase_order_supplier_document_unique');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('ingredient_packaging_id')->nullable()
                ->constrained('ingredient_packagings')->nullOnDelete();
            $table->string('purchase_unit', 30);
            $table->unsignedBigInteger('conversion_quantity')->default(1);
            $table->unsignedBigInteger('requested_purchase_quantity');
            $table->unsignedBigInteger('supplier_accepted_quantity')->nullable();
            $table->unsignedBigInteger('shipped_purchase_quantity')->nullable();
            $table->decimal('purchase_unit_price', 14, 2)->nullable();
            $table->string('supplier_lot_code', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('supplier_note')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'ingredient_id'], 'purchase_order_ingredient_unique');
        });

        Schema::table('stock_imports', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->unique()
                ->after('id')->constrained('purchase_orders')->restrictOnDelete();
        });

        Schema::table('stock_import_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')->nullable()->unique()
                ->after('id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->unsignedBigInteger('delivered_purchase_quantity')->nullable()->after('purchase_quantity');
            $table->unsignedBigInteger('accepted_purchase_quantity')->nullable()->after('delivered_purchase_quantity');
            $table->unsignedBigInteger('rejected_purchase_quantity')->nullable()->after('accepted_purchase_quantity');
            $table->text('rejection_reason')->nullable()->after('rejected_purchase_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_import_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
            $table->dropColumn([
                'delivered_purchase_quantity',
                'accepted_purchase_quantity',
                'rejected_purchase_quantity',
                'rejection_reason',
            ]);
        });
        Schema::table('stock_imports', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
    }
};
