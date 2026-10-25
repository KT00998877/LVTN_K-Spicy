/**
 * Shared types cho module Home.
 * Đồng bộ với HomeService.php (back-end) và HomeController response shape.
 */

export interface RestaurantInfo {
    name: string
    slogan: string
    description: string
    phone: string
    address: string
    email?: string
    open_hours: string
    social: {
        facebook: string
        instagram: string
        tiktok: string
    }
}

export interface HomeStats {
    menu_items: number
    categories: number
    tables: number
    tables_available: number
    blogs_published: number
    happy_customers: number
    spicy_levels: number
}

export interface CategoryItem {
    id: number
    name: string
    slug: string
    description: string | null
    items_count: number
}

export interface MenuItemCard {
    rating_summary?: { count: number; average: number }
    id: number
    name: string
    slug: string
    description: string | null
    image_url: string
    price: number
    price_formatted: string
    spicy_level: number
    size: 'SMALL' | 'LARGE' | null
    preparation_minutes: number
    category_name: string
    category_slug: string
    /** Có món hay không (để staff gọi món) */
    is_available?: boolean
    /** Lý do backend tự chặn: 'manual' | 'ingredient' | 'insufficient_data' | null */
    availability_reason?: 'manual' | 'ingredient' | 'insufficient_data' | null
    /** Lý do bếp/quản lý báo hết thủ công (chỉ có khi is_available = false). */
    sold_out_reason?: string | null
    /** Thời điểm báo hết (ISO string). */
    sold_out_at?: string | null
    /** Chỉ có ở best_sellers */
    sold_count?: number
    /** Gợi ý combo nước uống do quản lý cấu hình (Phase 3). */
    suggestions?: import('./menuSuggestion').MenuSuggestion[]
}

export type TableStatusKey =
    | 'AVAILABLE'
    | 'RESERVED'
    | 'OCCUPIED'
    | 'WAITING_PAYMENT'
    | 'CLEANING'

export interface TableOverview {
    total: number
    by_status: Record<TableStatusKey, number>
    summary: {
        available: number
        busy: number
        reserved: number
    }
}

export type BlogCategory =
    | 'NEWS'
    | 'PROMOTION'
    | 'NEW_DISH'
    | 'EVENT'
    | 'CULINARY'

export interface BlogCard {
    id: number
    title: string
    slug: string
    excerpt: string | null
    cover_image: string | null
    category: BlogCategory
    published_at: string | null
    author_name: string | null
}

/** Payload trả về từ GET /api/home */
export interface HomePayload {
    restaurant: RestaurantInfo
    stats: HomeStats
    categories: CategoryItem[]
    featured: MenuItemCard[]
    best_sellers: MenuItemCard[]
    tables: TableOverview
    blogs: BlogCard[]
}

/** Generic API response wrapper */
export interface ApiResponse<T> {
    success: boolean
    message?: string
    data: T
}
