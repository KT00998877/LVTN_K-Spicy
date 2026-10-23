import apiClient from './client'
import type {
    AuthResponse,
    ChangePasswordPayload,
    ForgotPasswordPayload,
    LoginPayload,
    RegisterPayload,
    ResetPasswordPayload,
    AuthUser,
} from '@/types/auth'

const ENDPOINTS = {
    register:         '/auth/register',
    login:            '/auth/login',
    logout:           '/auth/logout',
    logoutAll:        '/auth/logout-all',
    me:               '/auth/me',
    changePassword:   '/auth/change-password',
    forgotPassword:   '/auth/forgot-password',
    resetPassword:    '/auth/reset-password',
} as const

/** Chuẩn hóa response Laravel validation → object error dễ dùng */
function extractValidationError(err: unknown): Record<string, string[]> {
    const anyErr = err as { response?: { data?: { errors?: Record<string, string[]> } } }
    return anyErr.response?.data?.errors ?? {}
}

export async function register(payload: RegisterPayload): Promise<AuthResponse> {
    try {
        const { data } = await apiClient.post<{ success: boolean; data: AuthResponse }>(
            ENDPOINTS.register,
            payload,
        )
        return data.data
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

export async function login(payload: LoginPayload): Promise<AuthResponse> {
    try {
        const { data } = await apiClient.post<{ success: boolean; data: AuthResponse }>(
            ENDPOINTS.login,
            payload,
        )
        return data.data
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

export async function logout(token?: string): Promise<void> {
    await apiClient.post(ENDPOINTS.logout, undefined, token
        ? { headers: { Authorization: `Bearer ${token}` } } : undefined)
}

export async function logoutAll(): Promise<void> {
    await apiClient.post(ENDPOINTS.logoutAll)
}

export async function fetchMe(): Promise<AuthUser> {
    const { data } = await apiClient.get<{ success: boolean; data: AuthUser }>(ENDPOINTS.me)
    return data.data
}

/**
 * POST /api/auth/me/avatar — upload ảnh đại diện (multipart/form-data).
 * Trả về user đã cập nhật để store có thể đồng bộ ngay.
 */
export async function uploadAvatar(file: File): Promise<AuthUser> {
    try {
        const body = new FormData()
        body.append('avatar', file)
        // KHÔNG đặt Content-Type thủ công — axios sẽ tự gắn kèm boundary.
        // Đặt tay sẽ khiến Laravel không parse được file.
        const { data } = await apiClient.post<{ success: boolean; message: string; data: AuthUser }>(
            `${ENDPOINTS.me}/avatar`,
            body,
        )
        return data.data
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

/** DELETE /api/auth/me/avatar — xoá ảnh đại diện, quay về chữ cái đầu. */
export async function deleteAvatar(): Promise<AuthUser> {
    try {
        const { data } = await apiClient.delete<{ success: boolean; message: string; data: AuthUser }>(
            `${ENDPOINTS.me}/avatar`,
        )
        return data.data
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

/**
 * PUT /api/auth/me — cập nhật thông tin cá nhân (name, full_name, phone).
 */
export async function updateProfile(payload: {
    name?: string
    full_name?: string | null
    phone?: string | null
}): Promise<AuthUser> {
    try {
        const { data } = await apiClient.put<{ success: boolean; message: string; data: AuthUser }>(
            ENDPOINTS.me,
            payload,
        )
        return data.data
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

export async function changePassword(payload: ChangePasswordPayload): Promise<void> {
    try {
        await apiClient.post(ENDPOINTS.changePassword, payload)
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

export async function forgotPassword(payload: ForgotPasswordPayload): Promise<string> {
    try {
        const { data } = await apiClient.post<{ success: boolean; message: string }>(
            ENDPOINTS.forgotPassword,
            payload,
        )
        return data.message
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<string> {
    try {
        const { data } = await apiClient.post<{ success: boolean; message: string }>(
            ENDPOINTS.resetPassword,
            payload,
        )
        return data.message
    } catch (err) {
        throw Object.assign(err as Error, { validationErrors: extractValidationError(err) })
    }
}
