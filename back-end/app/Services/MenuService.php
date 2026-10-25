<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MenuService - cung cấp dữ liệu cho trang thực đơn.
 *
 * Hỗ trợ filter đa chiều:
 *  - category (slug)
 *  - search (tên món)
 *  - spicy_level (0..5)
 *  - size (SMALL | LARGE)
 *  - min_price, max_price
 *  - sort (price_asc | price_desc | name_asc | name_desc | newest)
 */
class MenuService
{
    /**
     * Danh sách món yêu thích còn hiển thị trên thực đơn của một khách hàng.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFavorites(User $user): array
    {
        $availability = app(MenuAvailabilityService::class);

        return DB::table('user_favorite_menu_items as f')
            ->join('menu_items as m', 'm.id', '=', 'f.menu_item_id')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('f.user_id', $user->id)
            ->where('m.is_active', true)
            ->where('c.is_active', true)
            ->orderByDesc('f.created_at')
            ->get([
                'm.id', 'm.name', 'm.slug', 'm.description', 'm.image_url',
                'm.price', 'm.spicy_level', 'm.size', 'm.preparation_minutes',
                'm.is_available', 'm.sold_out_reason', 'm.sold_out_at',
                'm.auto_availability_enabled',
                'c.name as category_name', 'c.slug as category_slug',
            ])
            ->map(function ($row) use ($availability) {
                $status = $availability->status($row);

                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'image_url' => $row->image_url ?: $this->placeholderImage($row->category_slug),
                    'price' => (float) $row->price,
                    'price_formatted' => number_format((float) $row->price, 0, ',', '.') . ' đ',
                    'spicy_level' => (int) $row->spicy_level,
                    'size' => $row->size,
                    'preparation_minutes' => (int) $row->preparation_minutes,
                    'is_available' => $status['is_available'],
                    'availability_reason' => $status['reason'],
                    'sold_out_reason' => $row->sold_out_reason,
                    'sold_out_at' => $row->sold_out_at,
                    'category_name' => $row->category_name,
                    'category_slug' => $row->category_slug,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lấy danh sách món ăn đã được lọc + sắp xếp, kèm metadata phân trang.
     *
     * @return array<string, mixed>
     */
    public function listItems(Request $request): array
    {
        $category = $request->query('category');                  // slug hoặc null
        $search   = trim((string) $request->query('search', ''));
        $spicy    = $request->query('spicy_level');                // 0..5
        $size     = strtoupper((string) $request->query('size', ''));
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');
        $sort     = $request->query('sort', 'default');
        $perPage  = max(1, min((int) $request->query('per_page', 50), 50));
        $page     = max(1, (int) $request->query('page', 1));

        $q = DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)
            ->where('c.is_active', true);

        // Lọc theo slug danh mục
        if ($category !== null && $category !== '' && $category !== 'all') {
            $q->where('c.slug', $category);
        }

        // Tìm kiếm theo tên + mô tả
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $like = '%' . $search . '%';
                $w->where('m.name', 'like', $like)
                  ->orWhere('m.description', 'like', $like);
            });
        }

        // Lọc theo cấp độ cay (nullable => bỏ qua)
        if ($spicy !== null && $spicy !== '') {
            $q->where('m.spicy_level', (int) $spicy);
        }

        // Lọc theo size
        if (in_array($size, ['SMALL', 'LARGE'], true)) {
            $q->where('m.size', $size);
        }

        // Lọc theo khoảng giá
        if ($minPrice !== null && $minPrice !== '') {
            $q->where('m.price', '>=', (float) $minPrice);
        }
        if ($maxPrice !== null && $maxPrice !== '') {
            $q->where('m.price', '<=', (float) $maxPrice);
        }

        // Sắp xếp — whitelist để chống SQL injection qua sort key
        match ($sort) {
            'price_asc'  => $q->orderBy('m.price', 'asc'),
            'price_desc' => $q->orderBy('m.price', 'desc'),
            'name_asc'   => $q->orderBy('m.name', 'asc'),
            'name_desc'  => $q->orderBy('m.name', 'desc'),
            'newest'     => $q->orderByDesc('m.id'),
            default      => $q->orderBy('c.sort_order')
                              ->orderBy('m.id'),
        };

        // Stable tie-breaker for price/name sorts, before taking the page slice.
        $q->orderBy('m.id');
        $total = (clone $q)->count('m.id');
        $q->forPage($page, $perPage);

        $availability = app(MenuAvailabilityService::class);
        $rows = $q
            ->get([
                'm.id',
                'm.name',
                'm.slug',
                'm.description',
                'm.image_url',
                'm.price',
                'm.spicy_level',
                'm.size',
                'm.preparation_minutes',
                'm.is_available',
                'm.sold_out_reason',
                'm.sold_out_at',
                'm.auto_availability_enabled',
                'c.name as category_name',
                'c.slug as category_slug',
            ]);

        // One query for the visible page instead of one HTTP request per card.
        $ratings = DB::table('reviews')
            ->whereIn('menu_item_id', $rows->pluck('id'))
            ->where('is_approved', true)
            ->where('is_hidden', false)
            ->selectRaw('menu_item_id, COUNT(*) as review_count, AVG(rating) as average')
            ->groupBy('menu_item_id')
            ->get()
            ->keyBy('menu_item_id');

        $rows = $rows->map(function ($row) use ($availability, $ratings) {
                $status = $availability->status($row);
                $rating = $ratings->get($row->id);
                return [
                'rating_summary'      => [
                    'count' => (int) ($rating->review_count ?? 0),
                    'average' => round((float) ($rating->average ?? 0), 2),
                ],
                'id'                  => (int) $row->id,
                'name'                => $row->name,
                'slug'                => $row->slug,
                'description'         => $row->description,
                'image_url'           => $row->image_url ?: $this->placeholderImage($row->category_slug),
                'price'               => (float) $row->price,
                'price_formatted'     => number_format((float) $row->price, 0, ',', '.') . ' đ',
                'spicy_level'         => (int) $row->spicy_level,
                'size'                => $row->size,
                'preparation_minutes' => (int) $row->preparation_minutes,
                'is_available'        => $status['is_available'],
                'availability_reason' => $status['reason'],
                'sold_out_reason'     => $row->sold_out_reason,
                'sold_out_at'         => $row->sold_out_at,
                'category_name'       => $row->category_name,
                'category_slug'       => $row->category_slug,
                ];
            })
            ->values()
            ->all();

        // Gợi ý combo (Phase 3) — 1 query cho cả trang.
        $suggestions = app(MenuSuggestionService::class)->forMenuItems(array_column($rows, 'id'));
        $rows = array_map(function (array $row) use ($suggestions) {
            $row['suggestions'] = $suggestions[$row['id']] ?? [];
            return $row;
        }, $rows);

        return [
            'items'      => $rows,
            'count'      => count($rows),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters'    => [
                'category'   => $category,
                'search'     => $search,
                'spicy_level' => $spicy,
                'size'       => $size,
                'min_price'  => $minPrice,
                'max_price'  => $maxPrice,
                'sort'       => $sort,
            ],
        ];
    }

    /**
     * Chi tiết 1 món theo slug (dùng cho trang chi tiết sau này nếu cần).
     *
     * @return array<string, mixed>|null
     */
    public function getItemBySlug(string $slug): ?array
    {
        $row = DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.slug', $slug)
            ->where('m.is_active', true)
            ->first([
                'm.id', 'm.name', 'm.slug', 'm.description', 'm.image_url',
                'm.price', 'm.spicy_level', 'm.size', 'm.preparation_minutes',
                'm.is_available', 'm.sold_out_reason', 'm.sold_out_at',
                'm.auto_availability_enabled',
                'c.name as category_name', 'c.slug as category_slug',
            ]);

        if (!$row) return null;

        return [
            'id'                  => (int) $row->id,
            'name'                => $row->name,
            'slug'                => $row->slug,
            'description'         => $row->description,
            'image_url'           => $row->image_url ?: $this->placeholderImage($row->category_slug),
            'price'               => (float) $row->price,
            'price_formatted'     => number_format((float) $row->price, 0, ',', '.') . ' đ',
            'spicy_level'         => (int) $row->spicy_level,
            'size'                => $row->size,
            'preparation_minutes' => (int) $row->preparation_minutes,
            'is_available'        => app(MenuAvailabilityService::class)->status($row)['is_available'],
            'sold_out_reason'     => $row->sold_out_reason,
            'sold_out_at'         => $row->sold_out_at,
            'category_name'       => $row->category_name,
            'category_slug'       => $row->category_slug,
            'suggestions' => app(MenuSuggestionService::class)->forMenuItem((int) $row->id),
        ];
    }

    /**
     * Ảnh placeholder theo slug danh mục (giống HomeService).
     */
    private function placeholderImage(string $categorySlug): string
    {
        $map = [
            'mi-cay-han-quoc' => 'menu/mi-cay-han-quoc.jpg',
            'topping-them'    => 'menu/topping-them.jpg',
            'mon-phu'         => 'menu/mon-phu.jpg',
            'trang-mieng'     => 'menu/trang-mieng.jpg',
            'do-uong'         => 'menu/do-uong.jpg',
        ];

        $relative = $map[$categorySlug] ?? 'menu/default.jpg';
        return asset('storage/' . $relative);
    }
}
