<?php

namespace App\Services;

use App\Models\{Ingredient, InventoryTransaction, StockImport, Stocktake};
use App\Models\StockImportItem;
use App\Models\StocktakeItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * InventoryService — cổng duy nhất cho MỌI thay đổi tồn kho.
 *
 *  Tất cả mutation đều phải đi qua:
 *    - increaseStock(): nhập kho (từ stock import hoặc thủ công)
 *    - decreaseStock(): xuất kho (sẽ dùng cho SALE / WASTE trong phase sau)
 *    - adjustStock():   điều chỉnh tới 1 giá trị tuyệt đối (stocktake hoặc thủ công)
 *    - confirmImport(): xác nhận phiếu nhập DRAFT (gọi increaseStock cho từng item)
 *    - completeStocktake(): hoàn tất kiểm kê (gọi adjustStock cho từng item)
 *
 *  Đảm bảo:
 *    - DB::transaction cho mỗi unit-of-work.
 *    - lockForUpdate trên ingredient rows để chống race condition.
 *    - Mỗi mutation tạo đúng 1 row inventory_transactions (audit trail).
 *    - Không bao giờ cho stock âm (BR-INV-04).
 *    - idempotency cho confirm import: gọi 2 lần vẫn chỉ cộng stock 1 lần (BR-INV-03).
 */
class InventoryService
{
    /* =====================================================================
     * 1. INCREASE STOCK (nhập kho)
     * =====================================================================
     */

    /**
     * Cộng tồn kho cho 1 ingredient, ghi 1 bản ghi IMPORT.
     *
     * @param array{
     *   ingredient_id:int,
     *   quantity:float,
     *   unit_price:float,
     *   note?:string,
     *   reference_type?:string,
     *   reference_id?:int,
     *   request_key?:string|null
     * } $payload
     *
     * @return InventoryTransaction
     */
    public function increaseStock(array $payload, int $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($payload, $performedBy) {
            $this->assertStockKeys($payload, ['ingredient_id', 'quantity', 'unit_price']);
            $ingredient = $this->lockIngredient((int) $payload['ingredient_id']);
            $cents = fn (float $n): int => (int) round($n * 100);

            $before = $cents((float) $ingredient->quantity_in_stock);
            $qty    = $cents((float) $payload['quantity']);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Số lượng nhập phải > 0.']);
            }
            $after = $this->guardOverflow($before + $qty);

            // Idempotency: nếu request_key đã được ghi trước đó → trả về bản ghi cũ.
            if (!empty($payload['request_key'])) {
                $existing = InventoryTransaction::where('request_key', $payload['request_key'])->first();
                if ($existing) {
                    $this->assertReplayMatches($existing, $payload, $ingredient->id);
                    return $existing;
                }
            }

            $ingredient->update([
                'quantity_in_stock' => $after / 100,
                'import_price'      => (float) $payload['unit_price'],
                'import_date'       => Carbon::now('Asia/Ho_Chi_Minh')->toDateString(),
                'updated_at'        => now(),
            ]);

            return $this->writeTransaction([
                'ingredient_id' => $ingredient->id,
                'type'          => 'IMPORT',
                'quantity'      => $qty / 100,
                'unit_price'    => (float) $payload['unit_price'],
                'stock_before'  => $before / 100,
                'stock_after'   => $after / 100,
                'reference_type'=> $payload['reference_type'] ?? null,
                'reference_id'  => $payload['reference_id']   ?? null,
                'performed_by'  => $performedBy,
                'note'          => $payload['note'] ?? null,
                'request_key'   => $payload['request_key'] ?? (string) Str::uuid(),
                'transacted_at' => now(),
            ]);
        });
    }

    /* =====================================================================
     * 2. DECREASE STOCK (xuất kho / WASTE)
     * =====================================================================
     */

    /**
     * Trừ tồn kho cho 1 ingredient.
     *
     * @param array{
     *   ingredient_id:int,
     *   quantity:float,
     *   type:string,            // EXPORT | WASTE
     *   note?:string,
     *   reference_type?:string,
     *   reference_id?:int,
     *   request_key?:string|null
     * } $payload
     */
    public function decreaseStock(array $payload, int $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($payload, $performedBy) {
            $this->assertStockKeys($payload, ['ingredient_id', 'quantity', 'type']);
            $type = $payload['type'];
            if (!in_array($type, ['EXPORT', 'WASTE'], true)) {
                throw ValidationException::withMessages(['type' => 'Loại xuất kho không hợp lệ.']);
            }
            $ingredient = $this->lockIngredient((int) $payload['ingredient_id']);
            $cents = fn (float $n): int => (int) round($n * 100);

            $before = $cents((float) $ingredient->quantity_in_stock);
            $qty    = $cents((float) $payload['quantity']);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Số lượng xuất phải > 0.']);
            }
            if (!empty($payload['request_key'])) {
                $existing = InventoryTransaction::where('request_key', $payload['request_key'])->first();
                if ($existing) {
                    $this->assertReplayMatches($existing, $payload, $ingredient->id);
                    return $existing;
                }
            }
            $after = $before - $qty;
            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => sprintf('Không đủ tồn kho (hiện có %.2f %s).', $before / 100, $ingredient->unit),
                ]);
            }

            $ingredient->update([
                'quantity_in_stock' => $after / 100,
                'updated_at'        => now(),
            ]);

            return $this->writeTransaction([
                'ingredient_id' => $ingredient->id,
                'type'          => $type,
                'quantity'      => -($qty / 100),                  // âm để phản ánh chiều biến động
                'reason_code'   => $payload['reason_code'] ?? null,
                'unit_price'    => (float) $ingredient->import_price,
                'stock_before'  => $before / 100,
                'stock_after'   => $after / 100,
                'reference_type'=> $payload['reference_type'] ?? null,
                'reference_id'  => $payload['reference_id']   ?? null,
                'performed_by'  => $performedBy,
                'note'          => $payload['note'] ?? null,
                'request_key'   => $payload['request_key'] ?? (string) Str::uuid(),
                'transacted_at' => now(),
            ]);
        });
    }

    /* =====================================================================
     * 3. ADJUST STOCK (điều chỉnh tới giá trị tuyệt đối)
     * =====================================================================
     */

    /**
     * Đặt tồn kho về 1 giá trị tuyệt đối (kiểm kê hoặc thủ công).
     *
     * @param array{
     *   ingredient_id:int,
     *   expected_stock:float,    // số lượng thực tế
     *   note?:string,
     *   reference_type?:string,
     *   reference_id?:int
     * } $payload
     */
    public function adjustStock(array $payload, int $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($payload, $performedBy) {
            $this->assertStockKeys($payload, ['ingredient_id', 'expected_stock']);
            $ingredient = $this->lockIngredient((int) $payload['ingredient_id']);
            $cents = fn (float $n): int => (int) round($n * 100);

            $before = $cents((float) $ingredient->quantity_in_stock);
            $after  = $cents((float) $payload['expected_stock']);
            if ($after < 0) {
                throw ValidationException::withMessages(['expected_stock' => 'Tồn kho sau điều chỉnh không được âm.']);
            }
            if ($after > 9_999_999_999_99) {
                throw ValidationException::withMessages(['expected_stock' => 'Vượt giới hạn lưu trữ.']);
            }

            // BR-INV-05: optimistic concurrency — nếu caller truyền `current_stock_check`
            // (số tồn họ đọc trước khi gửi), phải khớp DB. Mặc định KHÔNG check
            // (cho service nội bộ như stocktake).
            if (isset($payload['current_stock_check'])) {
                $expectedBefore = $cents((float) $payload['current_stock_check']);
                if ($before !== $expectedBefore) {
                    throw ValidationException::withMessages([
                        'expected_stock' => 'Tồn kho đã thay đổi. Hãy làm mới và kiểm kê lại.',
                    ]);
                }
            }

            $change = $after - $before;

            $ingredient->update([
                'quantity_in_stock' => $after / 100,
                'updated_at'        => now(),
            ]);

            return $this->writeTransaction([
                'ingredient_id' => $ingredient->id,
                'type'          => 'ADJUSTMENT',
                'quantity'      => $change / 100,
                'unit_price'    => (float) $ingredient->import_price,
                'stock_before'  => $before / 100,
                'stock_after'   => $after / 100,
                'reference_type'=> $payload['reference_type'] ?? null,
                'reference_id'  => $payload['reference_id']   ?? null,
                'performed_by'  => $performedBy,
                'note'          => $payload['note'] ?? 'Điều chỉnh thủ công',
                'request_key'   => $payload['request_key'] ?? (string) Str::uuid(),
                'transacted_at' => now(),
            ]);
        });
    }

    /* =====================================================================
     * 4. CONFIRM STOCK IMPORT (DRAFT → CONFIRMED)
     * =====================================================================
     */

    /**
     * Xác nhận phiếu nhập. Gọi đúng 1 lần.
     *
     *  - Nếu phiếu đã CONFIRMED → trả về không tạo thêm transactions (idempotent).
     *  - Nếu CANCELLED → 422.
     *
     * @return StockImport
     */
    public function confirmImport(StockImport $import, int $performedBy, Carbon $receivedAt): StockImport
    {
        return DB::transaction(function () use ($import, $performedBy, $receivedAt) {
            // Khóa cùng trang sổ mà bếp dùng khi chốt để phiếu không chen vào sau giờ đếm.
            DB::table('daily_stock_sheets')->orderByDesc('business_date')->lockForUpdate()->first();
            $latestCount = DB::table('daily_stock_sheets')->where('status', 'CLOSED')->orderByDesc('closed_at')->value('counted_at');
            if ($latestCount && $receivedAt->lessThanOrEqualTo(Carbon::parse($latestCount))) {
                throw ValidationException::withMessages(['received_at' => 'Hàng nhận trước hoặc đúng lần đếm đã chốt. Cần đối chiếu sổ trước khi ghi nhập.']);
            }
            /** @var StockImport $locked */
            $locked = StockImport::lockForUpdate()->findOrFail($import->id);
            if ($locked->status === StockImport::STATUS_CONFIRMED) {
                return $locked; // Idempotent.
            }
            if ($locked->status === StockImport::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Phiếu nhập đã bị huỷ, không thể xác nhận.',
                ]);
            }

            // Chốt từng item → gọi increaseStock với reference_type='stock_import'.
            // Lưu ý: increaseStock tự lockForUpdate, nên ta phải chấp nhận nested lockForUpdate
            // (Postgres/MySQL đều cho phép nested locks cùng transaction).
            foreach ($locked->items()->lockForUpdate()->get() as $item) {
                /** @var StockImportItem $item */
                $this->increaseStock([
                    'ingredient_id'   => $item->ingredient_id,
                    'quantity'        => (float) $item->quantity,
                    'unit_price'      => (float) $item->unit_price,
                    'note'            => sprintf('Phiếu nhập %s', $locked->code),
                    'reference_type'  => 'stock_import',
                    'reference_id'    => $locked->id,
                    'request_key'     => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_DNS, sprintf('stock_import:%d:item:%d', $locked->id, $item->id))->toString(),
                ], $performedBy);
            }

            $locked->update([
                'status'       => StockImport::STATUS_CONFIRMED,
                'confirmed_by' => $performedBy,
                'confirmed_at' => now(),
                'received_at' => $receivedAt,
            ]);

            Log::info('stock_import.confirmed', [
                'code' => $locked->code, 'by' => $performedBy,
                'items' => $locked->items()->count(),
            ]);

            return $locked->fresh('items', 'confirmer');
        });
    }

    /* =====================================================================
     * 5. COMPLETE STOCKTAKE (OPEN → COMPLETED)
     * =====================================================================
     */

    /**
     * Hoàn tất kiểm kê đột xuất như một bản đối chiếu; sổ ngày mới là nguồn tồn thực tế.
     *
     *  - Nếu đã COMPLETED → trả về không tạo thêm (idempotent).
     *  - Nếu CANCELLED → 422.
     *
     * @return Stocktake
     */
    public function completeStocktake(Stocktake $stocktake, int $performedBy): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $performedBy) {
            /** @var Stocktake $locked */
            $locked = Stocktake::lockForUpdate()->findOrFail($stocktake->id);
            if ($locked->status === Stocktake::STATUS_COMPLETED) {
                return $locked;
            }
            if ($locked->status === Stocktake::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Đợt kiểm kê đã bị huỷ, không thể hoàn tất.',
                ]);
            }

            $totalValue = 0;
            foreach ($locked->items()->lockForUpdate()->get() as $row) {
                /** @var StocktakeItem $row */
                $ingredient = $this->lockIngredient((int) $row->ingredient_id);
                $difference = round((float) $row->actual_qty - (float) $ingredient->quantity_in_stock, 2);
                $value = round($difference * (float) $ingredient->import_price, 2);
                $row->update([
                    'system_qty' => (float) $ingredient->quantity_in_stock,
                    'difference_qty' => $difference,
                    'unit_cost' => (float) $ingredient->import_price,
                    'difference_value' => $value,
                ]);
                $totalValue += $value;
            }

            $locked->update([
                'status'                 => Stocktake::STATUS_COMPLETED,
                'completed_by'           => $performedBy,
                'completed_at'           => now(),
                'total_difference_value' => $totalValue,
            ]);

            Log::info('stocktake.completed', [
                'code' => $locked->code, 'by' => $performedBy,
                'items' => $locked->items()->count(),
                'total_value' => $totalValue,
            ]);

            return $locked->fresh('items', 'completer');
        });
    }

    /* =====================================================================
     *  INTERNAL HELPERS
     * =====================================================================
     */

    /** Khoá 1 ingredient row để chống race condition. */
    private function lockIngredient(int $id): Ingredient
    {
        $i = Ingredient::lockForUpdate()->find($id);
        if (!$i) {
            throw (new ModelNotFoundException())->setModel(Ingredient::class, [$id]);
        }
        return $i;
    }

    private function guardOverflow(int $afterCents): int
    {
        if ($afterCents > 9_999_999_999_99) {
            throw ValidationException::withMessages(['quantity' => 'Vượt giới hạn lưu trữ (12 chữ số thập phân).']);
        }
        return $afterCents;
    }

    /** Ghi 1 row inventory_transactions. */
    private function writeTransaction(array $data): InventoryTransaction
    {
        $id = InventoryTransaction::insertGetId(array_merge($data, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return InventoryTransaction::findOrFail($id);
    }

    /** Assert các field bắt buộc có mặt. */
    private function assertStockKeys(array $payload, array $required): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                throw ValidationException::withMessages([
                    $key => "Thiếu trường bắt buộc: {$key}",
                ]);
            }
        }
    }

    /** Idempotency: nếu request_key đã được dùng → payload phải khớp bản gốc, nếu không → 409. */
    private function assertReplayMatches(InventoryTransaction $existing, array $payload, int $ingredientId): void
    {
        $matches = (int) $existing->ingredient_id === $ingredientId
            && (string) ($existing->note ?? '') === (string) ($payload['note'] ?? '');
        if (isset($payload['expected_stock'])) {
            $matches = $matches && $existing->type === 'ADJUSTMENT'
                && round((float) $existing->stock_after, 2) === round((float) $payload['expected_stock'], 2);
        } else {
            $matches = $matches && $existing->type === ($payload['type'] ?? 'IMPORT')
                && round(abs((float) $existing->quantity), 2) === round((float) $payload['quantity'], 2);
        }

        if (!$matches) {
            // 409 Conflict: cùng request_key nhưng payload khác → client phải đổi key.
            abort(409, 'Mã yêu cầu đã được sử dụng. Hãy đóng và lập phiếu mới.');
        }
    }
}
