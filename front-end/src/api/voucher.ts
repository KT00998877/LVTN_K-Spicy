/**
 * API cho Voucher + Loyalty.
 *
 * Voucher (khách):
 *  - GET    /api/vouchers?active=1   — danh sách voucher còn hiệu lực (marketing)
 *  - POST   /api/vouchers/preview     — validate + tính discount cho 1 mã
 *
 * Loyalty (khách):
 *  - GET    /api/vouchers/loyalty     — sổ cái điểm thưởng cá nhân
 */
import apiClient from './client'
import type {
    LoyaltyResponse,
    Voucher,
    VoucherPreview,
} from '@/types/voucher'

const ENDPOINTS = {
    vouchers:  '/vouchers',
    preview:   '/vouchers/preview',
    loyalty:   '/vouchers/loyalty',
} as const

/* ===== Vouchers ===== */

/** Lấy danh sách voucher (mặc định chỉ active). */
export async function fetchActiveVouchers(): Promise<Voucher[]> {
    const { data } = await apiClient.get<{ success: boolean; data: Voucher[] }>(
        ENDPOINTS.vouchers, { params: { active: 1 } },
    )
    return data.data
}

/** Preview 1 voucher cho subtotal hiện tại (mặc định lấy từ cart của user). */
export async function previewVoucher(code: string, subtotal?: number): Promise<VoucherPreview> {
    const { data } = await apiClient.post<{ success: boolean; data: VoucherPreview }>(
        ENDPOINTS.preview, { code, subtotal },
    )
    return data.data
}

/* ===== Loyalty ===== */

export async function fetchLoyaltyLedger(page = 1, perPage = 20): Promise<LoyaltyResponse> {
    const { data } = await apiClient.get<{ success: boolean; data: LoyaltyResponse }>(
        ENDPOINTS.loyalty, { params: { page, per_page: perPage } },
    )
    return data.data
}