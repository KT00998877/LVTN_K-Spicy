<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{Period, StatisticsService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ManagerStatisticsController — REST endpoints cho thống kê dashboard manager.
 *
 * Tất cả endpoints đều:
 *  - Chỉ dành cho MANAGER (middleware 'staff:MANAGER').
 *  - Hỗ trợ filter period (xem StatisticsService::resolvePeriod).
 *  - Trả JSON theo format thống nhất: { success: true, data: ... }.
 *
 * Filter chung:
 *   ?period=today | yesterday | last_7_days | last_30_days | this_week
 *         | previous_week | this_month | previous_month | this_quarter | this_year
 *         | custom (cần &from=YYYY-MM-DD &to=YYYY-MM-DD)
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD  (chỉ khi period=custom)
 *   ?limit=N (cho top-products / top-ingredients / food-cost)
 */
class ManagerStatisticsController extends Controller
{
    public function __construct(private readonly StatisticsService $stats) {}

    /** GET /api/staff/manager/statistics/overview */
    public function overview(Request $request): JsonResponse
    {
        [$current, $previous] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->overview($current, $previous)]);
    }

    /** GET /api/staff/manager/statistics/revenue */
    public function revenue(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->revenueChart($current)]);
    }

    /** GET /api/staff/manager/statistics/orders */
    public function orders(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->orderStatusBreakdown($current)]);
    }

    /** GET /api/staff/manager/statistics/top-products */
    public function topProducts(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        $limit = (int) $request->query('limit', 10);
        return response()->json(['success' => true, 'data' => $this->stats->topProducts($current, $limit)]);
    }

    /** GET /api/staff/manager/statistics/low-products */
    public function lowProducts(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        $limit = (int) $request->query('limit', 10);
        return response()->json(['success' => true, 'data' => $this->stats->lowSellingProducts($current, $limit)]);
    }

    /** GET /api/staff/manager/statistics/categories */
    public function categories(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->categoryStats($current)]);
    }

    /** GET /api/staff/manager/statistics/payments */
    public function payments(Request $request): JsonResponse
    {
        [$current, $previous] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->paymentStats($current, $previous)]);
    }

    /** GET /api/staff/manager/statistics/customers */
    public function customers(Request $request): JsonResponse
    {
        [$current, $previous] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->customerStats($current, $previous)]);
    }

    /** GET /api/staff/manager/statistics/reservations */
    public function reservations(Request $request): JsonResponse
    {
        [$current, $previous] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->reservationStats($current, $previous)]);
    }

    /** GET /api/staff/manager/statistics/peak-hours */
    public function peakHours(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->peakHours($current)]);
    }

    /** GET /api/staff/manager/statistics/inventory */
    public function inventory(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->inventoryStats($current)]);
    }

    /** GET /api/staff/manager/statistics/top-ingredients */
    public function topIngredients(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        $limit = (int) $request->query('limit', 10);
        return response()->json(['success' => true, 'data' => $this->stats->topConsumedIngredients($current, $limit)]);
    }

    /** GET /api/staff/manager/statistics/food-cost */
    public function foodCost(Request $request): JsonResponse
    {
        [$current] = StatisticsService::resolvePeriod($request->all());
        return response()->json(['success' => true, 'data' => $this->stats->foodCost($current)]);
    }
}
