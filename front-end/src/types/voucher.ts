/**
 * Types cho Voucher + Loyalty.
 * Đồng bộ với VoucherController.php + LoyaltyController.php (back-end).
 */

/** ===== Voucher ===== */

/** Voucher trả về từ API (list / preview / CRUD). */
export interface Voucher {
    id: number
    code: string
    name: string
    description: string | null
    type: 'PERCENT' | 'FIXED'
    value: number
    max_discount_amount: number | null
    min_order_subtotal: number
    usage_limit: number | null
    used_count: number
    usage_limit_per_user: number | null
    starts_at: string | null
    ends_at: string | null
    is_active: boolean
    created_at: string | null
    /** Nhãn tiện dụng (vd "-10%", "-20.000đ") cho UI. */
    badge: string
}

/** Preview voucher (kết quả từ /api/vouchers/preview). */
export interface VoucherPreview {
    code: string
    name: string
    type: 'PERCENT' | 'FIXED'
    value: number
    /** Số tiền giảm thực tế cho cart hiện tại (VND). */
    discount: number
    min_order_subtotal: number
}

/** Trong payload checkout kèm `voucher_code` (optional). */
export interface CheckoutPayloadVoucherExtension {
    voucher_code?: string | null
}

/** ===== Manager (Phase 3) ===== */

/** Voucher từ manager endpoint — bổ sung is_within_window, has_global_quota. */
export interface ManagerVoucher extends Voucher {
    is_within_window: boolean
    has_global_quota: boolean
}

/** Một lượt dùng voucher (drawer lịch sử). */
export interface ManagerVoucherUsage {
    id: number
    order_id: number
    order_code: string | null
    order_total: number | null
    user_id: number
    user_name: string | null
    discount_amount: number
    created_at: string | null
}

/** Pagination meta chung. */
export interface Paginated<T> {
    data: T[]
    meta: {
        total: number
        per_page: number
        current_page: number
        last_page: number
    }
}

/** ===== Loyalty ===== */

export type LoyaltyEntryType = 'EARN' | 'REDEEM' | 'ADJUST'

export interface LoyaltyEntry {
    id: number
    type: LoyaltyEntryType
    /** Dương = cộng, âm = trừ. */
    points: number
    balance_after: number
    reason: string | null
    order_id: number | null
    created_at: string | null
}

export interface LoyaltyResponse {
    balance: number
    items: LoyaltyEntry[]
    meta: {
        current_page: number
        last_page: number
        per_page: number
        total: number
    }
}