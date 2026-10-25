<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F — Tự trừ kho theo món + yêu cầu nhập kho từ bếp.
 *
 *  1. order_items.inventory_deducted_at
 *     Cờ idempotency: 1 order_item chỉ bị trừ kho đúng 1 lần (tại lúc bếp
 *     bấm "Bắt đầu nấu"). NULL = chưa trừ. Giữ nguyên giá trị sau khi trừ
 *     để retry không trừ 2 lần.
 *
 *  2. stock_requests — yêu cầu nhập kho do bếp tạo khi phát hiện thiếu nguyên liệu.
 *     Tách khỏi stock_imports vì: yêu cầu của bếp chưa có đơn giá / nhà cung cấp
 *     (bếp không biết), và manager muốn gộp nhiều yêu cầu thành 1 phiếu nhập.
 *     status: OPEN → RESOLVED | DISMISSED.
 *
 *  3. stock_request_items — chi tiết từng nguyên liệu thiếu trong 1 yêu cầu.
 *
 *  4. daily_stock_sheet_items.consumption_qty
 *     Lượng nguyên liệu đã bị trừ do món bán trong kỳ. Tách khỏi outgoing_qty
 *     (xuất/hủy thủ công) để bảng sổ kho ngày không đếm trùng: nếu gộp chung,
 *     cột "Dùng dự kiến" (tính từ công thức) và cột "Xuất/hủy" sẽ trùng nhau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'inventory_deducted_at')) {
                $table->timestamp('inventory_deducted_at')->nullable()->after('recipe_captured_at')
                    ->comment('Lần bếp bắt đầu nấu đã trừ kho cho món này (idempotency)');
            }
            if (!Schema::hasColumn('order_items', 'inventory_deduction_key')) {
                $table->uuid('inventory_deduction_key')->nullable()->after('inventory_deducted_at')
                    ->comment('Mã lần trừ: phân biệt món được nấu lại sau khi hủy, để request_key không va chạm');
            }
        });

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('Mã yêu cầu, vd: YC-20261023-0001');
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete()
                ->comment('Món bếp phát hiện thiếu nguyên liệu');
            $table->string('menu_item_name', 150)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Bếp gửi yêu cầu');
            $table->enum('status', ['OPEN', 'RESOLVED', 'DISMISSED'])->default('OPEN');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('required_qty', 12, 3)->comment('Lượng cần cho món này');
            $table->decimal('available_qty', 12, 3)->comment('Tồn còn lại lúc bếp phát hiện');
            $table->decimal('shortage_qty', 12, 3)->comment('Phần thiếu = required - available');
            $table->string('unit', 20);
            $table->timestamps();
            $table->unique(['stock_request_id', 'ingredient_id'], 'stock_request_ingredient_unique');
        });

        Schema::table('daily_stock_sheet_items', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_stock_sheet_items', 'consumption_qty')) {
                $table->decimal('consumption_qty', 12, 2)->default(0)->after('outgoing_qty')
                    ->comment('Lượng trừ do món bán trong kỳ (tách khỏi xuất/hủy thủ công)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_stock_sheet_items', function (Blueprint $table) {
            if (Schema::hasColumn('daily_stock_sheet_items', 'consumption_qty')) {
                $table->dropColumn('consumption_qty');
            }
        });
        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('stock_requests');
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'inventory_deduction_key')) {
                $table->dropColumn('inventory_deduction_key');
            }
            if (Schema::hasColumn('order_items', 'inventory_deducted_at')) {
                $table->dropColumn('inventory_deducted_at');
            }
        });
    }
};
