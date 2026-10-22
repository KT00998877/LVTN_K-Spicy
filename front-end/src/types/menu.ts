/**
 * Types cho module Menu (trang thực đơn).
 * Đồng bộ với MenuService.php (back-end) và /api/menu response shape.
 */

export type MenuSize = 'SMALL' | 'LARGE'

/** Sort option cho dropdown */
export type MenuSort =
    | 'default'
    | 'price_asc'
    | 'price_desc'
    | 'name_asc'
    | 'name_desc'
    | 'newest'

/** UI state cho filters (lưu vào query string) */
export interface MenuFilters {
    category: string | null       // slug hoặc null = tất cả
    search: string
    size: MenuSize | null
    min_price: number | null
    max_price: number | null
    sort: MenuSort
}

/** Meta trả về từ API */
export interface MenuMeta {
    count: number
    current_page: number
    per_page: number
    total: number
    last_page: number
    filters: Record<string, unknown>
}

/** Response của GET /api/menu */
export interface MenuListResponse {
    success: boolean
    message?: string
    data: import('./home').MenuItemCard[]
    meta: MenuMeta
}

/** Response của GET /api/menu/{slug} */
export interface MenuDetailResponse {
    success: boolean
    data: import('./home').MenuItemCard & {
        is_available: boolean
    }
}

/** Response từ GET /api/menu/categories (nếu sau này tách riêng) */
export interface MenuCategoryOption {
    id: number
    name: string
    slug: string
    items_count: number
}
