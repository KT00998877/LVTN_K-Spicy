/**
 * marketingCampaigns.ts — API client cho Manager quản lý chiến dịch ưu đãi (Phase 1).
 *
 * Tất cả endpoint dưới `/api/staff/manager/marketing-campaigns` — cần role MANAGER.
 */
import apiClient from './client'

export type MarketingCampaignStatus = 'SCHEDULED' | 'SENT' | 'SKIPPED' | 'CANCELLED'

export interface MarketingCampaign {
    id: number
    name: string
    message: string
    voucher_code: string
    banner_image_url: string | null
    accent_color: string
    cta_text: string
    landing_url: string | null
    target_tier_ids: number[] | null
    send_at: string
    window_start: string
    window_end: string
    status: MarketingCampaignStatus
    sent_at: string | null
    recipient_count: number
    discount_label: string | null
    created_at: string
}

export interface VoucherOption {
    code: string
    name: string
    type: 'PERCENT' | 'FIXED'
    value: number
    ends_at: string | null
}

export interface TierOption {
    id: number
    code: string
    name: string
    color: string | null
}

export interface MarketingCampaignPayload {
    name: string
    message: string
    voucher_code: string
    banner_image_url?: string | null
    accent_color?: string
    cta_text?: string
    landing_url?: string | null
    target_tier_ids?: number[] | null
    send_at: string
    window_start: string
    window_end: string
}

const ENDPOINTS = {
    campaigns: '/staff/manager/marketing-campaigns',
    campaign: (id: number) => `/staff/manager/marketing-campaigns/${id}`,
    send: (id: number) => `/staff/manager/marketing-campaigns/${id}/send`,
    cancel: (id: number) => `/staff/manager/marketing-campaigns/${id}/cancel`,
    vouchers: '/staff/manager/marketing-campaigns/meta/vouchers',
    tiers: '/staff/manager/marketing-campaigns/meta/tiers',
} as const

/** GET — danh sách chiến dịch ưu đãi. */
export async function fetchMarketingCampaigns(params?: { search?: string; status?: string }): Promise<MarketingCampaign[]> {
    const { data } = await apiClient.get(ENDPOINTS.campaigns, { params })
    return data.data
}

/** POST — tạo chiến dịch ưu đãi mới. */
export async function createMarketingCampaign(payload: MarketingCampaignPayload): Promise<MarketingCampaign> {
    const { data } = await apiClient.post(ENDPOINTS.campaigns, payload)
    return data.data
}

/** PUT — sửa chiến dịch ưu đãi. */
export async function updateMarketingCampaign(id: number, payload: MarketingCampaignPayload): Promise<MarketingCampaign> {
    const { data } = await apiClient.put(ENDPOINTS.campaign(id), payload)
    return data.data
}

/** DELETE — xoá chiến dịch (chỉ được xoá khi chưa gửi). */
export async function deleteMarketingCampaign(id: number): Promise<void> {
    await apiClient.delete(ENDPOINTS.campaign(id))
}

/** POST — gửi ngay, không cần chờ cron. */
export async function sendMarketingCampaignNow(id: number): Promise<{ message: string; data: MarketingCampaign }> {
    const { data } = await apiClient.post(ENDPOINTS.send(id))
    return data
}

/** POST — huỷ lịch gửi. */
export async function cancelMarketingCampaign(id: number): Promise<MarketingCampaign> {
    const { data } = await apiClient.post(ENDPOINTS.cancel(id))
    return data.data
}

/** GET — danh sách mã voucher để chọn. */
export async function fetchVoucherOptions(): Promise<VoucherOption[]> {
    const { data } = await apiClient.get(ENDPOINTS.vouchers)
    return data.data
}

/** GET — danh sách hạng thành viên để chọn đối tượng nhận. */
export async function fetchTierOptions(): Promise<TierOption[]> {
    const { data } = await apiClient.get(ENDPOINTS.tiers)
    return data.data
}
