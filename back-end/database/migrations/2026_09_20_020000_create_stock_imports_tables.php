<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Phiếu nhập kho (Stock Import).
 *
 *  - stock_imports      : header của phiếu nhập (DRAFT → CONFIRMED → CANCELLED).
 *  - stock_import_items : chi tiết từng dòng nguyên liệu của phiếu.
 *
 *  - status DRAFT không được cộng tồn kho.
 *  - status CONFIRMED đã cộng tồn kho 1 lần (idempotent).
 *  - status CANCELLED có ghi chú lý do.
 *
 *  - imported_at chỉ là ngày nhập theo múi giờ VN, không chứa giờ chi tiết.
 *  - expiry_date ở item-level cho phép tracking HSD theo từng lô nhập.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('Mã phiếu nhập, vd: PN-20260920-0001');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete()
                  ->comment('Nhân viên tạo phiếu (MANAGER)');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->date('imported_at')->comment('Ngày nhập thực tế theo múi giờ VN');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CANCELLED'])->default('DRAFT');
            $table->decimal('total_cost', 14, 2)->default(0)->comment('Tổng tiền = sum(items.subtotal)');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'imported_at']);
            $table->index(['supplier_id', 'imported_at']);
        });

        Schema::create('stock_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_import_id')->constrained('stock_imports')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->comment('Số lượng nhập');
            $table->string('unit', 20)->comment('Đơn vị tại thời điểm nhập (copy từ ingredient.unit)');
            $table->decimal('unit_price', 12, 2)->comment('Đơn giá nhập');
            $table->decimal('subtotal', 14, 2)->comment('= quantity * unit_price');
            $table->date('expiry_date')->nullable()->comment('HSD theo lô nhập (NULL = không theo dõi)');
            $table->string('supplier_lot_code', 60)->nullable()->comment('Mã lô từ NCC (optional)');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['stock_import_id']);
            $table->index(['ingredient_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_import_items');
        Schema::dropIfExists('stock_imports');
    }
};
