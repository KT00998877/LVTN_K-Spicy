import axios, { CanceledError, type AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { announceDataChange, markDataWrite } from '@/utils/realtime'

/**
 * axios instance dùng chung cho toàn bộ front-end.
 * Đọc base URL từ VITE_API_BASE_URL, fallback về /api (chạy qua Vite proxy).
 */
const apiClient: AxiosInstance = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
    timeout: 15000,
})

/** Read the guest token used for chatbot sessions belonging to anonymous users. */
function getChatGuestToken(): string | null {
    try {
        return sessionStorage.getItem('chat_guest_token')
    } catch {
        return null
    }
}

/**
 * Endpoint ký kênh realtime của live chat.
 *
 * Nó được gọi bởi CẢ nhân viên (dùng `staff_token`) lẫn khách (dùng
 * `auth_token`), nên không thể suy ra token từ URL như các endpoint khác.
 */
const LIVE_CHAT_AUTH_URL = '/live-chat/broadcast-auth'

/** Credentials never cross the customer/staff boundary. */
function pickToken(url: string | undefined): string | null {
    if (!url) return null
    // Caller tự gắn Authorization header — không suy diễn ở đây.
    if (url.includes(LIVE_CHAT_AUTH_URL)) return null
    const isStaff = url.includes('/staff/') || url.includes('/broadcasting/auth')
    if (isStaff) {
        return localStorage.getItem('staff_token')
    }
    return localStorage.getItem('auth_token')
}

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    if (['post', 'put', 'patch', 'delete'].includes(config.method ?? '')) markDataWrite()
    const token = pickToken(config.url)
    if (token && !config.headers.Authorization) {
        config.headers.Authorization = `Bearer ${token}`
    }
    // Chatbot endpoints identify guest sessions by guest_token, not by id.
    // Always forward it alongside Bearer so a pre-login guest session keeps
    // working after the customer logs in (backend resolves both).
    if (config.url?.startsWith('/chatbot/')) {
        const guestToken = getChatGuestToken()
        if (guestToken) config.headers['X-Chat-Guest-Token'] = guestToken
    }
    return config
}, undefined, { synchronous: true })

// ===== Handle 401 globally =====
function staleSession(config: InternalAxiosRequestConfig | undefined): boolean {
    if (!config) return false
    // Endpoint ký kênh live chat dùng token nào tuỳ phía gọi (nhân viên hay
    // khách), nên không thể so sánh với token "hiện tại" của interceptor.
    if (config.url?.includes(LIVE_CHAT_AUTH_URL)) return false
    const sent = config.headers.Authorization ?? null
    const current = pickToken(config.url)
    return sent !== (current ? `Bearer ${current}` : null)
}

apiClient.interceptors.response.use(
    (response) => {
        if (staleSession(response.config)) throw new CanceledError('Session changed')
        if (['post', 'put', 'patch', 'delete'].includes(response.config.method ?? '')) {
            announceDataChange()
        }
        return response
    },
    (error: AxiosError) => {
        // A late 401 from user A must never sign out user B.
        if (staleSession(error.config)) return Promise.reject(new CanceledError('Session changed'))
        // eslint-disable-next-line no-console
        console.error('[API ERROR]', error?.response?.status, error?.config?.url, error.message)

        const url = error.config?.url || ''
        const isStaffApi = url.includes('/staff/') || url.includes('/broadcasting/auth')
        const status = error.response?.status

        // 401 = token hết hạn / không hợp lệ
        if (status === 401) {
            if (isStaffApi) {
                // Token staff hết hạn → xoá staff_token + staff_session
                localStorage.removeItem('staff_token')
                localStorage.removeItem('staff_session')
                const path = window.location.pathname
                const isOnStaffLogin = path === '/dang-nhap' || path === '/staff/login' || path === '/staff/'
                if (!isOnStaffLogin) {
                    window.location.href = '/dang-nhap?expired=1'
                }
            } else {
                // Token customer hết hạn → xoá token customer
                localStorage.removeItem('auth_token')
                localStorage.removeItem('auth_user')
                window.dispatchEvent(new Event('customer-session-expired'))
                const path = window.location.pathname
                if (
                    !path.startsWith('/dang-nhap') &&
                    !path.startsWith('/dang-ky') &&
                    !path.startsWith('/dat-lai-mat-khau') &&
                    !path.startsWith('/quen-mat-khau')
                ) {
                    window.location.href = '/dang-nhap?expired=1'
                }
            }
        }

        return Promise.reject(error)
    },
)

export default apiClient
