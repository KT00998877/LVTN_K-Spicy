/**
 * API cho Manager — Promotion CRUD (Phase 4).
 *
 *  - GET    /api/staff/manager/promotions             — list
 *  - POST   /api/staff/manager/promotions             — tạo
 *  - GET    /api/staff/manager/promotions/{id}        — chi tiết
 *  - PUT    /api/staff/manager/promotions/{id}        — cập nhật
 *  - DELETE /api/staff/manager/promotions/{id}        — xoá/soft-delete
 *  - POST   /api/staff/manager/promotions/{id}/toggle — bật/tắt nhanh
 *  - GET    /api/staff/manager/promotions/{id}/usages — lịch sử
 *  - POST   /api/staff/manager/promotions/preview     — preview cart mẫu
 */
import apiClient from './client'
import type {
    ListPromotionsParams,
    ManagerPromotion,
    ManagerPromotionUsage,
    PreviewCartItem,
    PromotionPayload,
    PromotionPreviewResult,
} from '@/types/promotion'
import type { Paginated } from '@/types/voucher'

export function listManagerPromotions(
    params: ListPromotionsParams = {},
): Promise<Paginated<ManagerPromotion>> {
    return apiClient
        .get<{ success: boolean; data: ManagerPromotion[]; meta: Paginated<ManagerPromotion>['meta'] }>(
            '/staff/manager/promotions',
            { params },
        )
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export function getManagerPromotion(id: number): Promise<ManagerPromotion> {
    return apiClient
        .get<{ success: boolean; data: ManagerPromotion }>(`/staff/manager/promotions/${id}`)
        .then((r) => r.data.data)
}

export function createManagerPromotion(payload: PromotionPayload): Promise<ManagerPromotion> {
    return apiClient
        .post<{ success: boolean; data: ManagerPromotion }>('/staff/manager/promotions', payload)
        .then((r) => r.data.data)
}

export function updateManagerPromotion(
    id: number,
    payload: PromotionPayload,
): Promise<ManagerPromotion> {
    return apiClient
        .put<{ success: boolean; data: ManagerPromotion }>(
            `/staff/manager/promotions/${id}`,
            payload,
        )
        .then((r) => r.data.data)
}

export function deleteManagerPromotion(id: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`/staff/manager/promotions/${id}`)
        .then((r) => r.data)
}

export function toggleManagerPromotion(id: number): Promise<ManagerPromotion> {
    return apiClient
        .post<{ success: boolean; data: ManagerPromotion }>(
            `/staff/manager/promotions/${id}/toggle`,
        )
        .then((r) => r.data.data)
}

export function listPromotionUsages(
    id: number,
    page = 1,
    perPage = 20,
): Promise<Paginated<ManagerPromotionUsage>> {
    return apiClient
        .get<{
            success: boolean
            data: ManagerPromotionUsage[]
            meta: Paginated<ManagerPromotionUsage>['meta']
        }>(`/staff/manager/promotions/${id}/usages`, { params: { page, per_page: perPage } })
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export interface PreviewPayload {
    subtotal: number
    channel?: 'ONLINE' | 'DINE_IN' | 'TAKEAWAY'
    items: PreviewCartItem[]
    user_total_orders?: number
    loyalty_tier_id?: number
}

export function previewManagerPromotion(payload: PreviewPayload): Promise<PromotionPreviewResult> {
    return apiClient
        .post<{ success: boolean; data: PromotionPreviewResult }>(
            '/staff/manager/promotions/preview',
            payload,
        )
        .then((r) => r.data.data)
}
