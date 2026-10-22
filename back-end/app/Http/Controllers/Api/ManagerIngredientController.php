<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertIngredientRequest;
use App\Services\IngredientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\{Ingredient, IngredientCategory, IngredientPackaging};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ManagerIngredientController
 *
 *  Endpoint CRUD đầy đủ cho nguyên liệu. CHỈ dành cho MANAGER (route middleware).
 *  Phase này KHÔNG kèm auto-deduct kho. Các thao tác nhập/xuất/điều chỉnh tồn vẫn
 *  dùng ManagerInventoryController (vốn có sẵn).
 */
class ManagerIngredientController extends Controller
{
    public function __construct(private readonly IngredientService $service)
    {
    }

    /** GET /api/staff/manager/ingredients */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q'           => ['nullable', 'string', 'max:100'],
            'is_active'   => ['nullable', 'boolean'],
            'low_stock'   => ['nullable', 'boolean'],
            'supplier_id' => ['nullable', 'integer'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $payload = $this->service->paginate($filters);
        return response()->json([
            'success' => true,
            'data'    => $payload['data'],
            'meta'    => $payload['meta'],
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => IngredientCategory::withCount('ingredients')
            ->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function saveCategory(Request $request, ?int $id = null): JsonResponse
    {
        $category = $id ? IngredientCategory::findOrFail($id) : new IngredientCategory;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('ingredient_categories', 'name')->ignore($id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category->fill($data)->save();
        return response()->json(['data' => $category->fresh()->loadCount('ingredients')], $id ? 200 : 201);
    }

    public function savePackaging(Request $request, int $ingredientId, ?int $id = null): JsonResponse
    {
        $ingredient = Ingredient::findOrFail($ingredientId);
        $packaging = $id ? IngredientPackaging::where('ingredient_id', $ingredient->id)->findOrFail($id) : new IngredientPackaging;
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchase_unit' => ['required', 'string', 'max:30'],
            'conversion_quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($ingredient, $packaging, $data) {
            if ($data['is_default'] ?? false) IngredientPackaging::where('ingredient_id', $ingredient->id)->update(['is_default' => false]);
            $packaging->fill($data + ['ingredient_id' => $ingredient->id])->save();
        });
        return response()->json(['data' => $this->service->find($ingredient->id)], $id ? 200 : 201);
    }

    /** POST /api/staff/manager/ingredients */
    public function store(UpsertIngredientRequest $request): JsonResponse
    {
        $payload = $this->service->create($request->validated());
        return response()->json(['success' => true, 'data' => $payload], 201);
    }

    /** GET /api/staff/manager/ingredients/{id} */
    public function show(int $id): JsonResponse
    {
        $payload = $this->service->find($id);
        return response()->json(['success' => true, 'data' => $payload]);
    }

    /** PATCH /api/staff/manager/ingredients/{id} */
    public function update(UpsertIngredientRequest $request, int $id): JsonResponse
    {
        $payload = $this->service->update($id, $request->validated());
        return response()->json(['success' => true, 'data' => $payload]);
    }

    /**
     * GET /api/staff/manager/ingredients/{id}/blocking-references
     *
     * Trả về danh sách các món đang dùng nguyên liệu này (để hiển thị cảnh báo trước khi deactivate).
     */
    public function blockingReferences(int $id): JsonResponse
    {
        $refs = $this->service->blockingReferences($id);
        return response()->json([
            'success' => true,
            'data'    => $refs,
            'can_deactivate' => $refs === [],
        ]);
    }
}
