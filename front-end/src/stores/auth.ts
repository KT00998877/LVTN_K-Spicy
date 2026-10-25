import { beginRead } from '@/utils/realtime'
import { defineStore } from 'pinia'
import { useStaffStore } from './staff'
import { useCartStore } from './cart'
import { useOrderStore } from './orders'
import { useFavoritesStore } from './favorites'
import * as authApi from '@/api/auth'
import type { AuthUser, LoginPayload, RegisterPayload } from '@/types/auth'

const STORAGE_TOKEN = 'auth_token'
const STORAGE_USER = 'auth_user'

interface AuthError extends Error {
    validationErrors?: Record<string, string[]>
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null as AuthUser | null,
        token: null as string | null,
        loading: false,
        error: null as string | null,
        validationErrors: {} as Record<string, string[]>,
        // user vừa đăng ký → cần hiển thị thông báo đặc biệt
        justRegistered: false,
        twoFactorChallenge: null as string | null,
        sessionRevision: 0,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,
        isActive: (state) => state.user?.is_active ?? false,
        displayName: (state) => state.user?.full_name || state.user?.name || '',
    },

    actions: {
        resetCustomerData(): void {
            try {
                if (this.user) sessionStorage.removeItem(`checkout_pending:${this.user.id}`)
            } catch { /* Always clear memory even when browser storage is unavailable. */ }
            this.sessionRevision++
            useCartStore().$reset()
            useOrderStore().$reset()
            useFavoritesStore().$reset()
        },

        clearSession(): void {
            this.resetCustomerData()
            this.user = null
            this.token = null
            this.loading = false
            this.error = null
            this.validationErrors = {}
            this.justRegistered = false
            this.twoFactorChallenge = null
            this.clearStorage()
        },

        isCurrentSession(revision: number, token: string | null): boolean {
            return !!token && this.sessionRevision === revision && this.token === token
                && localStorage.getItem(STORAGE_TOKEN) === token
        },
        /**
         * Khởi tạo từ localStorage (gọi 1 lần khi app load).
         */
        init(): void {
            const token = localStorage.getItem(STORAGE_TOKEN)
            const userJson = localStorage.getItem(STORAGE_USER)
            if (token && userJson) {
                if (this.token === token && this.user) return
                try {
                    const user = JSON.parse(userJson) as AuthUser
                    if (!user || !Number.isInteger(user.id)) throw new Error('Invalid session')
                    if (this.token !== token || this.user?.id !== user.id) this.resetCustomerData()
                    this.token = token
                    this.user = user
                } catch {
                    this.clearSession()
                }
            } else if (this.token || this.user) {
                this.clearSession()
            }
        },

        persist(): void {
            if (this.token && this.user) {
                localStorage.setItem(STORAGE_TOKEN, this.token)
                localStorage.setItem(STORAGE_USER, JSON.stringify(this.user))
            } else {
                this.clearStorage()
            }
        },

        clearStorage(): void {
            localStorage.removeItem(STORAGE_TOKEN)
            localStorage.removeItem(STORAGE_USER)
        },

        /**
         * Đăng ký → auto login → lưu token.
         */
        async register(payload: RegisterPayload): Promise<boolean> {
            this.loading = true
            this.error = null
            this.validationErrors = {}
            try {
                const result = await authApi.register(payload)
                this.clearSession()
                this.user = result.user
                this.token = result.token
                this.persist()
                this.justRegistered = true
                return true
            } catch (err) {
                const e = err as AuthError
                this.validationErrors = e.validationErrors ?? {}
                this.error = this.extractErrorMessage(e) ?? 'Đăng ký thất bại. Vui lòng thử lại.'
                return false
            } finally {
                this.loading = false
            }
        },

        /**
         * Đăng nhập.
         */
        async login(payload: LoginPayload): Promise<'authenticated' | 'two_factor' | false> {
            this.loading = true
            this.error = null
            this.validationErrors = {}
            try {
                const result = await authApi.login(payload)
                if (result.requires_two_factor) {
                    this.twoFactorChallenge = result.challenge_token
                    return 'two_factor'
                }
                const staff = useStaffStore()
                staff.clearLocalSession()
                this.clearSession()
                if (result.primary_role) {
                    staff.acceptLogin({ ...result, primary_role: result.primary_role, roles: result.roles ?? [] })
                    this.justRegistered = false
                    return 'authenticated'
                }
                this.user = result.user
                this.token = result.token
                this.persist()
                this.justRegistered = false
                return 'authenticated'
            } catch (err) {
                const e = err as AuthError
                this.validationErrors = e.validationErrors ?? {}
                this.error = this.extractErrorMessage(e) ?? 'Đăng nhập thất bại. Vui lòng thử lại.'
                return false
            } finally {
                this.loading = false
            }
        },

        async verifyTwoFactor(code: string): Promise<boolean> {
            if (!this.twoFactorChallenge) return false
            this.loading = true
            this.error = null
            this.validationErrors = {}
            try {
                const result = await authApi.verifyTwoFactor(this.twoFactorChallenge, code)
                const staff = useStaffStore()
                staff.clearLocalSession()
                this.clearSession()
                if (!result.primary_role) throw new Error('Tài khoản quản lý không có vai trò hợp lệ.')
                staff.acceptLogin({
                    ...result,
                    primary_role: result.primary_role,
                    roles: result.roles ?? [],
                })
                return true
            } catch (err) {
                const e = err as AuthError
                this.validationErrors = e.validationErrors ?? {}
                this.error = this.extractErrorMessage(e) ?? 'Không thể xác minh mã bảo mật.'
                return false
            } finally {
                this.loading = false
            }
        },

        cancelTwoFactor(): void {
            this.twoFactorChallenge = null
            this.error = null
            this.validationErrors = {}
        },

        /**
         * Đăng xuất — gọi API để revoke token, rồi xóa local.
         */
        async logout(): Promise<void> {
            const token = this.token
            this.clearSession()
            try {
                if (token) await authApi.logout(token)
            } catch {
                // Ignore network error — vẫn logout local
            }
        },

        /**
         * Refresh thông tin user (vd sau khi đổi profile).
         */
        async refreshLiveProfile(): Promise<void> {
            const revision = this.sessionRevision
            const token = this.token
            if (!this.isCurrentSession(revision, token) || this.loading) return
            const fresh = beginRead(this, 'profile', true)
            const user = await authApi.fetchMe()
            if (fresh() && this.isCurrentSession(revision, token) && !this.loading) {
                this.user = user
                this.persist()
            }
        },

        async refreshMe(): Promise<void> {
            const revision = this.sessionRevision
            const token = this.token
            if (!this.isCurrentSession(revision, token)) return
            try {
                const user = await authApi.fetchMe()
                if (!this.isCurrentSession(revision, token)) return
                this.user = user
                this.persist()
            } catch {
                if (this.isCurrentSession(revision, token)) await this.logout()
            }
        },

        /**
         * Upload ảnh đại diện. Trả về true nếu thành công.
         */
        async uploadAvatar(file: File): Promise<boolean> {
            const revision = this.sessionRevision
            const token = this.token
            if (!this.isCurrentSession(revision, token)) return false
            this.loading = true
            this.error = null
            this.validationErrors = {}
            try {
                const user = await authApi.uploadAvatar(file)
                if (!this.isCurrentSession(revision, token)) return false
                this.user = user
                this.persist()
                return true
            } catch (err) {
                if (!this.isCurrentSession(revision, token)) return false
                const e = err as AuthError
                this.validationErrors = e.validationErrors ?? {}
                this.error = this.extractErrorMessage(e) ?? 'Không tải được ảnh lên.'
                return false
            } finally {
                if (this.isCurrentSession(revision, token)) this.loading = false
            }
        },

        /**
         * Xoá ảnh đại diện. Trả về true nếu thành công.
         */
        async deleteAvatar(): Promise<boolean> {
            const revision = this.sessionRevision
            const token = this.token
            if (!this.isCurrentSession(revision, token)) return false
            this.loading = true
            this.error = null
            try {
                const user = await authApi.deleteAvatar()
                if (!this.isCurrentSession(revision, token)) return false
                this.user = user
                this.persist()
                return true
            } catch (err) {
                if (!this.isCurrentSession(revision, token)) return false
                const e = err as AuthError
                this.error = this.extractErrorMessage(e) ?? 'Không xoá được ảnh.'
                return false
            } finally {
                if (this.isCurrentSession(revision, token)) this.loading = false
            }
        },

        /**
         * Cập nhật thông tin cá nhân (name, full_name, phone).
         * Trả về true nếu thành công, false nếu thất bại (lưu validationErrors).
         */
        async updateProfile(payload: {
            name?: string
            full_name?: string | null
            phone?: string | null
        }): Promise<boolean> {
            const revision = this.sessionRevision
            const token = this.token
            if (!this.isCurrentSession(revision, token)) return false
            this.loading = true
            this.error = null
            this.validationErrors = {}
            try {
                const user = await authApi.updateProfile(payload)
                if (!this.isCurrentSession(revision, token)) return false
                this.user = user
                this.persist()
                return true
            } catch (err) {
                if (!this.isCurrentSession(revision, token)) return false
                const e = err as AuthError
                this.validationErrors = e.validationErrors ?? {}
                this.error = this.extractErrorMessage(e) ?? 'Cập nhật thất bại. Vui lòng thử lại.'
                return false
            } finally {
                if (this.isCurrentSession(revision, token)) this.loading = false
            }
        },

        /**
         * Trích message lỗi từ Laravel response.
         */
        extractErrorMessage(err: AuthError): string | null {
            // Ưu tiên lấy message từ validation error đầu tiên
            const ve = err.validationErrors
            if (ve) {
                for (const key of Object.keys(ve)) {
                    const msgs = ve[key]
                    if (msgs && msgs.length > 0) return msgs[0] ?? null
                }
            }
            const anyErr = err as { response?: { data?: { message?: string } } }
            return anyErr.response?.data?.message ?? null
        },
    },
})
