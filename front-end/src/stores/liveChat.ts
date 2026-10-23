/**
 * Live Chat Store — phía khách cho Tư vấn trực tiếp.
 *
 * Tách khỏi `stores/chatbot` một cách có chủ đích: chatbot có pending action,
 * xác nhận đơn hàng và mô hình RAG riêng; trộn hai luồng vào một store sẽ khiến
 * mọi thay đổi trạng thái chatbot ảnh hưởng sang tư vấn và ngược lại.
 *
 * Tư vấn trực tiếp CHỈ dành cho khách đã đăng nhập. Khách vãng lai vẫn dùng
 * được chatbot, nhưng mở phiên tư vấn thì cần đăng nhập trước.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import * as liveChatApi from '@/api/liveChat'
import type { LiveChatMessage, LiveChatSession, LiveChatStatus } from '@/api/liveChat'

const SESSION_STORAGE_KEY = 'live_chat_session_id'

/**
 * Payload ký kênh mà Pusher-js mong đợi. Package không export kiểu này từ
 * gốc nên khai báo tại chỗ theo đúng định dạng server trả về.
 */
interface ChannelAuthData {
    auth: string
    channel_data?: string
}

export const useLiveChatStore = defineStore('liveChat', () => {
    const isOpen = ref(false)
    const sessionId = ref<number | null>(null)
    const status = ref<LiveChatStatus | null>(null)
    const messages = ref<LiveChatMessage[]>([])
    const staffName = ref<string | null>(null)
    const loading = ref(false)
    const sending = ref(false)
    const error = ref<string | null>(null)
    const connected = ref(false)
    /** Khách chưa đăng nhập → widget mời đăng nhập thay vì mở phiên. */
    const requiresLogin = ref(false)

    let echo: Echo<'reverb'> | null = null
    let generation = 0

    /**
     * Khách đã gửi tin nhắn nào chưa.
     *
     * Nhân viên chỉ "nhận" phiên khi khách đã nói — nếu mở phiên thì màn
     * hình nhân viên sẽ hiện "đang chờ" vô nghĩa và hàng chờ bị nhiễu. Cờ này
     * quyết định có hiện dòng "Đang chờ nhân viên tư vấn trả lời" hay không.
     */
    const hasCustomerMessage = computed(() =>
        messages.value.some((m) => m.sender_type === 'customer'),
    )

    // ===== Session =====

    function open(): void {
        isOpen.value = true
        if (sessionId.value && !sending.value) void loadSession()
        else void ensureSession()
    }

    function close(): void {
        isOpen.value = false
    }

    function toggle(): void {
        if (isOpen.value) close()
        else open()
    }

    /**
     * Mở phiên nếu chưa có, hoặc khôi phục phiên đang dang dở.
     *
     * Phiên được nhớ trong sessionStorage nên F5 không mất cuộc trò chuyện —
     * khách không phải kể lại từ đầu.
     */
    async function ensureSession(): Promise<void> {
        if (sessionId.value) return

        const storedId = sessionStorage.getItem(SESSION_STORAGE_KEY)
        if (storedId) {
            sessionId.value = Number(storedId)
            await loadSession()
            if (sessionId.value && status.value !== 'closed') return
        }

        await startNewSession()
    }

    async function startNewSession(): Promise<void> {
        loading.value = true
        error.value = null
        requiresLogin.value = false
        try {
            const session = await liveChatApi.startSession()
            sessionId.value = session.id
            status.value = session.status
            staffName.value = session.assigned_staff_name ?? null
            messages.value = []
            sessionStorage.setItem(SESSION_STORAGE_KEY, String(session.id))
            subscribe(session.id)
        } catch (err) {
            const failure = err as { response?: { status?: number; data?: { message?: string } } }
            if (failure.response?.status === 401) {
                // Khách chưa đăng nhập → hiện lời mời đăng nhập, không báo lỗi.
                requiresLogin.value = true
                error.value = null
            } else {
                // eslint-disable-next-line no-console
                console.error('Live chat session error:', err)
                error.value = failure.response?.data?.message
                    ?? 'Không thể bắt đầu tư vấn trực tiếp. Vui lòng thử lại.'
            }
        } finally {
            loading.value = false
        }
    }

    async function loadSession(): Promise<void> {
        if (!sessionId.value) return
        loading.value = true
        try {
            const session = await liveChatApi.showSession(sessionId.value)
            messages.value = session.messages ?? []
            status.value = session.status
            staffName.value = session.assigned_staff_name ?? null
            requiresLogin.value = false
            subscribe(session.id)
        } catch (err) {
            const failure = err as { response?: { status?: number } }
            if (failure.response?.status === 404) {
                // Phiên đã bị xoá phía server → bắt đầu phiên mới thay vì báo lỗi.
                forgetSession()
                await startNewSession()
            } else if (failure.response?.status === 401) {
                forgetSession()
                requiresLogin.value = true
            } else {
                error.value = 'Không thể tải cuộc tư vấn.'
            }
        } finally {
            loading.value = false
        }
    }

    async function startNewChat(): Promise<void> {
        forgetSession()
        await startNewSession()
    }

    async function closeSession(): Promise<void> {
        if (!sessionId.value) return
        try {
            await liveChatApi.closeSession(sessionId.value)
            status.value = 'closed'
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Close live chat error:', err)
        }
    }

    function forgetSession(): void {
        unsubscribe()
        sessionId.value = null
        status.value = null
        messages.value = []
        staffName.value = null
        sessionStorage.removeItem(SESSION_STORAGE_KEY)
    }

    // ===== Messages =====

    async function sendMessage(content: string): Promise<boolean> {
        const text = content.trim()
        if (!text || sending.value || !sessionId.value) return false

        sending.value = true
        error.value = null
        try {
            const result = await liveChatApi.sendMessage(sessionId.value, text)
            // Tin nhắn của chính mình cũng đến qua Echo, nên chỉ thêm nếu chưa có
            // để không hiện hai lần.
            pushMessage(result.message)
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Send live chat error:', err)
            error.value = 'Chưa gửi được tin nhắn. Vui lòng thử lại.'
            return false
        } finally {
            sending.value = false
        }
        return true
    }

    function pushMessage(message: LiveChatMessage): void {
        if (messages.value.some((m) => m.id === message.id)) return
        messages.value.push(message)
    }

    function clearError(): void {
        error.value = null
    }

    // ===== Realtime =====

    /**
     * Nghe tin nhắn mới của phiên đang mở.
     *
     * Khách có thể là người gửi, nên phải lọc theo phiên hiện tại: Echo trả về
     * event cho mọi kênh đã subscribe, và còn sót phiên cũ sẽ đẩy tin nhắn của
     * cuộc trò chuyện trước vào khung mới.
     */
    function subscribe(id: number): void {
        unsubscribe()
        const currentGeneration = ++generation

        const key = import.meta.env.VITE_REVERB_APP_KEY
        if (!key) {
            // Không có realtime: widget vẫn dùng được qua HTTP, chỉ chậm hơn.
            return
        }

        const secure = (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '')) === 'https'
        const port = Number(import.meta.env.VITE_REVERB_PORT ?? (secure ? 443 : 8080))
        const client = new Pusher(key, {
            cluster: 'mt1',
            wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: secure,
            enabledTransports: ['ws', 'wss'],
            channelAuthorization: {
                customHandler: (params, callback) => {
                    void authenticateChannel(params).then(
                        (payload) => callback(null, payload),
                        () => callback(new Error('Không thể xác thực kênh chat'), null),
                    )
                },
            },
        })

        echo = new Echo({ broadcaster: 'reverb', client })
        client.connection.bind('state_change', ({ current }: { current: string }) => {
            if (generation !== currentGeneration) return
            connected.value = current === 'connected'
        })

        echo
            .private(`live-chat-session.${id}`)
            .listen('.live-chat.message.sent', (event: { session_id: number; message: LiveChatMessage; session: LiveChatSession }) => {
                if (generation !== currentGeneration) return
                if (event.session_id !== sessionId.value) return
                pushMessage(event.message)
                if (event.session?.status) {
                    status.value = event.session.status
                    staffName.value = event.session.assigned_staff_name ?? staffName.value
                }
            })
    }

    function unsubscribe(): void {
        generation++
        echo?.disconnect()
        echo = null
        connected.value = false
    }

    return {
        isOpen, sessionId, status, messages, staffName, loading, sending, error, connected,
        requiresLogin, hasCustomerMessage,
        open, close, toggle, ensureSession, startNewSession, startNewChat, loadSession,
        sendMessage, closeSession, clearError,
    }
})

/**
 * Xác thực kênh realtime của khách.
 *
 * Endpoint này riêng cho live chat vì route broadcasting chuẩn của ứng dụng
 * chỉ cho phép nhân viên; khách tư vấn được cấp quyền bằng token Sanctum của
 * chính khách.
 */
async function authenticateChannel(params: { socketId: string; channelName: string }): Promise<ChannelAuthData> {
    const { default: apiClient } = await import('@/api/client')
    const token = localStorage.getItem('auth_token')
    const response = await apiClient.post(
        '/live-chat/broadcast-auth',
        { socket_id: params.socketId, channel_name: params.channelName },
        { headers: token ? { Authorization: `Bearer ${token}` } : undefined },
    )
    return response.data as ChannelAuthData
}
