<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomeService;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __construct(private readonly HomeService $homeService)
    {
    }

    /**
     * GET /api/home
     * Trả về toàn bộ payload cho trang chủ.
     */
    public function index(): JsonResponse
    {
        $payload = $this->homeService->getHomePayload();

        return response()->json([
            'success' => true,
            'message' => 'Lấy dữ liệu trang chủ thành công',
            'data'    => $payload,
        ]);
    }

    /**
     * GET /api/home/restaurant
     * Chỉ thông tin nhà hàng.
     */
    public function restaurant(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getRestaurantInfo(),
        ]);
    }

    /**
     * GET /api/home/stats
     * Số liệu thống kê.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getStats(),
        ]);
    }

    /**
     * GET /api/home/categories
     * Danh mục kèm số món.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getCategoriesWithCount(),
        ]);
    }

    /**
     * GET /api/home/featured
     * Món nổi bật.
     */
    public function featured(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getFeaturedItems(),
        ]);
    }

    /**
     * GET /api/home/best-sellers
     * Món bán chạy.
     */
    public function bestSellers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getBestSellers(),
        ]);
    }

    /**
     * GET /api/home/tables
     * Tổng quan bàn.
     */
    public function tables(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getTableOverview(),
        ]);
    }

    /**
     * GET /api/home/blogs
     * Bài viết mới nhất.
     */
    public function blogs(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->homeService->getLatestBlogs(),
        ]);
    }
}
