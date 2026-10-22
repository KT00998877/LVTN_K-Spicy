<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Category, MenuItem};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\MenuAvailabilityService;

class ManagerMenuController extends Controller
{
    private function item(MenuItem $item): array
    {
        return array_merge($item->toArray(), ['price' => (float) $item->price, 'cost_price' => (float) $item->cost_price,
            'category' => $item->category?->name]);
    }

    public function index()
    {
        return response()->json(['data' => MenuItem::with('category')->orderBy('category_id')->orderBy('name')->get()->map(fn ($item) => $this->item($item))]);
    }

    public function save(Request $request, ?int $id = null)
    {
        $item = $id ? MenuItem::findOrFail($id) : new MenuItem;
        $required = $id ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'category_id' => [$required, 'integer', 'exists:categories,id'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['nullable', 'string', 'max:255', 'regex:~^(https?://|/storage/)~i'],
            'is_available' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'sold_out_reason' => ['nullable', 'string', 'max:255'],
            'preparation_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'spicy_level' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'size' => ['nullable', Rule::in(['SMALL', 'LARGE'])],
        ]);
        if (!$id) $data['slug'] = Str::slug($data['name']) . '-' . Str::lower(Str::random(10));
        if (array_key_exists('is_available', $data)) {
            $this->recordAvailability($item, (bool) $data['is_available'], $data['sold_out_reason'] ?? null, $request);
        }
        unset($data['sold_out_reason']);
        $item->fill($data)->save();
        return response()->json(['data' => $this->item($item->fresh('category'))], $id ? 200 : 201);
    }

    public function setAvailability(Request $request, int $id)
    {
        $data = $request->validate([
            'is_available' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $item = MenuItem::findOrFail($id);
        $this->recordAvailability($item, (bool) $data['is_available'], $data['reason'] ?? null, $request);
        $item->save();

        return response()->json(['data' => $this->item($item->load('category'))]);
    }

    public function availabilityIndex()
    {
        $availability = new MenuAvailabilityService;
        return response()->json(['data' => MenuItem::with('category')
            ->where('is_active', true)->orderBy('category_id')->orderBy('name')->get()
            ->map(fn (MenuItem $item) => array_merge($this->item($item), [
                'effective_availability' => $availability->status($item),
            ]))]);
    }

    public function setAutoAvailability(Request $request, int $id)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $item = MenuItem::findOrFail($id);
        if ($data['enabled']) {
            $keyLines = $item->recipes()->with('ingredient')->where('availability_required', true)->get();
            $valid = $item->recipe_managed_at !== null && $keyLines->isNotEmpty()
                && $keyLines->every(fn ($line) => $line->ingredient?->is_active
                    && ($line->unit_override === null || $line->unit_override === $line->ingredient->unit));
            if (!$valid) return response()->json(['message' => 'Cần lưu công thức có nguyên liệu trọng yếu trước khi bật tự báo hết.'], 422);
            if (!DB::table('daily_stock_sheets')->where('status', 'CLOSED')->exists()) {
                return response()->json(['message' => 'Cần chốt sổ kho đầu tiên trước khi bật tự báo hết.'], 422);
            }
            $probe = clone $item;
            $probe->auto_availability_enabled = true;
            $probe->is_available = true;
            if ((new MenuAvailabilityService)->status($probe)['reason'] === 'insufficient_data') {
                return response()->json(['message' => 'Dữ liệu tồn hoặc công thức chưa đủ để bật tự báo hết. Hãy đối chiếu sổ kho và đơn đang chờ.'], 422);
            }
        }
        $item->auto_availability_enabled = (bool) $data['enabled'];
        $item->save();
        return response()->json(['data' => $this->item($item->load('category'))]);
    }

    private function recordAvailability(MenuItem $item, bool $available, ?string $reason, Request $request): void
    {
        if ($item->exists && (bool) $item->is_available === $available) return;
        $item->is_available = $available;
        $item->sold_out_reason = $available ? null : (trim((string) $reason) ?: 'Báo hết thủ công');
        $item->sold_out_at = $available ? null : now();
        $item->sold_out_by = $available ? null : $request->user()?->id;
    }

    public function categories()
    {
        return response()->json(['data' => Category::withCount('menuItems')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function saveCategory(Request $request, ?int $id = null)
    {
        $category = $id ? Category::findOrFail($id) : new Category;
        $data = $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (!$id) $data['slug'] = Str::slug($data['name']) . '-' . Str::lower(Str::random(10));
        $category->fill($data)->save();
        return response()->json(['data' => $category->fresh()->loadCount('menuItems')], $id ? 200 : 201);
    }

    public function upload(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = $request->file('image')->store('menu', 'public');
        return response()->json(['data' => ['url' => asset('storage/' . $path)]], 201);
    }
}
