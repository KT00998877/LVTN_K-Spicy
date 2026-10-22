<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryDeductionService — Phase 5 (Order ↔ Inventory linkage).
 *
 * Trách nhiệm:
 *  1. Trừ kho khi order chuyển sang CONFIRMED (kitchen accept).
 *  2. Hoàn kho khi order CANCELLED trước khi bếp bắt đầu nấu.
 *  3. Tính menu availability (đủ nguyên liệu cho 1 phần hay không).
 *
 * BR-INV-07: idempotency cho SALE — order chỉ trừ kho 1 lần.
 *  - Dùng order.inventory_processed_at làm cờ; không reset khi replay.
 *  - Nếu request retry sau khi đã trừ thành công → bỏ qua, trả OK.
 *
 * BR-INV-08: pre-check toàn bộ recipe trước khi trừ.
 *  - Gom lượng cần theo ingredient (chia sẻ giữa các món) rồi so với tồn kho.
 *  - Nếu 1 ingredient thiếu → KHÔNG trừ bất kỳ ingredient nào, rollback, throw 422
 *    với errors[] chứa ingredient / required / available / unit.
 *
 * BR-INV-09: lock + transaction.
 *  - DB::transaction bao ngoài.
 *  - lockForUpdate trên mọi ingredient row của recipe.
 *
 * BR-INV-10: hoàn kho an toàn (cancel flow).
 *  - Chỉ hoàn khi order chưa có món PREPARING/READY/SERVED.
 *  - Ngược lại throw 409 để manager xử lý thủ công (bếp đã dùng nguyên liệu).
 *  - Hoàn kho ghi type = EXPORT với note "[Hoàn kho do huỷ đơn]" và reference = order.
 *
 * Quy ước:
 *  - reference_type = 'order'
 *  - reference_id   = order.id
 *  - type cho deduction đầu tiên: SALE (nếu schema có) hoặc EXPORT.
 *
 * Lưu ý: bảng inventory_transactions của project đang dùng enum:
 *   IMPORT, EXPORT, WASTE, ADJUSTMENT (xem InventoryService::decreaseStock).
 *   Do đó deduction cho order sẽ dùng type = 'EXPORT' và prefix note "SALE:".
 *   Nếu sau này schema mở rộng enum thêm SALE thì chỉ cần đổi constant.
 */
class InventoryDeductionService
{
    /** Loại transaction dùng cho deduction do order bán hàng. */
    public const TX_TYPE_SALE = 'EXPORT';

    /** Loại transaction dùng cho hoàn kho do huỷ đơn. */
    public const TX_TYPE_RESTOCK = 'EXPORT';

    /**
     * Trừ kho cho 1 order (idempotent).
     *
     * Cần được gọi từ trong transaction của caller (đã lockForUpdate order).
     *
     * @throws \Illuminate\Validation\ValidationException 422 nếu nguyên liệu không đủ.
     */
    public function deductForOrder(Order $order, ?int $performedBy, string $eventCode = 'CONFIRMED'): array
    {
        // Idempotency: nếu đã trừ rồi → trả về danh sách transactions đã có, không làm gì thêm.
        if ($order->inventory_processed_at !== null) {
            return InventoryTransaction::where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', self::TX_TYPE_SALE)
                ->get()
                ->all();
        }

        $items = OrderItem::where('order_id', $order->id)
            ->where('status', '!=', 'CANCELLED')
            ->with(['menuItem.recipes.ingredient'])
            ->get();

        if ($items->isEmpty()) {
            // Không có món hợp lệ — không cần trừ, đánh dấu đã xử lý để khỏi chạy lại.
            $order->forceFill([
                'inventory_processed_at'     => now(),
                'inventory_processed_user_id'=> $performedBy,
                'inventory_processed_event'  => $eventCode,
            ])->save();
            return [];
        }

        // Bước 1: tính tổng lượng cần theo ingredient_id (gom các món dùng chung).
        $required = [];   // [ingredient_id => total_qty_in_cents]
        $recipesByIngredient = []; // [ingredient_id => ['unit' => ..., 'name' => ...]]

        foreach ($items as $item) {
            /** @var MenuItem $menu */
            $menu = $item->menuItem;
            if (!$menu) {
                continue; // menu đã bị xoá: bỏ qua (đã restrictOnDelete ở migration cũ nên không tới đây).
            }
            $recipeRows = $menu->recipes ?? collect();
            if ($recipeRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'menu_item_id' => ["Món '{$menu->name}' chưa có công thức. Không thể xác nhận đơn."],
                ]);
            }
            foreach ($recipeRows as $row) {
                /** @var MenuItemIngredient $row */
                $ingredient = $row->ingredient;
                if (!$ingredient) {
                    continue;
                }
                $key = (int) $ingredient->id;
                $effectiveQty = ((float) $row->quantity_required) * ((float) $row->waste_factor);
                // DB schema: quantity_required decimal(12,2); ingredient.quantity_in_stock decimal(12,2).
                // Lưu theo cents (nhân 100) để cộng dồn chính xác.
                $cents = (int) round($effectiveQty * 100) * (int) $item->quantity;
                $required[$key] = ($required[$key] ?? 0) + $cents;
                $recipesByIngredient[$key] = [
                    'name' => $ingredient->name,
                    'unit' => $row->unit_override ?: $ingredient->unit,
                ];
            }
        }

        if (empty($required)) {
            $order->forceFill([
                'inventory_processed_at'     => now(),
                'inventory_processed_user_id'=> $performedBy,
                'inventory_processed_event'  => $eventCode,
            ])->save();
            return [];
        }

        // Bước 2: pre-check + lock các ingredient theo thứ tự id để tránh deadlock.
        ksort($required);
        $ingredientIds = array_keys($required);
        $ingredients = Ingredient::whereIn('id', $ingredientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $insufficient = [];
        foreach ($required as $ingredientId => $needCents) {
            $ingredient = $ingredients[$ingredientId] ?? null;
            if (!$ingredient) {
                throw ValidationException::withMessages([
                    'ingredient_id' => ["Nguyên liệu #{$ingredientId} không tồn tại."],
                ]);
            }
            $stockCents = (int) round(((float) $ingredient->quantity_in_stock) * 100);
            if ($stockCents < $needCents) {
                $insufficient[] = [
                    'ingredient' => $recipesByIngredient[$ingredientId]['name'] ?? $ingredient->name,
                    'required'   => round($needCents / 100, 2),
                    'available'  => round($stockCents / 100, 2),
                    'unit'       => $recipesByIngredient[$ingredientId]['unit'] ?? $ingredient->unit,
                ];
            }
        }

        if (!empty($insufficient)) {
            // Quan trọng: KHÔNG trừ bất kỳ ingredient nào. Throw để caller rollback.
            throw ValidationException::withMessages([
                'inventory' => $insufficient,
            ]);
        }

        // Bước 3: trừ từng ingredient + ghi transaction.
        $transactions = [];
        foreach ($required as $ingredientId => $needCents) {
            $ingredient = $ingredients[$ingredientId];
            $before = (float) $ingredient->quantity_in_stock;
            $decrement = round($needCents / 100, 2);
            $after = round($before - $decrement, 2);
            // Lưu lại trên DB với decimal:2 (precision đã đảm bảo bằng tính toán cents).
            $ingredient->quantity_in_stock = $after;
            $ingredient->save();

            $transactions[] = InventoryTransaction::create([
                'ingredient_id'  => $ingredient->id,
                'type'           => self::TX_TYPE_SALE,
                'quantity'       => -$decrement,                // âm để phản ánh chiều biến động
                'unit_price'     => (float) $ingredient->import_price,
                'stock_before'   => $before,
                'stock_after'    => $after,
                'reference_type' => 'order',
                'reference_id'   => $order->id,
                'performed_by'   => $performedBy,
                'note'           => 'SALE: order '.$order->code.' ('.$eventCode.')',
                'request_key'    => 'order-sale-'.$order->id.'-'.$ingredient->id,
                'transacted_at'  => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // Bước 4: đánh dấu order đã trừ kho.
        $order->forceFill([
            'inventory_processed_at'     => now(),
            'inventory_processed_user_id'=> $performedBy,
            'inventory_processed_event'  => $eventCode,
        ])->save();

        return $transactions;
    }

    /**
     * Hoàn kho cho order bị huỷ (chỉ an toàn nếu bếp chưa bắt đầu).
     *
     * @param  string  $reason  lý do hoàn kho (ghi vào transaction note + order).
     * @return array<int, InventoryTransaction>
     *
     * @throws \Illuminate\Validation\ValidationException
     *         409 nếu có món đã PREPARING/READY/SERVED.
     *         422 nếu order chưa từng được trừ kho (không có gì để hoàn).
     */
    public function reverseForOrder(Order $order, int $performedBy, string $reason): array
    {
        if ($order->inventory_processed_at === null) {
            // Chưa từng trừ kho — không có gì để hoàn.
            throw ValidationException::withMessages([
                'order_id' => ['Đơn chưa được trừ kho. Không có gì để hoàn.'],
            ]);
        }
        if ($order->inventory_reversed_at !== null) {
            // Đã hoàn rồi — idempotent return.
            return InventoryTransaction::where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', self::TX_TYPE_RESTOCK)
                ->where('note', 'like', 'RESTOCK:%')
                ->get()
                ->all();
        }

        // BR-INV-10: chỉ hoàn khi KHÔNG có món PREPARING/READY/SERVED.
        $started = OrderItem::where('order_id', $order->id)
            ->whereIn('status', ['PREPARING', 'READY', 'SERVED'])
            ->exists();
        if ($started) {
            throw ValidationException::withMessages([
                'order_id' => ['Bếp đã bắt đầu chế biến (có món PREPARING/READY/SERVED). Không thể tự động hoàn kho. Vui lòng xử lý thủ công tại kiểm kê.'],
            ]);
        }

        // Lấy các transaction SALE đã ghi cho order này để biết lượng cần cộng lại.
        $sales = InventoryTransaction::where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('type', self::TX_TYPE_SALE)
            ->get();

        if ($sales->isEmpty()) {
            // Defensive: đã đánh dấu processed_at nhưng không có transaction SALE.
            // Không có gì để reverse → chỉ set cờ reversed_at.
            $order->forceFill([
                'inventory_reversed_at'      => now(),
                'inventory_reversed_user_id' => $performedBy,
                'inventory_reversal_reason'  => $reason,
            ])->save();
            return [];
        }

        // Gom theo ingredient, dùng giá trị tuyệt đối của quantity (đã là số âm ở SALE).
        $restoreByIngredient = []; // [ingredient_id => qty_to_restore]
        foreach ($sales as $tx) {
            $restoreByIngredient[(int) $tx->ingredient_id] =
                ($restoreByIngredient[(int) $tx->ingredient_id] ?? 0) + abs((float) $tx->quantity);
        }

        ksort($restoreByIngredient);
        $ingredientIds = array_keys($restoreByIngredient);
        $ingredients = Ingredient::whereIn('id', $ingredientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $restocked = [];
        foreach ($restoreByIngredient as $ingredientId => $qty) {
            $ingredient = $ingredients[$ingredientId] ?? null;
            if (!$ingredient) {
                continue; // ingredient đã bị xoá: bỏ qua (không gây lỗi đóng đơn).
            }
            $before = (float) $ingredient->quantity_in_stock;
            $after = round($before + $qty, 2);
            $ingredient->quantity_in_stock = $after;
            $ingredient->save();

            $restocked[] = InventoryTransaction::create([
                'ingredient_id'  => $ingredient->id,
                'type'           => self::TX_TYPE_RESTOCK,
                'quantity'       => $qty,                              // dương = cộng lại
                'unit_price'     => (float) $ingredient->import_price,
                'stock_before'   => $before,
                'stock_after'    => $after,
                'reference_type' => 'order',
                'reference_id'   => $order->id,
                'performed_by'   => $performedBy,
                'note'           => 'RESTOCK: order '.$order->code.' (huỷ đơn: '.$reason.')',
                'request_key'    => 'order-restock-'.$order->id.'-'.$ingredient->id,
                'transacted_at'  => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $order->forceFill([
            'inventory_reversed_at'      => now(),
            'inventory_reversed_user_id' => $performedBy,
            'inventory_reversal_reason'  => $reason,
        ])->save();

        return $restocked;
    }

    /**
     * Tính trạng thái khả dụng của 1 menu_item dựa trên tồn kho hiện tại.
     *
     * @return array{available:bool, missing:array<int, array{ingredient:string, required:float, available:float, unit:string}>}
     */
    public function menuAvailability(MenuItem $menu): array
    {
        $recipe = $menu->recipes ?? collect();
        if ($recipe->isEmpty()) {
            // Không có công thức → KHÔNG coi là out of stock; để rule "is_active=false" quyết định.
            return ['available' => true, 'missing' => []];
        }

        $missing = [];
        foreach ($recipe as $row) {
            /** @var MenuItemIngredient $row */
            $ingredient = $row->ingredient;
            if (!$ingredient) continue;
            $effective = (float) $row->quantity_required * (float) $row->waste_factor;
            $stock = (float) $ingredient->quantity_in_stock;
            if ($stock + 1e-9 < $effective) {
                $missing[] = [
                    'ingredient' => $ingredient->name,
                    'required'   => round($effective, 2),
                    'available'  => round($stock, 2),
                    'unit'       => $row->unit_override ?: $ingredient->unit,
                ];
            }
        }

        return [
            'available' => empty($missing),
            'missing'   => $missing,
        ];
    }
}
