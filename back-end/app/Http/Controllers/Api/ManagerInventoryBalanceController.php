<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ManagerInventoryBalanceController
 *
 *  GET /api/staff/manager/inventory-balance
 *      ?only=critical|all (mặc định all)
 *      ?ingredient_id=... (lọc 1 nguyên liệu)
 *
 *  Trả về 3 con số: actual_stock / expected_usage_qty / reserved_qty
 *  + estimated_available (= actual - usage - reserved, null nếu không đủ dữ liệu).
 *  Phase E dùng để manager xem vận hành, KHÔNG dùng để chặn (auto chặn đã có ở Phase D).
 */
class ManagerInventoryBalanceController extends Controller
{
    public function __construct(private readonly InventoryBalanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $ingredientId = $request->query('ingredient_id');
        if ($ingredientId !== null) {
            $id = (int) $ingredientId;
            $row = $this->service->forIngredient($id);
            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        }
        return response()->json([
            'success' => true,
            'data' => $this->service->forAll(),
        ]);
    }
}