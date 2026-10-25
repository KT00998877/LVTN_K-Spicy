<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuItemSuggestion;
use App\Services\MenuSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ManagerMenuSuggestionController — CRUD gợi ý combo cho MANAGER (Phase 3).
 *
 * Endpoint:
 *  - GET    /api/staff/manager/menu-suggestions          — danh sách
 *  - POST   /api/staff/manager/menu-suggestions          — tạo
 *  - PATCH  /api/staff/manager/menu-suggestions/{id}     — sửa
 *  - DELETE /api/staff/manager/menu-suggestions/{id}     — xoá
 *  - GET    /api/staff/manager/menu-suggestions/options  — danh sách món để chọn
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 */
class ManagerMenuSuggestionController extends Controller
{
    public function __construct(private readonly MenuSuggestionService $suggestions) {}

    /* ============================================================ */
    /* LIST                                                         */
    /* ============================================================ */

    public function index(Request $request): JsonResponse
    {
        $query = MenuItemSuggestion::query()
            ->with(['menuItem:id,name,price,category_id', 'suggestedItem:id,name,price,category_id'])
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('menuItem', fn ($m) => $m->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('suggestedItem', fn ($m) => $m->where('name', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($menuItemId = $request->query('menu_item_id')) {
            $query->where('menu_item_id', (int) $menuItemId);
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 50)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (MenuItemSuggestion $s) => $this->format($s))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /* ============================================================ */
    /* OPTIONS — danh sách món để chọn trong form                   */
    /* ============================================================ */

    public function options(): JsonResponse
    {
        $items = MenuItem::query()
            ->with('category:id,name,slug')
            ->where('is_active', true)
            ->orderBy('category_id')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'category_id', 'is_available', 'image_url'])
            ->map(fn (MenuItem $m) => [
                'id'          => (int) $m->id,
                'name'        => $m->name,
                'price'       => (float) $m->price,
                'price_formatted' => number_format((float) $m->price, 0, ',', '.') . ' đ',
                'category_name' => $m->category?->name,
                'category_slug' => $m->category?->slug,
                'image_url'   => $m->image_url,
                'is_available' => (bool) $m->is_available,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /* ============================================================ */
    /* STORE / UPDATE / DELETE                                      */
    /* ============================================================ */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $suggestion = MenuItemSuggestion::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Đã tạo gợi ý combo.',
            'data'    => $this->format($suggestion->fresh(['menuItem', 'suggestedItem'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $suggestion = MenuItemSuggestion::findOrFail($id);
        $suggestion->update($this->validated($request, $suggestion));

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật gợi ý combo.',
            'data'    => $this->format($suggestion->fresh(['menuItem', 'suggestedItem'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        MenuItemSuggestion::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã xoá gợi ý combo.',
        ]);
    }

    /* ============================================================ */
    /* Private                                                      */
    /* ============================================================ */

    /**
     * Validate + chuẩn hoá payload.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MenuItemSuggestion $suggestion = null): array
    {
        $data = $request->validate([
            'menu_item_id'     => ['required', 'integer', 'exists:menu_items,id'],
            'suggested_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'title'    => ['nullable', 'string', 'max:150'],
            'message'  => ['nullable', 'string', 'max:255'],
            'combo_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], [
            'menu_item_id.required'     => 'Vui lòng chọn món chính.',
            'suggested_item_id.required' => 'Vui lòng chọn món gợi ý.',
            'combo_price.min'           => 'Giá combo không được âm.',
        ]);

        if ((int) $data['menu_item_id'] === (int) $data['suggested_item_id']) {
            throw ValidationException::withMessages([
                'suggested_item_id' => 'Món gợi ý phải khác món chính.',
            ]);
        }

        // Không cho 2 gợi ý trùng cặp món chính + món gợi ý.
        $duplicate = MenuItemSuggestion::query()
            ->where('menu_item_id', $data['menu_item_id'])
            ->where('suggested_item_id', $data['suggested_item_id'])
            ->when($suggestion, fn ($q) => $q->where('id', '!=', $suggestion->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'suggested_item_id' => 'Gợi ý này đã tồn tại cho món này.',
            ]);
        }

        // combo_price phải thấp hơn tổng giá 2 món, nếu không thì "combo" không có ý nghĩa.
        if (isset($data['combo_price'])) {
            $prices = MenuItem::whereIn('id', [$data['menu_item_id'], $data['suggested_item_id']])
                ->pluck('price', 'id');
            $total = (float) ($prices[$data['menu_item_id']] ?? 0) + (float) ($prices[$data['suggested_item_id']] ?? 0);
            if ((float) $data['combo_price'] >= $total) {
                throw ValidationException::withMessages([
                    'combo_price' => 'Giá combo phải thấp hơn tổng giá 2 món để khách thấy ưu đãi.',
                ]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MenuItemSuggestion $s): array
    {
        $main = $s->menuItem;
        $side = $s->suggestedItem;
        $original = (int) round((float) ($main?->price ?? 0)) + (int) round((float) ($side?->price ?? 0));
        $comboPrice = $s->combo_price === null ? null : (int) round((float) $s->combo_price);

        return [
            'id'          => (int) $s->id,
            'title'       => $s->title ?: sprintf('Combo %s + %s', $main?->name ?? '?', $side?->name ?? '?'),
            'message'     => $s->message,
            'menu_item_id' => (int) $s->menu_item_id,
            'suggested_item_id' => (int) $s->suggested_item_id,
            'menu_item'   => $main ? ['id' => (int) $main->id, 'name' => $main->name, 'price' => (float) $main->price] : null,
            'suggested_item' => $side ? ['id' => (int) $side->id, 'name' => $side->name, 'price' => (float) $side->price] : null,
            'combo_price' => $comboPrice,
            'combo_price_formatted' => $comboPrice === null ? null : number_format($comboPrice, 0, ',', '.') . ' đ',
            'original_price' => $original,
            'saving'      => $comboPrice === null ? 0 : max(0, $original - $comboPrice),
            'is_active'   => (bool) $s->is_active,
            'sort_order'  => (int) $s->sort_order,
            'created_at'  => optional($s->created_at)->toDateTimeString(),
        ];
    }
}
