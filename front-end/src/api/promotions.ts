/**
 * API cho ưu đãi nổi bật (Phase 1) — banner trang chủ + mã voucher.
 *
 * `fetchFeaturedPromotions` là endpoint public: khách xem trang chủ khi
 * chưa đăng nhập vẫn thấy banner ưu đãi.
 */
import apiClient from './client'

export interface FeaturedPromotion {
    id: number
    name: string
    message: string
    voucher_code: string
    banner_image_url: string | null
    accent_color: string
    cta_text: string
    landing_url: string | null
    discount_label: string | null
    ends_at: string | null
}

export interface ClaimedPromotion {
    id: number
    campaign_id: number
    name: string
    message: string
    voucher_code: string
    discount_label: string | null
    landing_url: string | null
    claimed_at: string | null
    ends_at: string | null
}

export interface MyVoucher {
    id: string
    voucher_code: string
    name: string
    message: string | null
    discount_label: string
    received_at: string | null
    ends_at: string | null
    is_usable: boolean
}

interface FeaturedPromotionsResponse {
    data: FeaturedPromotion[]
}

const ENDPOINTS = {
    featured: '/promotions/featured',
    mine: '/promotions/me',
    myVouchers: '/vouchers/me',
    claim: (id: number) => `/promotions/${id}/claim`,
} as const

/**
 * GET /api/promotions/featured — danh sách ưu đãi đang chạy.
 */
export async function fetchFeaturedPromotions(): Promise<FeaturedPromotion[]> {
    const { data } = await apiClient.get<FeaturedPromotionsResponse>(ENDPOINTS.featured)
    return data.data
}

export async function claimPromotion(id: number): Promise<ClaimedPromotion> {
    const { data } = await apiClient.post<{ success: boolean; data: ClaimedPromotion }>(ENDPOINTS.claim(id))
    return data.data
}

export async function fetchMyPromotions(): Promise<ClaimedPromotion[]> {
    const { data } = await apiClient.get<{ success: boolean; data: ClaimedPromotion[] }>(ENDPOINTS.mine)
    return data.data
}

export async function fetchMyVouchers(): Promise<MyVoucher[]> {
    const { data } = await apiClient.get<{ success: boolean; data: MyVoucher[] }>(ENDPOINTS.myVouchers)
    return data.data
}
