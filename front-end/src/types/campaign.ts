/**
 * Types cho Campaign (Phase 5).
 * Đồng bộ ManagerCampaignController + PublicCampaignController (back-end).
 */

import type { PromotionKind } from './promotion'

/** Campaign từ Manager API (đầy đủ — internal). */
export interface ManagerCampaign {
    id: number
    slug: string
    name: string
    description: string | null
    cover_image_url: string | null
    starts_at: string | null
    ends_at: string | null
    is_active: boolean
    created_at: string | null
    promotions_count: number
    is_live: boolean
    is_within_window: boolean
}

/** Campaign từ Manager API — chi tiết (kèm promotions). */
export interface ManagerCampaignDetail extends ManagerCampaign {
    promotions: CampaignPromotionRef[]
}

export interface CampaignPromotionRef {
    id: number
    slug: string
    name: string
    kind: PromotionKind
    kind_label: string
    badge: string
    is_active: boolean
    priority: number
}

/** Campaign public (customer view). */
export interface PublicCampaign {
    id: number
    slug: string
    name: string
    cover_image_url: string | null
    description: string | null
    starts_at: string | null
    ends_at: string | null
}

/** Public campaign detail (kèm promotion + voucher). */
export interface PublicCampaignDetail {
    id: number
    slug: string
    name: string
    description: string | null
    cover_image_url: string | null
    starts_at: string | null
    ends_at: string | null
    promotions: PublicCampaignPromotion[]
    vouchers: PublicCampaignVoucher[]
}

export interface PublicCampaignPromotion {
    id: number
    slug: string
    name: string
    description: string | null
    kind: PromotionKind
    kind_label: string
    badge: string
    config: Record<string, unknown>
    conditions: Record<string, unknown>
    ends_at: string | null
}

export interface PublicCampaignVoucher {
    id: number
    code: string
    name: string
    type: 'PERCENT' | 'FIXED'
    value: number
    min_order_subtotal: number
    ends_at: string | null
    badge: string
}

/** Stats cho campaign (manager). */
export interface CampaignStats {
    campaign_id: number
    promotions_count: number
    promotions_active: number
    vouchers_count: number
    total_uses: number
    total_discount: number
    is_live: boolean
}

/** Payload cho store/update. */
export interface CampaignPayload {
    name: string
    slug?: string | null
    description?: string | null
    cover_image_url?: string | null
    starts_at?: string | null
    ends_at?: string | null
    is_active?: boolean
    marketing_meta?: Record<string, unknown> | null
}

/** Params list. */
export interface ListCampaignsParams {
    search?: string
    is_active?: boolean
    from?: string
    to?: string
    page?: number
    per_page?: number
    all?: boolean // bỏ filter is_active (cho dropdown)
}
