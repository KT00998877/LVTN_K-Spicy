/**
 * Types cho module Auth (đăng ký / đăng nhập / quên mật khẩu).
 * Đồng bộ với AuthController.php.
 */

/** Hạng thành viên khách hàng (Loyalty Tier) — đồng bộ LoyaltyTierController::formatTier */
export interface LoyaltyTier {
    id: number
    code: string
    name: string
    color: string
    icon: string | null
    /** Hệ số nhân điểm EARN (vd: GOLD = 1.5) */
    point_multiplier: number
    /** Ngưỡng đơn (VND) để được free ship, null = không có */
    free_ship_threshold: number | null
    /** % giảm thêm trên subtotal (vd: GOLD = 5 = 5%) */
    extra_discount_pct: number
}

/** Hạng kế tiếp khách CHƯA đạt — dùng hiển thị tiến trình thăng hạng */
export interface LoyaltyTierNext {
    id: number
    code: string
    name: string
    min_spent: number
    amount_to_next: number
}

export interface AuthUser {
    id: number
    name: string
    full_name: string | null
    email: string
    phone: string | null
    /** URL ảnh đại diện (storage/…), null nếu khách chưa upload. */
    avatar_url: string | null
    is_active: boolean
    can_close_daily_stock: boolean
    /** Tổng điểm thưởng hiện tại của khách (chỉ áp dụng cho CUSTOMER). */
    loyalty_points?: number
    /** Tổng chi tiêu tích lũy (VND) trên các đơn COMPLETED. */
    total_spent?: number
    /** Hạng hiện tại. Null nếu tài khoản chưa được gắn tier (rare). */
    tier?: LoyaltyTier | null
    /** Hạng kế tiếp để hiển thị progress bar. Null = đã ở hạng cao nhất. */
    next_tier?: LoyaltyTierNext | null
    /** % tiến trình tới hạng kế (0-100). Null nếu đã max. */
    tier_progress_percent?: number | null
    created_at: string | null
}

export interface AuthResponse {
    user: AuthUser
    token: string
    roles?: string[]
    primary_role?: import('./staff').StaffRole | null
}

/** Payload đăng ký */
export interface RegisterPayload {
    name: string
    full_name?: string
    email: string
    phone?: string
    password: string
    password_confirmation: string
}

/** Payload đăng nhập */
export interface LoginPayload {
    identifier: string     // email hoặc số điện thoại
    password: string
}

/** Payload đổi mật khẩu */
export interface ChangePasswordPayload {
    current_password: string
    password: string
    password_confirmation: string
}

/** Payload quên mật khẩu */
export interface ForgotPasswordPayload {
    email: string
}

/** Payload reset mật khẩu */
export interface ResetPasswordPayload {
    email: string
    token: string
    password: string
    password_confirmation: string
}

/** Mức độ mạnh của password (frontend dùng để hiển thị meter) */
export type PasswordStrength = 'empty' | 'weak' | 'fair' | 'good' | 'strong'

export interface PasswordStrengthInfo {
    level: PasswordStrength
    score: 0 | 1 | 2 | 3 | 4
    /** Feedback đơn giản cho người dùng */
    hint: string
}
