/**
 * API cho Manager — CRUD gợi ý combo nước uống (Phase 3).
 *
 *  - GET    /api/staff/manager/menu-suggestions         — list
 *  - GET    /api/staff/manager/menu-suggestions/options — danh sách món để chọn
 *  - POST   /api/staff/manager/menu-suggestions         — tạo
 *  - PATCH  /api/staff/manager/menu-suggestions/{id}    — sửa
 *  - DELETE /api/staff/manager/menu-suggestions/{id}    — xoá
 */
import apiClient from './client'
import type { MenuSuggestion } from '@/types/menuSuggestion'

/** 1 dòng gợi ý trong bảng quản lý (đã format sẵn cho UI). */
export interface ManagerMenuSuggestion {
    id: number
    title: string
    message: string | null
    menu_item_id: number
    suggested_item_id: number
    menu_item: { id: number; name: string; price: number } | null
    suggested_item: { id: number; name: string; price: number } | null
    combo_price: number | null
    combo_price_formatted: string | null
    original_price: number
    saving: number
    is_active: boolean
    sort_order: number
    created_at: string | null
}

/** Món để chọn trong form. */
export interface SuggestionOption {
    id: number
    name: string
    price: number
    price_formatted: string
    category_name: string | null
    category_slug: string | null
    image_url: string | null
    is_available: boolean
}

export interface MenuSuggestionPayload {
    menu_item_id: number
    suggested_item_id: number
    title?: string | null
    message?: string | null
    combo_price?: number | null
    is_active?: boolean
    sort_order?: number
}

export interface MenuSuggestionListMeta {
    total: number
    per_page: number
    current_page: number
    last_page: number
}

export async function listMenuSuggestions(params: {
    search?: string
    is_active?: boolean
    menu_item_id?: number
    page?: number
    per_page?: number
} = {}): Promise<{ data: ManagerMenuSuggestion[]; meta: MenuSuggestionListMeta }> {
    const { data } = await apiClient.get<{
        success: boolean
        data: ManagerMenuSuggestion[]
        meta: MenuSuggestionListMeta
    }>('/staff/manager/menu-suggestions', { params })
    return { data: data.data, meta: data.meta }
}

export async function listSuggestionOptions(): Promise<SuggestionOption[]> {
    const { data } = await apiClient.get<{ success: boolean; data: SuggestionOption[] }>(
        '/staff/manager/menu-suggestions/options',
    )
    return data.data
}

export async function createMenuSuggestion(
    payload: MenuSuggestionPayload,
): Promise<ManagerMenuSuggestion> {
    const { data } = await apiClient.post<{ success: boolean; data: ManagerMenuSuggestion }>(
        '/staff/manager/menu-suggestions',
        payload,
    )
    return data.data
}

export async function updateMenuSuggestion(
    id: number,
    payload: MenuSuggestionPayload,
): Promise<ManagerMenuSuggestion> {
    const { data } = await apiClient.patch<{ success: boolean; data: ManagerMenuSuggestion }>(
        `/staff/manager/menu-suggestions/${id}`,
        payload,
    )
    return data.data
}

export async function deleteMenuSuggestion(id: number): Promise<void> {
    await apiClient.delete(`/staff/manager/menu-suggestions/${id}`)
}

export type { MenuSuggestion }
