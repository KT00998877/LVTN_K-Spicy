/**
 * Marketing Studio API client (Phase 6.1 + 6.2).
 *
 *  - GET    /api/staff/manager/marketing/context               — gợi ý form
 *  - GET    /api/staff/manager/marketing/assets                — list (paginated, ?include_archived=1 để xem thùng rác)
 *  - POST   /api/staff/manager/marketing/assets                — tạo asset + version 1
 *  - GET    /api/staff/manager/marketing/assets/{id}           — chi tiết
 *  - POST   /api/staff/manager/marketing/assets/{id}/generate  — sinh version mới
 *  - DELETE /api/staff/manager/marketing/assets/{id}           — archive (soft delete)
 *  - PATCH  /api/staff/manager/marketing/assets/{id}/restore   — khôi phục ARCHIVED → DRAFT
 *  - DELETE /api/staff/manager/marketing/assets/{id}/force     — xoá vĩnh viễn (chỉ khi ARCHIVED)
 *  - PATCH  /api/staff/manager/marketing/assets/{id}/versions/{vid}/select — duyệt version
 *  - PATCH  /api/staff/manager/marketing/assets/{id}/versions/{vid}        — sửa nội dung version
 */
import apiClient from './client'
import type { Paginated } from '@/types/voucher'

export type MarketingChannel = 'FACEBOOK' | 'INSTAGRAM' | 'TIKTOK' | 'ZALO' | 'BLOG' | 'EMAIL'
export type MarketingContentType = 'POST' | 'STORY' | 'REEL_SCRIPT' | 'BLOG_ARTICLE' | 'EMAIL_BLAST'
export type MarketingTone = 'FUN' | 'WARM' | 'PREMIUM' | 'CASUAL' | 'PUNCHY'
export type MarketingStatus = 'DRAFT' | 'IN_REVIEW' | 'APPROVED' | 'SCHEDULED' | 'PUBLISHED' | 'REJECTED' | 'ARCHIVED'

export interface MarketingDraftContext {
    brand: string
    store_info: string
    campaigns: Array<{ id: number; name: string; slug: string }>
    menu_items: Array<{ id: number; name: string; price: number }>
    promotions: Array<{ id: number; name: string; kind: string; value: number }>
    tones: MarketingTone[]
    channels: MarketingChannel[]
    content_types: MarketingContentType[]
}

export interface MarketingVersion {
    id: number
    asset_id: number
    version_number: number
    provider: 'MOCK' | 'GEMINI' | 'OPENAI' | 'HUMAN'
    provider_model: string | null
    headline: string | null
    caption: string | null
    body: string | null
    hashtags: string[] | null
    meta: Record<string, unknown> | null
    prompt_used: string | null
    is_selected: boolean
    created_at?: string
    updated_at?: string
}

export interface MarketingAsset {
    id: number
    created_by: number
    campaign_id: number | null
    channel: MarketingChannel
    content_type: MarketingContentType
    subject: string
    tone: MarketingTone
    status: MarketingStatus
    approved_at: string | null
    approved_by: number | null
    versions?: MarketingVersion[]
    versions_count?: number
    context_payload?: Record<string, unknown> | null
}

export interface CreateAssetPayload {
    channel: MarketingChannel
    content_type: MarketingContentType
    subject?: string | null
    tone?: MarketingTone
    campaign_id?: number | null
    menu_item_id?: number | null
    promotion_id?: number | null
}

export interface ListAssetsParams {
    status?: MarketingStatus
    channel?: MarketingChannel
    q?: string
    page?: number
    per_page?: number
    /** Khi true: bao gồm asset ARCHIVED (tab "Thùng rác"). */
    include_archived?: boolean
}

const BASE = '/staff/manager/marketing'

export function fetchDraftContext(): Promise<MarketingDraftContext> {
    return apiClient
        .get<{ success: boolean; data: MarketingDraftContext }>(`${BASE}/context`)
        .then((r) => r.data.data)
}

export function listAssets(params: ListAssetsParams = {}): Promise<Paginated<MarketingAsset>> {
    return apiClient
        .get<{ success: boolean; data: MarketingAsset[]; meta: Paginated<MarketingAsset>['meta'] }>(
            `${BASE}/assets`,
            { params },
        )
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export function getAsset(id: number): Promise<MarketingAsset> {
    return apiClient
        .get<{ success: boolean; data: MarketingAsset }>(`${BASE}/assets/${id}`)
        .then((r) => r.data.data)
}

export function createAsset(payload: CreateAssetPayload): Promise<MarketingAsset> {
    return apiClient
        .post<{ success: boolean; data: MarketingAsset }>(`${BASE}/assets`, payload)
        .then((r) => r.data.data)
}

export function generateNewVersion(assetId: number): Promise<MarketingVersion> {
    return apiClient
        .post<{ success: boolean; data: MarketingVersion }>(`${BASE}/assets/${assetId}/generate`)
        .then((r) => r.data.data)
}

export function selectVersion(assetId: number, versionId: number): Promise<MarketingAsset> {
    return apiClient
        .patch<{ success: boolean; data: MarketingAsset }>(
            `${BASE}/assets/${assetId}/versions/${versionId}/select`,
        )
        .then((r) => r.data.data)
}

export function archiveAsset(assetId: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`${BASE}/assets/${assetId}`)
        .then((r) => r.data)
}

export function restoreAsset(assetId: number): Promise<MarketingAsset> {
    return apiClient
        .patch<{ success: boolean; data: MarketingAsset; message?: string }>(
            `${BASE}/assets/${assetId}/restore`,
        )
        .then((r) => r.data.data)
}

export function forceDeleteAsset(assetId: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`${BASE}/assets/${assetId}/force`)
        .then((r) => r.data)
}

export function updateVersion(
    assetId: number,
    versionId: number,
    payload: { headline?: string | null; caption?: string | null; body?: string | null; hashtags?: string[] | null },
): Promise<MarketingAsset> {
    return apiClient
        .patch<{ success: boolean; data: MarketingAsset }>(
            `${BASE}/assets/${assetId}/versions/${versionId}`,
            payload,
        )
        .then((r) => r.data.data)
}

export function pushAssetVersionToBlog(
    assetId: number,
    payload: { version_id: number; category?: string | null; news_section?: string | null; title?: string | null },
): Promise<{ id: number; slug: string }> {
    return apiClient
        .post<{ success: boolean; data: { id: number; slug: string; title: string; status: string } }>(
            `${BASE}/assets/${assetId}/publish-to-blog`,
            payload,
        )
        .then((r) => r.data.data)
}