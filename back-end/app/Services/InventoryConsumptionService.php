<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InventoryConsumptionService — Phase F: tự trừ kho theo TỪNG MÓN khi bếp bắt đầu nấu.
 *
 * Vì sao theo món chứ không theo đơn:
 *  - Bếp nấu từng món một (món A xong trước món B), mỗi món có công thức riêng.
 *  - Khách đổi ý giữa chừng → phải hoàn đúng phần của món đó.
 *  - Bảng "đã bán món nào trừ bao nhiêu" cần gắn ở cấp món, không phải cấp đơn.
 *
 * Nguyên tắc tồn kho:
 *  - Tồn KHÔNG BAO GIỜ âm (BR-INV-04). Khi thiếu, trừ tới 0 rồi ghi nhận phần thiếu.
 *  - Bếp là người quyết định: thiếu nhẹ vẫn nấu được, thiếu nặng thì hủy món.
 *  - Mọi thay đổi tồn đi qua InventoryService (cổng duy nhất) để giữ audit trail.
 */
class InventoryConsumptionService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Kiểm tra món có thiếu nguyên liệu không — dùng để bếp xem cảnh báo TRƯỚC khi nấu.
     *
     * Không ghi gì, không khóa tồn (chỉ đọc). Nên có thể gọi nhiều lần.
     *
     * @return array{has_shortage:bool, shortages:array<int, array{ingredient_id:int, ingredient:string, unit:string, required:float, available:float, shortage:float}>}
     */
    public function checkShortage(OrderItem $item): array
    {
        $required = $this->requiredQuantity($item);
        if ($required === null) {
            return ['has_shortage' => false, 'shortages' => []];
        }

        $shortages = [];
        foreach ($required as $ingredientId => $need) {
            $ingredient = Ingredient::find($ingredientId);
            if (!$ingredient) continue;
            $available = (float) $ingredient->quantity_in_stock;
            if ($available + 0.0001 < $need) {
                $shortages[] = [
                    'ingredient_id' => (int) $ingredientId,
                    'ingredient'    => (string) $ingredient->name,
                    'unit'          => (string) $ingredient->unit,
                    'required'      => round($need, 3),
                    'available'     => round($available, 3),
                    'shortage'      => round($need - $available, 3),
                ];
            }
        }

        return ['has_shortage' => $shortages !== [], 'shortages' => $shortages];
    }

    /**
     * Trừ kho cho 1 món khi bếp bắt đầu nấu.
     *
     * - Idempotent: nếu item đã có inventory_deducted_at → không trừ lần 2.
     * - Không âm: phần thiếu được trừ tới 0 và trả về để bếp quyết định.
     * - Dùng recipe_snapshot (chụp lúc bắt đầu nấu) nên đúng công thức tại thời điểm nấu,
     *   không bị ảnh hưởng khi manager sửa công thức giữa lúc bếp đang nấu.
     *
     * @param  bool  $allowShortage  true = bếp chọn "tiếp tục nấu" dù thiếu (trừ tới 0).
     * @return array{deducted:bool, shortages:array, request_code:?string}
     */
    public function deductForItem(OrderItem $item, User $actor, bool $allowShortage = false): array
    {
        return DB::transaction(function () use ($item, $actor, $allowShortage) {
            $locked = OrderItem::lockForUpdate()->find($item->id);
            if (!$locked) {
                return ['deducted' => false, 'shortages' => [], 'request_code' => null];
            }
            // BR-INV-07: 1 món chỉ trừ kho đúng 1 lần.
            if ($locked->inventory_deducted_at !== null) {
                return ['deducted' => false, 'shortages' => [], 'request_code' => null];
            }

            $required = $this->requiredQuantity($locked);
            if ($required === null || $required === []) {
                $locked->forceFill(['inventory_deducted_at' => now()])->save();
                return ['deducted' => true, 'shortages' => [], 'request_code' => null];
            }

            // Lock ingredient theo thứ tự id để tránh deadlock.
            ksort($required);
            $ingredients = Ingredient::whereIn('id', array_keys($required))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $shortages = [];
            foreach ($required as $ingredientId => $need) {
                $ingredient = $ingredients->get($ingredientId);
                if (!$ingredient) continue;
                $available = (float) $ingredient->quantity_in_stock;
                if ($available + 0.0001 < $need) {
                    $shortages[] = [
                        'ingredient_id' => (int) $ingredientId,
                        'ingredient'    => (string) $ingredient->name,
                        'unit'          => (string) $ingredient->unit,
                        'required'      => round($need, 3),
                        'available'     => round($available, 3),
                        'shortage'      => round($need - $available, 3),
                    ];
                }
            }

            // Bếp chưa đồng ý nấu tiếp dù thiếu → không trừ gì, để bếp quyết định.
            if ($shortages !== [] && !$allowShortage) {
                return ['deducted' => false, 'shortages' => $shortages, 'request_code' => null];
            }

            foreach ($required as $ingredientId => $need) {
                $ingredient = $ingredients->get($ingredientId);
                if (!$ingredient) continue;

                // Clamp: trừ tối đa tới 0 — tồn không bao giờ âm.
                $deducted = min($need, (float) $ingredient->quantity_in_stock);
                if ($deducted <= 0) continue;

                $this->inventory->decreaseStock([
                    'ingredient_id'   => $ingredient->id,
                    'quantity'        => $deducted,
                    'type'            => 'EXPORT',
                    'note'            => sprintf('Tiêu hao món %s (đơn %s)', $locked->item_name, $locked->order?->code ?? '-'),
                    'reference_type'  => 'order_item',
                    'reference_id'    => $locked->id,
                    'request_key'     => sprintf('consume:%s:%d:%d', $locked->inventory_deduction_key, $locked->id, $ingredient->id),
                ], $actor->id);
            }

            $locked->forceFill([
                'inventory_deducted_at'   => now(),
                'inventory_deduction_key' => (string) Str::uuid(),
            ])->save();

            $requestCode = null;
            if ($shortages !== []) {
                $request = $this->createStockRequest($locked, $shortages, $actor);
                $requestCode = $request?->code;
            }

            return ['deducted' => true, 'shortages' => $shortages, 'request_code' => $requestCode];
        });
    }

    /**
     * Hoàn kho khi bếp hủy món SAU khi đã bắt đầu nấu (đã trừ kho).
     * Chỉ hoàn phần thực sự đã trừ cho món này.
     *
     * @return int số lượng nguyên liệu đã hoàn
     */
    public function restoreForItem(OrderItem $item, User $actor, string $reason): int
    {
        return DB::transaction(function () use ($item, $actor, $reason) {
            $locked = OrderItem::lockForUpdate()->find($item->id);
            if (!$locked || $locked->inventory_deducted_at === null) {
                return 0;
            }

            $transactions = InventoryTransaction::where('reference_type', 'order_item')
                ->where('reference_id', $locked->id)
                ->where('type', 'EXPORT')
                ->get();

            $deductionKey = (string) ($locked->inventory_deduction_key ?? '');

            $restored = 0;
            foreach ($transactions as $tx) {
                $ingredient = Ingredient::lockForUpdate()->find($tx->ingredient_id);
                if (!$ingredient) continue;
                $qty = abs((float) $tx->quantity);
                if ($qty <= 0) continue;

                // Hoàn kho qua cổng ADJUSTMENT, không phải increaseStock (IMPORT):
                // increaseStock sẽ ghi type=IMPORT và ghi đè import_price/import_date
                // của nguyên liệu — sai về nghiệp vụ khi chỉ hoàn lại lượng đã lấy.
                $before = (float) $ingredient->quantity_in_stock;
                $after = round($before + $qty, 2);

                $this->inventory->adjustStock([
                    'ingredient_id'   => $ingredient->id,
                    'expected_stock'  => $after,
                    'reference_type'  => 'order_item_restore',
                    'reference_id'    => $locked->id,
                    'note'            => sprintf('Hoàn kho món %s: %s', $locked->item_name, $reason),
                    'request_key'     => sprintf('restore:%s:%d:%d', $deductionKey, $locked->id, $tx->ingredient_id),
                ], $actor->id);
                $restored++;
            }

            // Reset cờ để nếu bếp nấu lại món này thì trừ lại từ đầu (và có mã lần trừ mới).
            $locked->forceFill([
                'inventory_deducted_at'   => null,
                'inventory_deduction_key' => null,
            ])->save();

            return $restored;
        });
    }

    /**
     * Kiểm tra thiếu nguyên liệu cho NHIỀU món cùng lúc — dùng cho danh sách bếp.
     *
     * Gom toàn bộ công thức và tồn kho trong 2 query để không N+1:
     * danh sách bếp có thể vài chục món, mỗi món vài nguyên liệu.
     *
     * @param  array<int, OrderItem>  $items
     * @return array<int, array>  [order_item_id => shortages]
     */
    public function shortagesForItems(array $items): array
    {
        if ($items === []) return [];

        // Gom menu_item_id cần tra công thức (món chính + topping).
        $menuIds = [];
        $itemToppings = [];
        foreach ($items as $item) {
            $menuIds[(int) $item->menu_item_id] = true;
            $itemToppings[$item->id] = DB::table('order_item_toppings')
                ->where('order_item_id', $item->id)
                ->get(['topping_menu_item_id', 'quantity']);
            foreach ($itemToppings[$item->id] as $t) {
                $menuIds[(int) $t->topping_menu_item_id] = true;
            }
        }

        $recipes = DB::table('menu_item_ingredient as r')
            ->join('ingredients as i', 'i.id', '=', 'r.ingredient_id')
            ->whereIn('r.menu_item_id', array_keys($menuIds))
            ->get(['r.menu_item_id', 'r.ingredient_id', 'r.quantity_required', 'r.waste_factor', 'r.unit_override', 'i.unit', 'i.name'])
            ->groupBy('menu_item_id');

        $stock = Ingredient::whereIn('id',
            DB::table('menu_item_ingredient')->whereIn('menu_item_id', array_keys($menuIds))->distinct()->pluck('ingredient_id')
        )->pluck('quantity_in_stock', 'id');

        $result = [];
        foreach ($items as $item) {
            $parts = [['menu_item_id' => (int) $item->menu_item_id, 'multiplier' => (int) $item->quantity]];
            foreach ($itemToppings[$item->id] ?? collect() as $t) {
                $parts[] = [
                    'menu_item_id' => (int) $t->topping_menu_item_id,
                    'multiplier'   => (int) $item->quantity * (int) $t->quantity,
                ];
            }

            $required = [];
            $comparable = true;
            $found = false;
            foreach ($parts as $part) {
                $lines = $recipes->get($part['menu_item_id']);
                if (!$lines || $lines->isEmpty()) continue;
                $found = true;
                foreach ($lines as $line) {
                    if ($line->unit_override !== null && $line->unit_override !== $line->unit) {
                        $comparable = false;
                        continue;
                    }
                    $id = (int) $line->ingredient_id;
                    $required[$id] = ($required[$id] ?? 0)
                        + (float) $line->quantity_required * (float) $line->waste_factor * $part['multiplier'];
                }
            }

            if (!$found || !$comparable) {
                continue; // Không xác định được → không cảnh báo (không gây hiểu nhầm cho bếp).
            }

            $shortages = [];
            foreach ($required as $ingredientId => $need) {
                $available = (float) ($stock[$ingredientId] ?? 0);
                if ($available + 0.0001 >= $need) continue;
                $line = collect($recipes->flatten(1))->firstWhere('ingredient_id', $ingredientId);
                $shortages[] = [
                    'ingredient_id' => (int) $ingredientId,
                    'ingredient'    => (string) ($line->name ?? 'Nguyên liệu #'.$ingredientId),
                    'unit'          => (string) ($line->unit ?? ''),
                    'required'      => round($need, 3),
                    'available'     => round($available, 3),
                    'shortage'      => round($need - $available, 3),
                ];
            }
            if ($shortages !== []) {
                $result[(int) $item->id] = $shortages;
            }
        }

        return $result;
    }

    /**
     * Ẩn món khỏi thực đơn vì hết nguyên liệu chính.
     *
     * Khi bếp hủy món với lý do "Hết nguyên liệu", món đó không thể nấu được nữa.
     * Ẩn đi để khách không đặt món bếp không nấu nổi, và để quản lý biết cần nhập kho.
     *
     * CHỈ ẩn khi nguyên liệu ĐANG THIẾU là "trọng yếu" (availability_required) —
     * thiếu gia vị phụ thì bếp vẫn nấu được, không nên ẩn món khỏi menu.
     *
     * @param  array<int, int>  $shortageIngredientIds  Danh sách nguyên liệu đang thiếu.
     * @return bool true nếu món bị ẩn
     */
    public function hideMenuItemForShortage(int $menuItemId, string $reason, array $shortageIngredientIds = []): bool
    {
        $menu = MenuItem::find($menuItemId);
        if (!$menu) return false;

        // Đã ẩn rồi → không cần làm gì thêm.
        if (!(bool) $menu->is_available) return false;

        // Chỉ ẩn nếu CÓ nguyên liệu đang thiếu thuộc nhóm trọng yếu.
        // Nguyên liệu trọng yếu nhưng vẫn còn đủ → không ẩn (bếp vẫn nấu được).
        $criticalShortage = false;
        if ($shortageIngredientIds !== []) {
            $criticalShortage = DB::table('menu_item_ingredient as r')
                ->where('r.menu_item_id', $menuItemId)
                ->where('r.availability_required', true)
                ->whereIn('r.ingredient_id', $shortageIngredientIds)
                ->exists();
        }
        if (!$criticalShortage) return false;

        $menu->forceFill([
            'is_available'     => false,
            'sold_out_reason'  => 'Hết nguyên liệu: '.$reason,
            'sold_out_at'      => now(),
        ])->save();

        $this->notifyManagersAboutHiddenDish((string) $menu->name, $reason);

        return true;
    }

    /**
     * Tính lượng nguyên liệu cần cho 1 món, theo thứ tự ưu tiên:
     *  1. recipe_snapshot (đã chụp lúc bếp bắt đầu nấu) — nguồn chính xác nhất.
     *  2. Công thức hiện tại trong menu_item_ingredient — fallback khi chưa chụp snapshot.
     *
     * @return array<int, float>|null  null = không xác định được (thiếu công thức / lệch đơn vị)
     */
    private function requiredQuantity(OrderItem $item): ?array
    {
        $snapshot = $item->recipe_snapshot;
        if (is_array($snapshot) && $snapshot !== []) {
            $required = [];
            foreach ($snapshot as $line) {
                if (empty($line['ingredient_id'])) continue;
                if (($line['comparable'] ?? true) === false) return null; // lệch đơn vị → không so được
                $id = (int) $line['ingredient_id'];
                $required[$id] = ($required[$id] ?? 0) + (float) $line['quantity'];
            }
            return $required === [] ? null : $required;
        }

        // Fallback: chưa chụp snapshot → đọc công thức hiện tại.
        $parts = [['menu_item_id' => (int) $item->menu_item_id, 'multiplier' => (int) $item->quantity]];
        foreach (DB::table('order_item_toppings')->where('order_item_id', $item->id)->get() as $topping) {
            $parts[] = [
                'menu_item_id' => (int) $topping->topping_menu_item_id,
                'multiplier'   => (int) $item->quantity * (int) $topping->quantity,
            ];
        }

        $required = [];
        $found = false;
        foreach ($parts as $part) {
            $rows = DB::table('menu_item_ingredient as r')
                ->join('ingredients as i', 'i.id', '=', 'r.ingredient_id')
                ->where('r.menu_item_id', $part['menu_item_id'])
                ->select('r.ingredient_id', 'r.quantity_required', 'r.waste_factor', 'r.unit_override', 'i.unit')
                ->get();
            if ($rows->isEmpty()) continue;
            $found = true;
            foreach ($rows as $row) {
                if ($row->unit_override !== null && $row->unit_override !== $row->unit) return null;
                $id = (int) $row->ingredient_id;
                $required[$id] = ($required[$id] ?? 0)
                    + (float) $row->quantity_required * (float) $row->waste_factor * $part['multiplier'];
            }
        }

        return $found ? $required : null;
    }

    /**
     * Tạo yêu cầu nhập kho từ bếp + báo MANAGER.
     */
    private function createStockRequest(OrderItem $item, array $shortages, User $actor): ?StockRequest
    {
        $code = $this->nextRequestCode();
        $request = StockRequest::create([
            'code'            => $code,
            'menu_item_id'    => (int) $item->menu_item_id,
            'menu_item_name'  => (string) $item->item_name,
            'requested_by'    => $actor->id,
            'status'          => StockRequest::STATUS_OPEN,
            'note'            => sprintf('Bếp báo thiếu nguyên liệu khi bắt đầu nấu món %s.', $item->item_name),
        ]);

        foreach ($shortages as $shortage) {
            StockRequestItem::create([
                'stock_request_id' => $request->id,
                'ingredient_id'    => $shortage['ingredient_id'],
                'required_qty'     => $shortage['required'],
                'available_qty'    => $shortage['available'],
                'shortage_qty'     => $shortage['shortage'],
                'unit'             => $shortage['unit'],
            ]);
        }

        $this->notifyManagers($request, $shortages, $actor);

        return $request;
    }

    /** Thông báo cho các quản lý đang hoạt động. Dùng deduplication_key để không spam. */
    private function notifyManagers(StockRequest $request, array $shortages, User $actor): void
    {
        $summary = collect($shortages)
            ->map(fn ($s) => sprintf('%s thiếu %s %s', $s['ingredient'], number_format($s['shortage'], 2, ',', '.'), $s['unit']))
            ->implode('; ');

        $this->sendManagerNotification(
            'STOCK_REQUEST_CREATED',
            'STOCK_REQUEST:'.$request->id,
            sprintf('Bếp cần nhập %s — %s', $request->menu_item_name, $summary),
            '/staff/manager/stock-requests',
            $request->code,
        );
    }

    /** Bếp hủy món vì hết nguyên liệu → báo quản lý biết món đã bị ẩn. */
    private function notifyManagersAboutHiddenDish(string $dishName, string $reason): void
    {
        $this->sendManagerNotification(
            'DISH_HIDDEN_OUT_OF_STOCK',
            'DISH_HIDDEN:'.md5($dishName.$reason),
            sprintf('Món %s đã bị ẩn khỏi thực đơn vì hết nguyên liệu. Lý do: %s', $dishName, $reason),
            '/staff/manager/consumption',
            $dishName,
        );
    }

    /**
     * Gửi 1 thông báo cho mọi quản lý đang hoạt động.
     * deduplication_key giữ cho cùng 1 sự kiện không gửi trùng.
     */
    private function sendManagerNotification(string $event, string $keyPrefix, string $message, string $url, string $code): void
    {
        $managerIds = User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('code', 'MANAGER'))
            ->pluck('id');

        $data = [
            'event'      => $event,
            'order_id'   => null,
            'order_code' => $code,
            'message'    => $message,
            'url'        => $url,
        ];

        foreach ($managerIds as $managerId) {
            $notificationId = (string) Str::uuid();
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id'                => $notificationId,
                'type'              => 'staff.stock',
                'notifiable_type'   => User::class,
                'notifiable_id'     => $managerId,
                'data'              => json_encode($data, JSON_THROW_ON_ERROR),
                'deduplication_key' => $keyPrefix.':'.$managerId,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            if ($inserted) {
                \App\Events\StaffNotificationCreated::dispatch((int) $managerId, [
                    'id'         => $notificationId,
                    'data'       => $data,
                    'read_at'    => null,
                    'created_at' => now()->toIso8601String(),
                ]);
            }
        }
    }

    private function nextRequestCode(): string
    {
        $today = now('Asia/Ho_Chi_Minh')->format('Ymd');
        $prefix = "YC-{$today}-";
        $latest = StockRequest::where('code', 'like', $prefix.'%')->orderByDesc('id')->value('code');
        $next = 1;
        if ($latest && preg_match('/(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
