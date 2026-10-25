<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteMenuItemController extends Controller
{
    public function __construct(private readonly MenuService $menuService) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->menuService->listFavorites($request->user()),
        ]);
    }

    public function store(Request $request, MenuItem $menuItem): JsonResponse
    {
        abort_unless($menuItem->is_active && $menuItem->category?->is_active, 404);

        $request->user()->favoriteMenuItems()->syncWithoutDetaching([$menuItem->id]);

        return response()->json([
            'success' => true,
            'message' => 'Đã thêm món vào danh sách yêu thích.',
        ]);
    }

    public function destroy(Request $request, MenuItem $menuItem): JsonResponse
    {
        $request->user()->favoriteMenuItems()->detach($menuItem->id);

        return response()->json([
            'success' => true,
            'message' => 'Đã bỏ món khỏi danh sách yêu thích.',
        ]);
    }
}
