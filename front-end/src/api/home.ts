import apiClient from './client'
import type {
    ApiResponse,
    BlogCard,
    CategoryItem,
    HomePayload,
    HomeStats,
    MenuItemCard,
    RestaurantInfo,
    TableOverview,
} from '@/types/home'

const ENDPOINTS = {
    home: '/home',
    restaurant: '/home/restaurant',
    stats: '/home/stats',
    categories: '/home/categories',
    featured: '/home/featured',
    bestSellers: '/home/best-sellers',
    tables: '/home/tables',
    blogs: '/home/blogs',
} as const

/**
 * Gọi 1 lần duy nhất, lấy toàn bộ payload cho trang chủ.
 * Backend: GET /api/home -> HomeController@index -> HomeService::getHomePayload()
 */
export async function fetchHomePayload(): Promise<HomePayload> {
    const { data } = await apiClient.get<ApiResponse<HomePayload>>(ENDPOINTS.home)
    return data.data
}

export async function fetchRestaurantInfo(): Promise<RestaurantInfo> {
    const { data } = await apiClient.get<ApiResponse<RestaurantInfo>>(ENDPOINTS.restaurant)
    return data.data
}

export async function fetchHomeStats(): Promise<HomeStats> {
    const { data } = await apiClient.get<ApiResponse<HomeStats>>(ENDPOINTS.stats)
    return data.data
}

export async function fetchHomeCategories(): Promise<CategoryItem[]> {
    const { data } = await apiClient.get<ApiResponse<CategoryItem[]>>(ENDPOINTS.categories)
    return data.data
}

export async function fetchFeaturedItems(): Promise<MenuItemCard[]> {
    const { data } = await apiClient.get<ApiResponse<MenuItemCard[]>>(ENDPOINTS.featured)
    return data.data
}

export async function fetchBestSellers(): Promise<MenuItemCard[]> {
    const { data } = await apiClient.get<ApiResponse<MenuItemCard[]>>(ENDPOINTS.bestSellers)
    return data.data
}

export async function fetchTableOverview(): Promise<TableOverview> {
    const { data } = await apiClient.get<ApiResponse<TableOverview>>(ENDPOINTS.tables)
    return data.data
}

export async function fetchLatestBlogs(): Promise<BlogCard[]> {
    const { data } = await apiClient.get<ApiResponse<BlogCard[]>>(ENDPOINTS.blogs)
    return data.data
}
