/**
 * Staff Store — quản lý state cho khu vực nhân viên.
 *
 * Đăng nhập/đăng xuất gọi API THẬT (Laravel + Sanctum).
 * Không còn dùng staffMock hay localStorage chứa dữ liệu ảo.
 *
 * Lưu trữ:
 *  - `staff_token`  : Sanctum token (do apiClient tự đính kèm qua Bearer)
 *  - `staff_session`: {user_id, name, email, role, login_at, roles[]}
 *
 * Lưu ý: KHÔNG đụng `auth_token` / `auth_user` — đó là của customer.
 */
import { defineStore } from 'pinia'
import type { StaffRole } from '@/types/staff'
import { ROLE_LABELS } from '@/types/staff'
import {
    staffAuth,
    type StaffUser,
    hasStaffToken,
} from '@/api/staff'

export interface StaffSession {
    user_id: number
    name: string
    email: string
    role: StaffRole
    roles: string[]
    login_at: string
}

const TOKEN_KEY  = 'staff_token'
const SESSION_KEY = 'staff_session'

export const useStaffStore = defineStore('staff', {
    state: () => ({
        session: null as StaffSession | null,
        user: null as StaffUser | null,
        loading: false,
        error: null as string | null,
        /** Cờ đánh dấu đã hydrate từ localStorage + verify qua /me (chạy 1 lần). */
        initialized: false,
    }),

    getters: {
        isLoggedIn: (state) => !!state.session && !!state.user,
        currentRole: (state): StaffRole | null => state.session?.role ?? null,
        currentUserId: (state) => state.session?.user_id ?? null,
        roleInfo: (state) =>
            state.session ? ROLE_LABELS[state.session.role] : null,
        /** Lấy tất cả roles của user hiện tại */
        userRoles: (state) => state.session?.roles ?? [],
    },

    actions: {
        acceptLogin(data: { user: StaffUser; token: string; roles: string[]; primary_role: StaffRole }): void {
            this.clearLocalSession()
            this.user = data.user
            this.session = {
                user_id: data.user.id, name: data.user.name, email: data.user.email,
                role: data.primary_role, roles: data.roles, login_at: new Date().toISOString(),
            }
            localStorage.setItem(TOKEN_KEY, data.token)
            localStorage.setItem(SESSION_KEY, JSON.stringify(this.session))
            this.initialized = true
        },
        /**
         * Khởi tạo từ localStorage + verify token qua API.
         * - Idempotent: chỉ chạy đầy đủ 1 lần; lần gọi sau trả về Promise đã resolve.
         * - Nếu token hết hạn → xoá session + token, set initialized=true.
         */
        async init(): Promise<void> {
            if (this.initialized) return

            // Khôi phục vai trò từ server, không tin role lưu trong localStorage.
            if (hasStaffToken()) {
                try {
                    const me = await staffAuth.me()
                    if (!Object.prototype.hasOwnProperty.call(ROLE_LABELS, me.primary_role)) {
                        throw new Error('Vai trò nhân viên không hợp lệ.')
                    }
                    this.user = me.user
                    this.session = {
                        user_id: me.user.id,
                        name: me.user.name,
                        email: me.user.email,
                        role: me.primary_role as StaffRole,
                        roles: me.roles,
                        login_at: new Date().toISOString(),
                    }
                    localStorage.setItem(SESSION_KEY, JSON.stringify(this.session))
                } catch {
                    // Token hết hạn / không hợp lệ → xoá
                    this.clearLocalSession()
                }
            } else if (!hasStaffToken()) {
                this.session = null
                this.user = null
            }
            this.initialized = true
        },

        /**
         * Đăng nhập qua API thật.
         * @param email email nhân viên
         * @param password mật khẩu
         */
        async login(email: string, password: string): Promise<StaffSession> {
            this.loading = true
            this.error = null
            try {
                const data = await staffAuth.login({ email, password })
                this.user = data.user

                const session: StaffSession = {
                    user_id: data.user.id,
                    name: data.user.name,
                    email: data.user.email,
                    role: data.primary_role as StaffRole,
                    roles: data.roles,
                    login_at: new Date().toISOString(),
                }
                this.session = session
                localStorage.setItem(SESSION_KEY, JSON.stringify(session))
                return session
            } catch (err: any) {
                // Lấy message lỗi từ Laravel (ValidationException message)
                const apiMessage =
                    err?.response?.data?.message ||
                    err?.response?.data?.errors?.email?.[0] ||
                    err?.message ||
                    'Đăng nhập thất bại.'
                this.error = apiMessage
                throw new Error(apiMessage)
            } finally {
                this.loading = false
            }
        },

        /**
         * Đăng xuất: gọi API + xoá localStorage.
         */
        async logout(): Promise<void> {
            this.loading = true
            try {
                await staffAuth.logout()
            } catch {
                // Bỏ qua — vẫn xoá local
            } finally {
                this.clearLocalSession()
                this.loading = false
            }
        },

        /** Xoá session local (không gọi API). */
        clearLocalSession(): void {
            this.session = null
            this.user = null
            this.error = null
            localStorage.removeItem(SESSION_KEY)
            localStorage.removeItem(TOKEN_KEY)
        },

        hasRole(...roles: StaffRole[]): boolean {
            return this.session !== null && roles.includes(this.session.role)
        },
    },
})
