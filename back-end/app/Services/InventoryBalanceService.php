<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * InventoryBalanceService — tách 3 con số cho manager xem tồn dự kiến:
 *
 *   1) actual_stock        : số bếp chốt gần nhất (quantity_in_stock hiện tại)
 *   2) expected_usage_qty : lượng dự kiến đã dùng theo ảnh chụp công thức
 *                           (ExpectedUsageService::between)
 *   3) reserved_qty       : lượng giữ chỗ từ đơn chưa bắt đầu nấu
 *                           (MenuAvailabilityService::pendingUsage)
 *   4) estimated_available = actual - expected_usage - reserved
 *
 * Phase E — hiển thị vận hành, KHÔNG dùng để chặn (auto đã chặn ở Phase D).
 * Tất cả lệch đơn vị / thiếu mốc chốt đều được trả về null hoặc 0 để manager
 * biết nguyên nhân, không tự kết luận.
 */
class InventoryBalanceService
{
    public function __construct(
        private readonly ExpectedUsageService $expected,
        private readonly MenuAvailabilityService $availability,
    ) {
    }

    /**
     * Tính cho 1 ingredient tại thời điểm $now (mặc định now()).
     * Trả null cho estimated_available nếu không đủ dữ liệu để kết luận.
     *
     * @return array{
     *   ingredient_id:int, code:string, name:string, unit:string, is_active:bool,
     *   actual_stock:float,
     *   expected_usage_qty:float,
     *   reserved_qty:float,
     *   estimated_available:?float,
     *   data_status: 'ready'|'missing_snapshot'|'uncomparable'|'insufficient_data',
     *   since_closed_at:?string
     * }
     */
    public function forIngredient(int $ingredientId, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();
        $ingredient = Ingredient::find($ingredientId);
        if (!$ingredient) return null;

        $lastSheet = DB::table('daily_stock_sheets')->where('status', 'CLOSED')
            ->orderByDesc('closed_at')->first();
        $since = $lastSheet?->closed_at;
        $actual = (float) $ingredient->quantity_in_stock;

        // Lượng đã nấu nhưng CHƯA được trừ kho (món nấu trước Phase F).
        // Từ Phase F, món đã bắt đầu nấu thì tồn đã bị trừ thật → không trừ lần 2.
        $used = $this->expected->undeductedBetween($since, $now, false);
        $row = $used['by_ingredient'][$ingredientId] ?? ['quantity' => 0.0, 'uncomparable_items' => 0];
        $expectedUsage = (float) $row['quantity'];

        // Giữ chỗ đơn chưa nấu. pendingUsage có thể trả null nếu thiếu công thức / lệch đơn vị.
        $reservedMap = $this->availability->pendingUsage();
        $reserved = (float) ($reservedMap[$ingredientId] ?? 0);

        $status = 'ready';
        $estimated = $actual - $expectedUsage - $reserved;
        if (!$since) {
            $status = 'insufficient_data';
            $estimated = null;
        } elseif ($used['missing_recipe_items'] > 0 || $used['legacy_items'] > 0) {
            // Có món đã bắt đầu nấu nhưng thiếu ảnh chụp công thức — không kết luận.
            $status = 'missing_snapshot';
            $estimated = null;
        } elseif ($row['uncomparable_items'] > 0 || $reservedMap === null) {
            $status = 'uncomparable';
            $estimated = null;
        }

        return [
            'ingredient_id' => (int) $ingredient->id,
            'code' => (string) $ingredient->code,
            'name' => (string) $ingredient->name,
            'unit' => (string) $ingredient->unit,
            'is_active' => (bool) $ingredient->is_active,
            'actual_stock' => $actual,
            'expected_usage_qty' => $expectedUsage,
            'reserved_qty' => $reserved,
            'estimated_available' => $estimated,
            'data_status' => $status,
            'since_closed_at' => $since ? Carbon::parse($since)->toIso8601String() : null,
        ];
    }

    /**
     * Tính cho nhiều nguyên liệu (1 query cho toàn bộ).
     * Chỉ trả về các nguyên liệu có dữ liệu dùng hoặc giữ chỗ hoặc đang active.
     *
     * @return array<int, array>  mỗi phần tử là cấu trúc giống forIngredient()
     */
    public function forAll(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $lastSheet = DB::table('daily_stock_sheets')->where('status', 'CLOSED')
            ->orderByDesc('closed_at')->first();
        $since = $lastSheet?->closed_at;

        $ingredients = Ingredient::query()->orderBy('name')->get();
        if ($ingredients->isEmpty()) return [];

        // Từ Phase F, món đã bắt đầu nấu thì tồn đã bị trừ thật → chỉ trừ thêm
        // lượng của các món CHƯA được trừ (nấu trước Phase F).
        $used = $this->expected->undeductedBetween($since, $now, false);
        $reservedMap = $this->availability->pendingUsage();
        $missingSnapshot = $used['missing_recipe_items'] > 0 || $used['legacy_items'] > 0;

        $out = [];
        foreach ($ingredients as $ingredient) {
            $id = (int) $ingredient->id;
            $row = $used['by_ingredient'][$id] ?? ['quantity' => 0.0, 'uncomparable_items' => 0];
            $expectedUsage = (float) $row['quantity'];
            $reserved = (float) ($reservedMap[$id] ?? 0);
            $actual = (float) $ingredient->quantity_in_stock;

            $status = 'ready';
            $estimated = $actual - $expectedUsage - $reserved;
            if (!$since) {
                $status = 'insufficient_data';
                $estimated = null;
            } elseif ($row['uncomparable_items'] > 0 || $reservedMap === null) {
                $status = 'uncomparable';
                $estimated = null;
            }

            // Bỏ qua nguyên liệu không hoạt động và không có usage/reserved gì để tránh nhiễu.
            if (!$ingredient->is_active && $expectedUsage === 0.0 && $reserved === 0.0) continue;

            $out[] = [
                'ingredient_id' => $id,
                'code' => (string) $ingredient->code,
                'name' => (string) $ingredient->name,
                'unit' => (string) $ingredient->unit,
                'is_active' => (bool) $ingredient->is_active,
                'actual_stock' => $actual,
                'expected_usage_qty' => $expectedUsage,
                'reserved_qty' => $reserved,
                'estimated_available' => $estimated,
                'data_status' => $status,
                'since_closed_at' => $since ? Carbon::parse($since)->toIso8601String() : null,
            ];
        }
        return $out;
    }
}