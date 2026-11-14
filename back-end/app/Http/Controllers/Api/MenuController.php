<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\FlashSaleService;
use App\Services\MenuAvailabilityService;

class MenuController extends Controller
{
    public function __construct(private readonly MenuService $menuService)
    {
    }

    /**
     * GET /api/menu
     * Query params: category, search, spicy_level, size, min_price, max_price, sort
     */
    public function index(Request $request): JsonResponse
    {
        $payload = $this->menuService->listItems($request);

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách thực đơn thành công',
            'data'    => $payload['items'],
            'meta'    => [
                'count'   => $payload['count'],
                'filters' => $payload['filters'],
                ...$payload['pagination'],
            ],
        ]);
    }

    public function flashSales(): JsonResponse
    {
        $now = now();
        $availability = app(MenuAvailabilityService::class);
        $sales = app(FlashSaleService::class);
        $items = DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)->where('c.is_active', true)
            ->where('m.is_available', true)
            ->where('m.flash_sale_discount', '>', 0)
            ->whereColumn('m.flash_sale_discount', '<', 'm.price')
            ->where('m.flash_sale_starts_at', '<=', $now)
            ->where('m.flash_sale_ends_at', '>', $now)
            ->orderBy('m.flash_sale_ends_at')->orderBy('m.id')
            ->get(['m.id', 'm.name', 'm.slug', 'm.price', 'm.flash_sale_discount',
                'm.flash_sale_starts_at', 'm.flash_sale_ends_at', 'm.is_available',
                'm.auto_availability_enabled'])
            ->filter(fn ($item) => $availability->status($item)['is_available'])
            ->map(fn ($item) => [
                'name' => $item->name,
                'slug' => $item->slug,
                'flash_sale' => $sales->activeFor($item, $now),
            ])->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * GET /api/menu/{slug}
     * Chi tiết 1 món theo slug.
     */
    public function show(string $slug): JsonResponse
    {
        $item = $this->menuService->getItemBySlug($slug);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy món ăn',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $item,
        ]);
    }
}
