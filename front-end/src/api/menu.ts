import apiClient from './client'
import type { MenuItemCard } from '@/types/home'
import type {
    MenuDetailResponse,
    MenuFilters,
    MenuListResponse,
    MenuSize,
    MenuSort,
} from '@/types/menu'

const ENDPOINTS = {
    list: '/menu',
    detail: (slug: string) => `/menu/${slug}`,
} as const

/**
 * Chuẩn hóa filter từ UI state → query params cho API.
 * Bỏ qua các giá trị rỗng / null / default để URL gọn.
 */
function buildParams(filters: MenuFilters): Record<string, string | number> {
    const params: Record<string, string | number> = {}

    if (filters.category) {
        params.category = filters.category
    }
    if (filters.search.trim()) {
        params.search = filters.search.trim()
    }
    if (filters.size) {
        params.size = filters.size
    }
    if (filters.min_price !== null && filters.min_price !== undefined && filters.min_price > 0) {
        params.min_price = filters.min_price
    }
    if (filters.max_price !== null && filters.max_price !== undefined && filters.max_price > 0) {
        params.max_price = filters.max_price
    }
    if (filters.sort && filters.sort !== 'default') {
        params.sort = filters.sort
    }

    return params
}

/**
 * GET /api/menu — danh sách món đã filter.
 */
export async function fetchMenuItems(filters: MenuFilters, page = 1, perPage = 50): Promise<MenuListResponse> {
    const params = { ...buildParams(filters), page, per_page: perPage }
    const { data } = await apiClient.get<MenuListResponse>(ENDPOINTS.list, { params })
    return data
}

/**
 * GET /api/menu/{slug} — chi tiết 1 món.
 */
export async function fetchMenuItemBySlug(slug: string): Promise<MenuItemCard & { is_available: boolean }> {
    const { data } = await apiClient.get<MenuDetailResponse>(ENDPOINTS.detail(slug))
    return data.data
}

/** Helper parse query string từ URL → MenuFilters */
export function parseFiltersFromQuery(query: Record<string, unknown>): MenuFilters {
    const get = (k: string): string | undefined => {
        const v = query[k]
        if (typeof v === 'string') return v
        if (Array.isArray(v) && typeof v[0] === 'string') return v[0]
        return undefined
    }

    const minRaw = get('min_price')
    const maxRaw = get('max_price')

    const allowedSizes: MenuSize[] = ['SMALL', 'LARGE']
    const sizeRaw = get('size')
    const size: MenuSize | null = sizeRaw && allowedSizes.includes(sizeRaw as MenuSize)
        ? (sizeRaw as MenuSize)
        : null

    const allowedSorts: MenuSort[] = ['default', 'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest']
    const sortRaw = get('sort')
    const sort: MenuSort = sortRaw && allowedSorts.includes(sortRaw as MenuSort)
        ? (sortRaw as MenuSort)
        : 'default'

    return {
        category: get('category') ?? null,
        search: get('search') ?? '',
        size,
        min_price: minRaw !== undefined && minRaw !== '' ? Number(minRaw) : null,
        max_price: maxRaw !== undefined && maxRaw !== '' ? Number(maxRaw) : null,
        sort,
    }
}

/** Helper serialize MenuFilters → query string (để đẩy lên URL) */
export function serializeFiltersToQuery(filters: MenuFilters): Record<string, string> {
    const q: Record<string, string> = {}

    if (filters.category) q.category = filters.category
    if (filters.search.trim()) q.search = filters.search.trim()
    if (filters.size) q.size = filters.size
    if (filters.min_price !== null && filters.min_price !== undefined && filters.min_price > 0) {
        q.min_price = String(filters.min_price)
    }
    if (filters.max_price !== null && filters.max_price !== undefined && filters.max_price > 0) {
        q.max_price = String(filters.max_price)
    }
    if (filters.sort !== 'default') q.sort = filters.sort

    return q
}
