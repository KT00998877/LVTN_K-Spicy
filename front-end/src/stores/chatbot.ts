/**
 * Chatbot Store - Phase C3
 * 
 * Manages chatbot state including:
 * - Session management
 * - Message history
 * - Pending actions for confirmation
 * - F5 recovery
 */
import { defineStore } from 'pinia'
import * as chatbotApi from '@/api/chatbot'
import type { ChatMessage, ChatSessionSummary, PendingAction, ConfirmationSummary } from '@/api/chatbot'

interface ChatState {
    isOpen: boolean
    sessionId: number | null
    messages: ChatMessage[]
    pendingActions: Map<number, PendingAction>
    loading: boolean
    sending: boolean
    error: string | null
    sessionStatus: 'active' | 'closed' | null
    retryMessage: { sessionId: number; content: string; id: string } | null
}

const SESSION_STORAGE_KEY = 'chatbot_session_id'

export const useChatbotStore = defineStore('chatbot', {
    state: (): ChatState => ({
        isOpen: false,
        sessionId: null,
        messages: [],
        pendingActions: new Map(),
        loading: false,
        sending: false,
        error: null,
        sessionStatus: null,
        retryMessage: null,
    }),

    getters: {
        hasPendingActions: (state) => state.pendingActions.size > 0,
        
        pendingActionsList: (state): PendingAction[] => {
            return Array.from(state.pendingActions.values())
        },

        hasUnconfirmedPending: (state): boolean => {
            return Array.from(state.pendingActions.values()).some(
                (pa) => pa.status === 'pending'
            )
        },
    },

    actions: {
        // ===== Session Management =====

        async open(): Promise<void> {
            this.isOpen = true
        },

        close(): void {
            this.isOpen = false
        },

        toggle(): void {
            if (this.isOpen) {
                this.close()
            } else {
                this.open()
                this.ensureSession()
            }
        },

        async ensureSession(): Promise<void> {
            // Check for existing session in storage
            if (this.sessionId) {
                return
            }

            const storedId = sessionStorage.getItem(SESSION_STORAGE_KEY)
            if (storedId) {
                this.sessionId = parseInt(storedId, 10)
                await this.loadSession()
                if (this.sessionId) return
            }

            // Create new session
            await this.startNewConversation()
        },

        async startNewConversation(): Promise<void> {
            this.loading = true
            this.error = null

            try {
                const session = await chatbotApi.startSession()
                this.sessionId = session.id
                this.messages = []
                this.pendingActions.clear()
                this.retryMessage = null
                this.sessionStatus = 'active'
                sessionStorage.setItem(SESSION_STORAGE_KEY, session.id.toString())
                // New conversation, new guest token (or null if logged in).
                if (session.guest_token) {
                    sessionStorage.setItem('chat_guest_token', session.guest_token)
                } else {
                    sessionStorage.removeItem('chat_guest_token')
                }
            } catch (err) {
                this.error = 'Không thể bắt đầu cuộc hội thoại. Vui lòng thử lại.'
                console.error('Chatbot session error:', err)
            } finally {
                this.loading = false
            }
        },

        async loadSession(): Promise<void> {
            if (!this.sessionId) return

            this.loading = true
            this.error = null

            try {
                const session = await chatbotApi.showSession(this.sessionId)
                this.messages = session.messages || []
                this.sessionStatus = session.status as 'active' | 'closed'

                // Load pending actions for recovery
                await this.loadPendingActions()
            } catch (err: unknown) {
                const error = err as { response?: { status?: number } }
                if (error.response?.status === 404) {
                    // Session not found, start fresh
                    sessionStorage.removeItem(SESSION_STORAGE_KEY)
                    this.sessionId = null
                    this.messages = []
                    this.pendingActions.clear()
                } else {
                    this.error = 'Không thể tải cuộc hội thoại.'
                }
            } finally {
                this.loading = false
            }
        },

        async closeSession(): Promise<void> {
            if (!this.sessionId) return

            try {
                await chatbotApi.closeSession(this.sessionId)
                this.sessionStatus = 'closed'
            } catch (err) {
                console.error('Close session error:', err)
            }
        },

        async startNewChat(): Promise<void> {
            sessionStorage.removeItem(SESSION_STORAGE_KEY)
            this.sessionId = null
            this.messages = []
            this.pendingActions.clear()
            await this.startNewConversation()
        },

        // ===== Message Handling =====

        async sendMessage(content: string): Promise<boolean> {
            if (!content.trim() || this.sending || this.loading) return false
            this.sending = true
            this.error = null
            if (!this.sessionId) {
                await this.ensureSession()
            }
            if (!this.sessionId || this.sessionStatus !== 'active') {
                this.error ||= 'Phiên chat chưa sẵn sàng. Vui lòng bắt đầu hội thoại mới.'
                this.sending = false
                return false
            }
            const text = content.trim()
            if (this.retryMessage?.sessionId !== this.sessionId || this.retryMessage.content !== text) {
                this.retryMessage = { sessionId: this.sessionId, content: text, id: crypto.randomUUID() }
            }

            // Add user message optimistically
            const userMessage: ChatMessage = {
                id: Date.now(),
                role: 'user',
                content: content.trim(),
                created_at: new Date().toISOString(),
            }
            this.messages.push(userMessage)

            try {
                const response = await chatbotApi.sendMessage(this.sessionId, text, this.retryMessage.id)

                // Add assistant message
                const assistantMessage: ChatMessage = {
                    id: response.message_id,
                    role: 'assistant',
                    content: response.content,
                    metadata: {
                        intent: response.intent,
                        confirmation_required: response.confirmation_required,
                        pending_action_ids: response.pending_actions?.map(p => p.id),
                        quick_action: response.quick_action,
                        menu_suggestions: response.menu_suggestions,
                    },
                    created_at: new Date().toISOString(),
                }
                this.messages.push(assistantMessage)

                // Update pending actions
                if (response.pending_actions) {
                    for (const pa of response.pending_actions) {
                        this.pendingActions.set(pa.id, { ...pa, status: pa.status || 'pending' })
                    }
                }
                this.retryMessage = null
                return true
            } catch (err) {
                const failure = err as { code?: string; response?: { data?: { message?: string } } }
                this.error = failure.response?.data?.message || (failure.code === 'ECONNABORTED'
                    ? 'Trợ lý phản hồi quá lâu. Bạn có thể gửi lại tin nhắn.'
                    : 'Chưa kết nối được trợ lý. Vui lòng thử lại.')
                console.error('Send message error:', err)

                // Remove optimistic user message on error
                this.messages = this.messages.filter(message => message.id !== userMessage.id)
                return false
            } finally {
                this.sending = false
            }
        },

        // ===== Pending Actions =====

        async loadPendingActions(): Promise<void> {
            if (!this.sessionId || !localStorage.getItem('auth_token')) return

            try {
                const pending = await chatbotApi.getSessionPendingActions(this.sessionId, 'pending')
                this.pendingActions.clear()
                for (const pa of pending) {
                    if (pa.status === 'pending') {
                        this.pendingActions.set(pa.id, pa)
                    }
                }
            } catch (err) {
                console.error('Load pending actions error:', err)
            }
        },

        async confirmAction(actionId: number): Promise<boolean> {
            const action = this.pendingActions.get(actionId)
            if (!action) return false

            try {
                const result = await chatbotApi.confirmPendingAction(actionId)
                action.status = 'confirmed'
                action.result = result.result
                return true
            } catch (err) {
                const error = err as { response?: { status?: number; data?: { message?: string } } }
                action.status = error.response?.status === 410 ? 'expired'
                    : error.response?.status === 502 ? 'failed' : 'pending'
                this.error = error.response?.data?.message || 'Không thể xác nhận. Vui lòng thử lại.'
                return false
            }
        },

        async cancelAction(actionId: number): Promise<boolean> {
            const action = this.pendingActions.get(actionId)
            if (!action) return false

            // Update optimistic UI
            action.status = 'cancelled'

            try {
                await chatbotApi.cancelPendingAction(actionId)
                action.status = 'cancelled'
                return true
            } catch (err) {
                // Revert on error
                action.status = 'pending'
                const error = err as { response?: { data?: { message?: string } } }
                this.error = error.response?.data?.message || 'Không thể hủy. Vui lòng thử lại.'
                return false
            }
        },

        removePendingAction(actionId: number): void {
            this.pendingActions.delete(actionId)
        },

        // ===== Utilities =====

        clearError(): void {
            this.error = null
        },
    },
})
