/**
 * API cho Campaign (Phase 5).
 *
 * Manager endpoints (cần auth + role MANAGER):
 *  - GET    /api/staff/manager/campaigns             — list
 *  - POST   /api/staff/manager/campaigns             — tạo
 *  - GET    /api/staff/manager/campaigns/{id}        — chi tiết
 *  - PUT    /api/staff/manager/campaigns/{id}        — sửa
 *  - DELETE /api/staff/manager/campaigns/{id}        — xoá
 *  - POST   /api/staff/manager/campaigns/{id}/toggle — bật/tắt
 *  - GET    /api/staff/manager/campaigns/{id}/stats  — tổng quan
 *
 * Public endpoints (customer):
 *  - GET /api/campaigns             — list campaign đang chạy
 *  - GET /api/campaigns/{slug}      — chi tiết
 */
import apiClient from './client'
import type {
    CampaignPayload,
    CampaignStats,
    ListCampaignsParams,
    ManagerCampaign,
    ManagerCampaignDetail,
    PublicCampaign,
    PublicCampaignDetail,
} from '@/types/campaign'
import type { Paginated } from '@/types/voucher'

/* ============================================================
 *  Manager
 * ============================================================ */

export function listManagerCampaigns(
    params: ListCampaignsParams = {},
): Promise<Paginated<ManagerCampaign>> {
    return apiClient
        .get<{ success: boolean; data: ManagerCampaign[]; meta: Paginated<ManagerCampaign>['meta'] }>(
            '/staff/manager/campaigns',
            { params },
        )
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export function getManagerCampaign(id: number): Promise<ManagerCampaignDetail> {
    return apiClient
        .get<{ success: boolean; data: ManagerCampaignDetail }>(`/staff/manager/campaigns/${id}`)
        .then((r) => r.data.data)
}

export function createManagerCampaign(payload: CampaignPayload): Promise<ManagerCampaignDetail> {
    return apiClient
        .post<{ success: boolean; data: ManagerCampaignDetail }>(
            '/staff/manager/campaigns',
            payload,
        )
        .then((r) => r.data.data)
}

export function updateManagerCampaign(
    id: number,
    payload: CampaignPayload,
): Promise<ManagerCampaignDetail> {
    return apiClient
        .put<{ success: boolean; data: ManagerCampaignDetail }>(
            `/staff/manager/campaigns/${id}`,
            payload,
        )
        .then((r) => r.data.data)
}

export function deleteManagerCampaign(id: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`/staff/manager/campaigns/${id}`)
        .then((r) => r.data)
}

export function toggleManagerCampaign(id: number): Promise<ManagerCampaign> {
    return apiClient
        .post<{ success: boolean; data: ManagerCampaign }>(
            `/staff/manager/campaigns/${id}/toggle`,
        )
        .then((r) => r.data.data)
}

export function getManagerCampaignStats(id: number): Promise<CampaignStats> {
    return apiClient
        .get<{ success: boolean; data: CampaignStats }>(`/staff/manager/campaigns/${id}/stats`)
        .then((r) => r.data.data)
}

/* ============================================================
 *  Public (customer)
 * ============================================================ */

export interface ListPublicCampaignsParams {
    active?: boolean
    page?: number
    per_page?: number
}

export function listPublicCampaigns(
    params: ListPublicCampaignsParams = {},
): Promise<Paginated<PublicCampaign>> {
    return apiClient
        .get<{ success: boolean; data: PublicCampaign[]; meta: Paginated<PublicCampaign>['meta'] }>(
            '/campaigns',
            { params },
        )
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export function getPublicCampaign(slug: string): Promise<PublicCampaignDetail> {
    return apiClient
        .get<{ success: boolean; data: PublicCampaignDetail }>(`/campaigns/${slug}`)
        .then((r) => r.data.data)
}
