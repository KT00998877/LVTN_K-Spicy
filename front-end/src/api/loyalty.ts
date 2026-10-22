import apiClient from './client'
import type { LoyaltyTier } from '@/types/auth'

/**
 * Types cho API hạng thành viên (Loyalty Tiers).
 *
 * Endpoint backend:
 *  - GET  /api/loyalty/tiers            (public)  — danh sách 4 hạng + benefits
 *  - GET  /api/loyalty/me               (auth)    — hạng hiện tại + tiến trình thăng hạng
 *  - POST /api/staff/loyalty/{id}/adjust (MANAGER) — chỉnh tay total_spent
 */

/** Subset dùng cho public list — đầy đủ benefits. */
export type LoyaltyTierPublic = LoyaltyTier & {
    min_spent: number
    description: string | null
}

export interface LoyaltyTierProgress {
    current_spent: number
    next_min_spent: number
    amount_to_next: number
    percent: number
}

export interface LoyaltyMeResponse {
    user: {
        id: number
        loyalty_points: number
        total_spent: number
        tier_updated_at: string | null
    }
    current: LoyaltyTierPublic | null
    next: (LoyaltyTier & { min_spent: number }) | null
    progress: LoyaltyTierProgress | null
}

/** GET /api/loyalty/tiers — public list of 4 tiers. */
export async function fetchLoyaltyTiers(): Promise<LoyaltyTierPublic[]> {
    const { data } = await apiClient.get<{ success: boolean; data: LoyaltyTierPublic[] }>(
        '/loyalty/tiers',
    )
    return data.data
}

/** GET /api/loyalty/me — customer current tier + progress. */
export async function fetchLoyaltyMe(): Promise<LoyaltyMeResponse> {
    const { data } = await apiClient.get<{ success: boolean; data: LoyaltyMeResponse }>(
        '/loyalty/me',
    )
    return data.data
}

/** POST /api/staff/loyalty/{user}/adjust — manager manually correct total_spent. */
export interface LoyaltyAdjustPayload {
    total_spent?: number
    reason: string
}

export interface LoyaltyAdjustResponse {
    user_id: number
    total_spent: number
    loyalty_tier: string | null
    changed: boolean
}

export async function adjustLoyaltyTier(
    userId: number,
    payload: LoyaltyAdjustPayload,
): Promise<LoyaltyAdjustResponse> {
    const { data } = await apiClient.post<{ success: boolean; message: string; data: LoyaltyAdjustResponse }>(
        `/staff/loyalty/${userId}/adjust`,
        payload,
    )
    return data.data
}
