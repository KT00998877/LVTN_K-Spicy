<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceRecipeRequest;
use App\Services\RecipeService;
use Illuminate\Http\JsonResponse;

/**
 * ManagerRecipeController
 *
 *  GET    /api/staff/manager/menu-items/{id}/recipe
 *  PUT    /api/staff/manager/menu-items/{id}/recipe
 *
 *  Endpoint đọc / thay thế công thức của 1 món ăn (đã bao gồm cả tính food cost).
 */
class ManagerRecipeController extends Controller
{
    public function __construct(private readonly RecipeService $service)
    {
    }

    /** GET /api/staff/manager/menu-items/{menuItem}/recipe */
    public function show(int $menuItem): JsonResponse
    {
        $data = $this->service->get($menuItem);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** PUT /api/staff/manager/menu-items/{menuItem}/recipe */
    public function replace(ReplaceRecipeRequest $request, int $menuItem): JsonResponse
    {
        $data = $this->service->replace($menuItem, $request->validated()['lines']);
        return response()->json(['success' => true, 'data' => $data]);
    }
}
