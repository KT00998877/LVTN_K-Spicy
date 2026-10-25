<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * HomeService - cung cấp dữ liệu tổng hợp cho trang chủ.
 * Mục tiêu: chỉ đọc dữ liệu đã được seed sẵn, trả về cấu trúc gọn cho FE.
 */
class HomeService
{
    /**
     * Lấy toàn bộ payload cần thiết cho trang chủ trong 1 lần gọi.
     *
     * @return array<string, mixed>
     */
    public function getHomePayload(): array
    {
        return [
            'restaurant'   => $this->getRestaurantInfo(),
            'stats'        => $this->getStats(),
            'categories'   => $this->getCategoriesWithCount(),
            'featured'     => $this->getFeaturedItems(),
            'best_sellers' => $this->getBestSellers(),
            'tables'       => $this->getTableOverview(),
            'blogs'        => $this->getLatestBlogs(),
        ];
    }

    /**
     * Thông tin nhà hàng (tĩnh - sẽ được chuyển sang bảng settings sau).
     *
     * @return array<string, mixed>
     */
    public function getRestaurantInfo(): array
    {
        return [
            'name'        => 'Mì Cay Hàn Quốc - Korean Spice House',
            'slogan'      => 'Hương vị cay nồng chuẩn Hàn Quốc',
            'description' => 'Nhà hàng chuyên mì cay Hàn Quốc với 6 cấp độ cay, topping tươi mỗi ngày, kimbap, tokbokki, gà rán và các món tráng miệng đặc trưng.',
            'phone'       => '0901 234 567',
            'address'     => config('checkout.restaurant_address'),
            'open_hours'  => config('reservations.opens_at').' - '.config('reservations.closes_at'),
            'social'      => [
                'facebook'  => 'https://facebook.com/micayhanquoc',
                'instagram' => 'https://instagram.com/micayhanquoc',
                'tiktok'    => 'https://tiktok.com/@micayhanquoc',
            ],
        ];
    }

    /**
     * Thống kê tổng quan để hiển thị ở hero / about section.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'menu_items'        => (int) DB::table('menu_items')->where('is_active', true)->count(),
            'categories'        => (int) DB::table('categories')->where('is_active', true)->count(),
            'tables'            => (int) DB::table('tables')->where('is_active', true)->count(),
            'tables_available'  => (int) DB::table('tables')->where('is_active', true)->where('status', 'AVAILABLE')->count(),
            'blogs_published'   => (int) DB::table('blogs')->where('status', 'PUBLISHED')->count(),
            'happy_customers'   => 1500, // hard-coded cho landing page (sau này có thể thay bằng bảng reviews)
            'spicy_levels'      => 6,
        ];
    }

    /**
     * Danh sách danh mục kèm số lượng món đang active.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoriesWithCount(): array
    {
        return DB::table('categories as c')
            ->leftJoin('menu_items as m', function ($join) {
                $join->on('m.category_id', '=', 'c.id')
                    ->where('m.is_active', true);
            })
            ->where('c.is_active', true)
            ->groupBy('c.id', 'c.name', 'c.slug', 'c.description', 'c.sort_order')
            ->orderBy('c.sort_order')
            ->get([
                'c.id',
                'c.name',
                'c.slug',
                'c.description',
                'c.sort_order',
                DB::raw('COUNT(m.id) as items_count'),
            ])
            ->map(fn ($row) => [
                'id'          => (int) $row->id,
                'name'        => $row->name,
                'slug'        => $row->slug,
                'description' => $row->description,
                'items_count' => (int) $row->items_count,
            ])
            ->all();
    }

    /**
     * Món đặc sắc do quản lý bật, ưu tiên món còn bán và nhóm mì cay.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFeaturedItems(int $limit = 8): array
    {
        $availability = app(MenuAvailabilityService::class);
        // Ưu tiên category mì cay lên đầu, sau đó các category khác
        return DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)
            ->where('m.is_featured', true)
            ->where('c.is_active', true)
            ->orderByDesc('m.is_available')
            ->orderByRaw("CASE WHEN c.slug = 'mi-cay-han-quoc' THEN 0 ELSE 1 END")
            ->orderBy('m.id')
            ->limit($limit)
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
                'm.auto_availability_enabled',
                'c.name as category_name',
                'c.slug as category_slug',
            ])
            ->map(function ($row) use ($availability) {
                $status = $availability->status($row);
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
                'is_available'        => $status['is_available'],
                'availability_reason' => $status['reason'],
                'category_name'       => $row->category_name,
                'category_slug'       => $row->category_slug,
                ];
            })
            ->all();
    }

    /**
     * Món bán chạy do quản lý bật, sắp xếp theo số lượng đã bán thực tế.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBestSellers(int $limit = 4): array
    {
        $availability = app(MenuAvailabilityService::class);
        return DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->leftJoin('order_items as oi', 'oi.menu_item_id', '=', 'm.id')
            ->where('m.is_active', true)
            ->where('m.is_best_seller', true)
            ->where('c.is_active', true)
            ->orderByDesc('m.is_available')
            ->groupBy('m.id', 'm.name', 'm.slug', 'm.description', 'm.image_url', 'm.is_available', 'm.auto_availability_enabled',
                'm.price', 'm.spicy_level', 'm.size', 'm.preparation_minutes',
                'c.name', 'c.slug')
            ->orderByRaw('COALESCE(SUM(oi.quantity), 0) DESC')
            ->orderBy('m.id')
            ->limit($limit)
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
                'm.auto_availability_enabled',
                'c.name as category_name',
                'c.slug as category_slug',
                DB::raw('COALESCE(SUM(oi.quantity), 0) as sold_count'),
            ])
            ->map(function ($row) use ($availability) {
                $status = $availability->status($row);
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
                'is_available'        => $status['is_available'],
                'availability_reason' => $status['reason'],
                'sold_count'          => (int) $row->sold_count,
                'category_name'       => $row->category_name,
                'category_slug'       => $row->category_slug,
                ];
            })
            ->all();
    }

    /**
     * Tổng quan bàn - đếm theo trạng thái.
     *
     * @return array<string, mixed>
     */
    public function getTableOverview(): array
    {
        $rows = DB::table('tables')
            ->where('is_active', true)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->get();

        $byStatus = [
            'AVAILABLE'        => 0,
            'RESERVED'         => 0,
            'OCCUPIED'         => 0,
            'WAITING_PAYMENT'  => 0,
            'CLEANING'         => 0,
        ];

        foreach ($rows as $r) {
            $byStatus[$r->status] = (int) $r->total;
        }

        $total = array_sum($byStatus);

        return [
            'total'     => $total,
            'by_status' => $byStatus,
            'summary'   => [
                'available'        => $byStatus['AVAILABLE'],
                'busy'             => $byStatus['OCCUPIED'] + $byStatus['WAITING_PAYMENT'],
                'reserved'         => $byStatus['RESERVED'],
            ],
        ];
    }

    /**
     * Blog mới nhất đã published để hiển thị ở footer / section tin tức.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLatestBlogs(int $limit = 3): array
    {
        return DB::table('blogs as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.author_id')
            ->where('b.status', 'PUBLISHED')
            ->orderByDesc('b.published_at')
            ->limit($limit)
            ->get([
                'b.id',
                'b.title',
                'b.slug',
                'b.excerpt',
                'b.cover_image',
                'b.category',
                'b.published_at',
                'u.name as author_name',
            ])
            ->map(fn ($row) => [
                'id'           => (int) $row->id,
                'title'        => $row->title,
                'slug'         => $row->slug,
                'excerpt'      => $row->excerpt,
                'cover_image'  => $row->cover_image,
                'category'     => $row->category,
                'published_at' => $row->published_at,
                'author_name'  => $row->author_name,
            ])
            ->all();
    }

    /**
     * Ảnh placeholder theo slug danh mục.
     * Trỏ về Laravel Storage (storage/app/public/), truy cập qua symlink public/storage.
     * Cấu trúc file: storage/app/public/menu/{slug}.jpg
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

        // asset() sẽ trả về URL đầy đủ: http://domain/storage/menu/xxx.jpg
        return asset('storage/' . $relative);
    }
}
