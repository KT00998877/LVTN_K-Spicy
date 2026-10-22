/**
 * Chatbot API client - Phase C3
 * 
 * Backend endpoints (Laravel):
 *  - POST   /api/chatbot/sessions              → startSession
 *  - GET    /api/chatbot/sessions              → listSessions
 *  - GET    /api/chatbot/sessions/{id}         → showSession
 *  - POST   /api/chatbot/sessions/{id}/messages → sendMessage
 *  - POST   /api/chatbot/sessions/{id}/close   → closeSession
 *  - POST   /api/chatbot/pending-actions/{id}/confirm → confirmPendingAction
 *  - POST   /api/chatbot/pending-actions/{id}/cancel → cancelPendingAction
 *  - GET    /api/chatbot/pending-actions/{id}    → getPendingAction
 *  - GET    /api/chatbot/sessions/{id}/pending-actions → getSessionPendingActions
 */
import apiClient from './client'

// ===== Types =====

export interface ChatSessionSummary {
    id: number
    user_id: number | null
    status: 'active' | 'closed'
    created_at: string
    last_message?: string
}

export interface ChatMessage {
    id: number
    role: 'user' | 'assistant' | 'system' | 'tool'
    content: string
    metadata?: {
        intent?: string
        tool_calls?: Array<{ tool: string; args?: Record<string, unknown> }>
        confirmation_required?: boolean
        pending_action_ids?: number[]
        quick_action?: QuickAction
        menu_suggestions?: MenuSuggestion[]
        model_hops?: number
        ai_latency_ms?: number
    } | null
    created_at: string
}

/**
 * A UI affordance the chatbot offers for its reply.
 *
 * The AI only decides WHICH action fits the turn; the frontend owns how it
 * is rendered. `type` is a known identifier — unknown types are ignored.
 */
export interface QuickAction {
    type: 'reservation_form'
    label: string
    title?: string
}

export interface MenuSuggestion {
    name: string
    slug: string
    image_url?: string | null
    description?: string | null
}

export interface PendingAction {
    id: number
    action_type: string
    tool_name: string
    status: 'pending' | 'confirmed' | 'cancelled' | 'expired' | 'failed'
    summary: ConfirmationSummary
    expires_at: string
    result?: Record<string, unknown>
    error_message?: string
}

export interface ConfirmationSummary {
    type: string
    icon: string
    title: string
    items: Array<{ label: string; value: string }>
    price_breakdown?: {
        unit_price: number
        topping_price: number
        quantity: number
        subtotal: number
    }
    toppings?: string[]
}

export interface SendMessageResponse {
    message_id: number
    role: 'assistant'
    content: string
    intent: string
    request_id: string
    confirmation_required?: boolean
    pending_actions?: PendingAction[]
    quick_action?: QuickAction
    menu_suggestions?: MenuSuggestion[]
}

// ===== API calls =====

/**
 * Tạo session mới và lưu guest token (nếu user chưa đăng nhập).
 */
export async function startSession(): Promise<{ id: number; status: string; guest_token?: string | null }> {
    const { data } = await apiClient.post('/chatbot/sessions')
    const result = data.data
    // Persist guest_token so subsequent guest requests can prove ownership.
    if (result.guest_token) {
        try { sessionStorage.setItem('chat_guest_token', result.guest_token) } catch {}
    }
    return result
}

/**
 * Liệt kê sessions của user hiện tại.
 */
export async function listSessions(): Promise<ChatSessionSummary[]> {
    const { data } = await apiClient.get('/chatbot/sessions')
    return data.data
}

/**
 * Lấy chi tiết 1 session (metadata + messages).
 */
export async function showSession(id: number): Promise<{
    id: number
    user_id: number | null
    status: string
    messages: ChatMessage[]
}> {
    const { data } = await apiClient.get(`/chatbot/sessions/${id}`)
    return data.data
}

/**
 * Gửi message, nhận response từ bot.
 */
export async function sendMessage(
    sessionId: number,
    content: string,
    clientMessageId: string,
): Promise<SendMessageResponse> {
    const { data } = await apiClient.post(
        `/chatbot/sessions/${sessionId}/messages`,
        { message: content, use_tools: true, client_message_id: clientMessageId },
        // Must outlast the Laravel chat timeout (75s) so a slow turn is
        // answered by the server's own error response instead of being cut
        // off by Axios. The server stops at 75s and answers 504, so 90s
        // leaves room for the round trip.
        { timeout: 95000 },
    )
    return data.data
}

/**
 * Đóng session.
 */
export async function closeSession(sessionId: number): Promise<void> {
    await apiClient.post(`/chatbot/sessions/${sessionId}/close`)
}

// ===== Pending Actions =====

/**
 * Xác nhận thực hiện pending action.
 */
export async function confirmPendingAction(actionId: number): Promise<{
    pending_action_id: number
    status: string
    action_type: string
    result: Record<string, unknown>
    confirmed_at: string
}> {
    const { data } = await apiClient.post(`/chatbot/pending-actions/${actionId}/confirm`)
    return data.data
}

/**
 * Hủy pending action.
 */
export async function cancelPendingAction(actionId: number): Promise<{
    pending_action_id: number
    status: string
}> {
    const { data } = await apiClient.post(`/chatbot/pending-actions/${actionId}/cancel`)
    return data.data
}

/**
 * Lấy chi tiết pending action.
 */
export async function getPendingAction(actionId: number): Promise<PendingAction> {
    const { data } = await apiClient.get(`/chatbot/pending-actions/${actionId}`)
    return data.data
}

/**
 * Lấy danh sách pending actions của 1 session (F5 recovery).
 */
export async function getSessionPendingActions(
    sessionId: number,
    status: string = 'pending'
): Promise<PendingAction[]> {
    const { data } = await apiClient.get(
        `/chatbot/sessions/${sessionId}/pending-actions`,
        { params: { status } }
    )
    return data.data.items
}
