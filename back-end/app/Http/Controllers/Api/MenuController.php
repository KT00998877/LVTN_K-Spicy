<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
