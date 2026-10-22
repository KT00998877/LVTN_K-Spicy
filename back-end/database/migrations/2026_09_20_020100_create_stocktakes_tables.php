<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Phiên kiểm kê (Stocktake).
 *
 *  Flow: OPEN (đang nhập liệu) → COMPLETED (đã tạo ADJUSTMENT transactions).
 *
 *  - Không cho phép sửa items sau khi COMPLETED.
 *  - system_qty chốt tại thời điểm complete để audit.
 *  - stocktake_items.inventory_transaction_id trỏ tới bản ghi ADJUSTMENT đã tạo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('Mã đợt kiểm kê: KK-YYYYMMDD-NNNN');
            $table->date('counted_at')->comment('Ngày kiểm kê thực tế (timezone VN)');
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['OPEN', 'COMPLETED', 'CANCELLED'])->default('OPEN');
            $table->decimal('total_difference_value', 14, 2)->default(0)
                  ->comment('Tổng tiền chênh lệch sau khi complete');
            $table->text('note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'counted_at']);
        });

        Schema::create('stocktake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('system_qty', 12, 2)->comment('Số lượng hệ thống tại thời điểm chốt');
            $table->decimal('actual_qty', 12, 2)->comment('Số lượng thực tế kiểm đếm');
            $table->decimal('difference_qty', 12, 2)->comment('Dương = thừa; Âm = thiếu');
            $table->decimal('unit_cost', 12, 2)->default(0)->comment('Giá vốn đơn vị tại thời điểm kiểm kê');
            $table->decimal('difference_value', 14, 2)->default(0)->comment('= difference_qty * unit_cost');
            $table->text('reason')->nullable()->comment('Lý do chênh lệch (vd: hao hụt, ghi sai)');
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete()
                  ->comment('Trỏ tới ADJUSTMENT transaction đã tạo khi complete');
            $table->timestamps();

            $table->unique(['stocktake_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_items');
        Schema::dropIfExists('stocktakes');
    }
};
