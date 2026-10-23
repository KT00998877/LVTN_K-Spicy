/**
 * Live Chat API client — tư vấn trực tiếp với nhân viên.
 *
 * Backend endpoints (Laravel):
 *  - POST /api/live-chat/sessions                     → startSession
 *  - GET  /api/live-chat/sessions/{id}                → showSession
 *  - POST /api/live-chat/sessions/{id}/messages       → sendMessage
 *  - POST /api/live-chat/sessions/{id}/close          → closeSession
 *  - POST /api/live-chat/broadcast-auth               → ký kênh realtime
 *
 * Phía nhân viên:
 *  - GET  /api/live-chat/manager/queue                       → managerQueue
 *  - GET  /api/live-chat/manager/sessions/{id}                → managerShowSession
 *  - GET  /api/live-chat/manager/sessions/{id}/suggestions    → managerSuggestions
 *  - POST /api/live-chat/manager/sessions/{id}/accept         → acceptSession
 *  - POST /api/live-chat/manager/sessions/{id}/messages       → managerSendMessage
 *  - POST /api/live-chat/manager/sessions/{id}/close          → managerCloseSession
 */
import apiClient from './client'

// ===== Types =====

export type LiveChatStatus = 'waiting' | 'accepted' | 'closed'

export type LiveChatSenderType = 'customer' | 'staff' | 'system'

export interface LiveChatMessage {
    id: number
    session_id: number
    sender_type: LiveChatSenderType
    sender_id: number | null
    sender_name?: string | null
    content: string
    created_at: string
}

export interface LiveChatSession {
    id: number
    status: LiveChatStatus
    customer_name: string
    customer_phone?: string | null
    topic?: string | null
    assigned_staff_id: number | null
    assigned_staff_name: string | null
    last_message_at: string | null
    created_at: string
    messages?: LiveChatMessage[]
}

export interface LiveChatQueue {
    waiting: LiveChatSession[]
    mine: LiveChatSession[]
}

export interface LiveChatSuggestions {
    suggestions: string[]
    /** `ai` = do AI sinh, `template` = mẫu cố định trong DB. */
    source: 'ai' | 'template'
}

// ===== Phía khách =====

/**
 * Mở phiên tư vấn (hoặc nhận lại phiên đang mở).
 *
 * Yêu cầu đăng nhập — backend trả 401 nếu khách chưa đăng nhập.
 */
export async function startSession(topic?: string): Promise<LiveChatSession> {
    const { data } = await apiClient.post('/live-chat/sessions', topic ? { topic } : {})
    return data.data
}

/** Lấy phiên + lịch sử tin nhắn. */
export async function showSession(id: number): Promise<LiveChatSession> {
    const { data } = await apiClient.get(`/live-chat/sessions/${id}`)
    return data.data
}

/** Gửi tin nhắn tới nhân viên. */
export async function sendMessage(
    sessionId: number,
    content: string,
): Promise<{ message: LiveChatMessage; session: LiveChatSession }> {
    const { data } = await apiClient.post(`/live-chat/sessions/${sessionId}/messages`, { content })
    return data.data
}

/** Kết thúc phiên. */
export async function closeSession(sessionId: number): Promise<LiveChatSession> {
    const { data } = await apiClient.post(`/live-chat/sessions/${sessionId}/close`)
    return data.data
}

// ===== Phía nhân viên =====

/** Hàng chờ + các phiên của tôi. */
export async function managerQueue(): Promise<LiveChatQueue> {
    const { data } = await apiClient.get('/live-chat/manager/queue')
    return data.data
}

/** Chi tiết phiên + lịch sử tin nhắn. */
export async function managerShowSession(id: number): Promise<LiveChatSession> {
    const { data } = await apiClient.get(`/live-chat/manager/sessions/${id}`)
    return data.data
}

/** Gợi ý câu trả lời nhanh (AI, fallback về mẫu trong DB). */
export async function managerSuggestions(id: number): Promise<LiveChatSuggestions> {
    const { data } = await apiClient.get(`/live-chat/manager/sessions/${id}/suggestions`)
    return data.data
}

/** Nhận phiên từ hàng chờ. */
export async function acceptSession(id: number): Promise<LiveChatSession> {
    const { data } = await apiClient.post(`/live-chat/manager/sessions/${id}/accept`)
    return data.data
}

/** Nhân viên gửi tin nhắn. */
export async function managerSendMessage(
    sessionId: number,
    content: string,
): Promise<{ message: LiveChatMessage; session: LiveChatSession }> {
    const { data } = await apiClient.post(`/live-chat/manager/sessions/${sessionId}/messages`, { content })
    return data.data
}

/** Nhân viên kết thúc phiên. */
export async function managerCloseSession(id: number): Promise<LiveChatSession> {
    const { data } = await apiClient.post(`/live-chat/manager/sessions/${id}/close`)
    return data.data
}
