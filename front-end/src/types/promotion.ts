/**
 * Types cho Promotion (khuyến mãi tự động — Phase 4).
 * Đồng bộ với App\Models\Promotion, App\Enums\PromotionKind,
 * App\Enums\StackingMode, ManagerPromotionController (back-end).
 */

/** Loại promotion. */
export type PromotionKind = 'PERCENT_OFF' | 'FIXED_OFF' | 'BXGY' | 'COMBO'

/** Stacking mode (áp dụng nhiều promotion cùng lúc). */
export type StackingMode = 'STACK' | 'EXCLUSIVE' | 'BEST_ONLY'

/** Kênh bán — đồng bộ với App\Enums\Channel. */
export type Channel = 'ONLINE' | 'DINE_IN' | 'TAKEAWAY'

/** Phạm vi áp dụng cho PERCENT_OFF / FIXED_OFF. */
export type PromotionAppliesTo = 'ALL' | 'CATEGORY' | 'MENU_ITEM'

/** Phạm vi item (1 dòng trong promotion_items). */
export interface PromotionItemScope {
    id: number
    item_type: 'CATEGORY' | 'MENU_ITEM'
    item_id: number
}

/** Cấu hình riêng theo kind. */
export type PromotionConfig =
    | {
          kind: 'PERCENT_OFF'
          value: number
          max_discount?: number | null
          applies_to: PromotionAppliesTo
          item_ids?: number[]
      }
    | {
          kind: 'FIXED_OFF'
          value: number
          applies_to: PromotionAppliesTo
          item_ids?: number[]
      }
    | {
          kind: 'BXGY'
          buy_qty: number
          get_qty: number
          discount_percent: number
          get_item_ids: number[]
      }
    | {
          kind: 'COMBO'
          bundle_items: { item_id: number; qty: number }[]
          bundle_price: number
      }

/** Điều kiện kích hoạt. */
export interface PromotionConditions {
    min_subtotal?: number
    days_of_week?: number[]
    time_from?: string
    time_to?: string
    channels?: Channel[]
    customer_tags?: string[]
    first_order_only?: boolean
    loyalty_tier_ids?: number[]
}

/** Campaign gắn vào promotion (optional). */
export interface PromotionCampaignRef {
    id: number
    name: string
    slug: string
}

/** Promotion từ API manager — đồng bộ format() trong controller. */
export interface ManagerPromotion {
    id: number
    slug: string
    name: string
    description: string | null
    campaign_id: number | null
    campaign: PromotionCampaignRef | null

    kind: PromotionKind
    kind_label: string
    priority: number
    stacking_mode: StackingMode
    stacking_label: string

    config: PromotionConfig
    conditions: PromotionConditions

    usage_limit: number | null
    used_count: number
    usage_limit_per_user: number | null
    starts_at: string | null
    ends_at: string | null
    is_active: boolean
    created_at: string | null

    items: PromotionItemScope[]

    badge: string
    is_within_window: boolean
    has_global_quota: boolean
}

/** Lượt sử dụng promotion (drawer). */
export interface ManagerPromotionUsage {
    id: number
    order_id: number
    order_code: string | null
    order_total: number | null
    user_id: number | null
    user_name: string | null
    discount_amount: number
    created_at: string | null
}

/** Kết quả preview 1 promotion. */
export interface AppliedPromotionResult {
    promotion_id: number
    slug: string
    kind: PromotionKind
    discount: number
    discount_label: string
}

/** Kết quả preview cart mẫu (POST /api/staff/manager/promotions/preview). */
export interface PromotionPreviewResult {
    applied: AppliedPromotionResult[]
    total_discount: number
    new_subtotal: number
    has_discount: boolean
}

/** Params cho list. */
export interface ListPromotionsParams {
    search?: string
    is_active?: boolean
    kind?: PromotionKind
    campaign_id?: number
    from?: string
    to?: string
    page?: number
    per_page?: number
}

/** Payload gửi lên store / update. */
export interface PromotionPayload {
    name: string
    description?: string | null
    campaign_id?: number | null
    slug?: string
    kind: PromotionKind
    stacking_mode: StackingMode
    priority: number
    config: PromotionConfig
    conditions?: PromotionConditions
    usage_limit?: number | null
    usage_limit_per_user?: number | null
    starts_at?: string | null
    ends_at?: string | null
    is_active?: boolean
    items?: PromotionItemScope[]
}

/** Item cho preview cart mẫu. */
export interface PreviewCartItem {
    menu_item_id: number
    category_id?: number
    quantity: number
    unit_price: number
}
