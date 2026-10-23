/**
 * Live Chat Manager Store — phía nhân viên cho Tư vấn trực tiếp.
 *
 * Giữ hàng chờ và phiên đang mở trong một store để màn hình chỉ việc vẽ:
 * hàng chờ đổi (có khách mới, ai vừa nhận) đến từ realtime, và tin nhắn của
 * phiên đang chọn cũng đến qua cùng kênh đó.
 *
 * Nhân viên nghe ĐỒNG THỜI nhiều kênh phiên, không chỉ phiên đang mở: nếu
 * chỉ nghe một kênh thì tin nhắn của khách khác chỉ hiện được sau khi nhân
 * viên chuyển tab — đúng lỗi người dùng đã gặp. Tin nhắn tới phiên đang mở
 * hiện thẳng trong khung chat; tin nhắn của phiên khác đánh dấu chưa đọc (chấm
 * đỏ) trên thẻ phiên tương ứng.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import * as liveChatApi from '@/api/liveChat'
import type { LiveChatMessage, LiveChatSession } from '@/api/liveChat'

/**
 * Payload ký kênh Pusher-js mong đợi. Package không export kiểu này từ gốc
 * nên khai báo tại chỗ theo đúng định dạng server trả về.
 */
interface ChannelAuthData {
    auth: string
    channel_data?: string
}

export const useLiveChatManagerStore = defineStore('liveChatManager', () => {
    const waiting = ref<LiveChatSession[]>([])
    const mine = ref<LiveChatSession[]>([])
    const activeId = ref<number | null>(null)
    const messages = ref<LiveChatMessage[]>([])
    const suggestions = ref<string[]>([])
    const suggestionSource = ref<'ai' | 'template' | null>(null)
    const loadingQueue = ref(false)
    const loadingSession = ref(false)
    const loadingSuggestions = ref(false)
    const sending = ref(false)
    const accepting = ref<number | null>(null)
    const error = ref<string | null>(null)
    const connected = ref(false)
    /** Phiên có tin nhắn chưa đọc (không phải phiên đang mở). */
    const unreadSessionIds = ref<number[]>([])

    let echo: Echo<'reverb'> | null = null
    let generation = 0
    /** Kênh đã đăng ký, theo id phiên — để bỏ đăng ký khi không còn cần. */
    const sessionChannels = new Map<number, ReturnType<Echo<'reverb'>['private']>>()

    const activeSession = computed(
        () => [...waiting.value, ...mine.value].find((s) => s.id === activeId.value) ?? null,
    )

    const canReply = computed(() => activeSession.value?.status === 'accepted' && !sending.value)

    // ===== Hàng chờ =====

    async function fetchQueue(): Promise<void> {
        if (loadingQueue.value) return
        loadingQueue.value = true
        try {
            const queue = await liveChatApi.managerQueue()
            waiting.value = queue.waiting
            mine.value = queue.mine
            error.value = null

            // Nghe kênh của MỌI phiên đang mở, không chỉ phiên của mình.
            //
            // Sự kiện `live-chat.session.updated` trên kênh hàng chờ không mang
            // nội dung tin nhắn, nên nếu chỉ nghe hàng chờ thì tin nhắn của khách
            // chưa được nhận sẽ không hiện — nhân viên phải bấm tải lại mới thấy.
            // Nghe kênh từng phiên ngay từ lúc nó vào hàng chờ để chấm đỏ và
            // nội dung hiện ngay khi khách nhắn.
            const visible = [...queue.mine, ...queue.waiting]
            for (const session of visible) {
                subscribeSessionChannel(session.id)
            }
            // Phiên không còn trong hàng chờ (đã đóng / người khác nhận) →
            // bỏ đăng ký kênh cũ.
            const visibleIds = new Set(visible.map((s) => s.id))
            for (const id of [...sessionChannels.keys()]) {
                if (!visibleIds.has(id)) unsubscribeSessionChannel(id)
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Live chat queue error:', err)
            error.value = 'Không tải được hàng chờ tư vấn.'
        } finally {
            loadingQueue.value = false
        }
    }

    // ===== Phiên đang mở =====

    /**
     * Mở một phiên và bắt đầu nghe tin nhắn của nó.
     */
    async function openSession(id: number): Promise<void> {
        if (activeId.value === id && messages.value.length) return
        activeId.value = id
        suggestions.value = []
        suggestionSource.value = null
        loadingSession.value = true
        try {
            const session = await liveChatApi.managerShowSession(id)
            messages.value = session.messages ?? []
            upsertSession(session)
            subscribeSessionChannel(id)
            markRead(id)
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Live chat session error:', err)
            error.value = 'Không mở được cuộc tư vấn.'
        } finally {
            loadingSession.value = false
        }
    }

    function closeSessionView(): void {
        activeId.value = null
        messages.value = []
        suggestions.value = []
        suggestionSource.value = null
    }

    async function acceptSession(id: number): Promise<boolean> {
        accepting.value = id
        error.value = null
        try {
            await liveChatApi.acceptSession(id)
            // Nạp lại hàng chờ: phiên vừa nhận phải rời hàng chờ và sang danh
            // sách của tôi, nếu không bảng sẽ hiện sai trạng thái.
            await fetchQueue()
            await openSession(id)
            return true
        } catch (err) {
            const failure = err as { response?: { status?: number; data?: { message?: string } } }
            // Người khác có thể đã nhận trước → đồng bộ lại hàng chờ trước,
            // vì `fetchQueue` xoá `error` khi thành công.
            if (failure.response?.status === 409) await fetchQueue()
            error.value = failure.response?.data?.message ?? 'Không nhận được cuộc tư vấn.'
            return false
        } finally {
            accepting.value = null
        }
    }

    async function sendMessage(content: string, fromSuggestion = false): Promise<boolean> {
        const text = content.trim()
        if (!text || !activeId.value || !canReply.value) return false

        sending.value = true
        error.value = null
        try {
            const result = await liveChatApi.managerSendMessage(activeId.value, text)
            pushMessage(result.message)
            upsertSession(result.session)
            // Gợi ý cũ đã hết liên quan tới lượt mới; giữ lại sẽ khiến nhân viên
            // bấm nhầm câu đã trả lời.
            suggestions.value = []
            suggestionSource.value = null
            return true
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Send staff message error:', err)
            error.value = 'Chưa gửi được tin nhắn. Vui lòng thử lại.'
            return false
        } finally {
            sending.value = false
        }
    }

    async function closeSession(id: number): Promise<void> {
        try {
            const session = await liveChatApi.managerCloseSession(id)
            upsertSession(session)
            await fetchQueue()
            if (activeId.value === id) closeSessionView()
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Close session error:', err)
            error.value = 'Không kết thúc được cuộc tư vấn.'
        }
    }

    // ===== Gợi ý nhanh =====

    /**
     * Lấy gợi ý câu trả lời.
     *
     * Gợi ý không phải lời đúng — nhân viên đọc và sửa trước khi gửi, nên phía
     * server đã loại các câu quá dài và câu trùng lặp.
     */
    async function loadSuggestions(): Promise<void> {
        if (!activeId.value || loadingSuggestions.value) return
        loadingSuggestions.value = true
        try {
            const result = await liveChatApi.managerSuggestions(activeId.value)
            suggestions.value = result.suggestions
            suggestionSource.value = result.source
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('Live chat suggestions error:', err)
            // Không gợi ý vẫn gõ tay được, nên đây không phải lỗi nghiêm trọng.
            suggestions.value = []
            suggestionSource.value = null
        } finally {
            loadingSuggestions.value = false
        }
    }

    function clearError(): void {
        error.value = null
    }

    // ===== Realtime =====

    function start(): void {
        stop()
        const currentGeneration = ++generation
        void fetchQueue()

        const key = import.meta.env.VITE_REVERB_APP_KEY
        if (!key) {
            error.value = 'Chưa cấu hình kết nối real-time. Hàng chờ vẫn dùng được.'
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
                        () => callback(new Error('Không thể xác thực kênh tư vấn'), null),
                    )
                },
            },
        })

        echo = new Echo({ broadcaster: 'reverb', client })
        client.connection.bind('state_change', ({ current }: { current: string }) => {
            if (generation !== currentGeneration) return
            connected.value = current === 'connected'
        })

        // Kênh hàng chờ dùng chung cho mọi nhân viên: có khách mới thì ai cũng
        // phải thấy để kịp nhận trước.
        echo.private('live-chat-queue').listen('.live-chat.session.updated', () => {
            if (generation !== currentGeneration) return
            void fetchQueue()
        })
    }

    function stop(): void {
        generation++
        for (const id of [...sessionChannels.keys()]) {
            unsubscribeSessionChannel(id)
        }
        echo?.disconnect()
        echo = null
        connected.value = false
    }

    /**
     * Đăng ký kênh của một phiên (nếu chưa có).
     *
     * Mỗi phiên một kênh riêng. Nhân viên nghe đồng thời nhiều phiên nên tin
     * nhắn tới phiên nào cũng nhận được ngay.
     */
    function subscribeSessionChannel(id: number): void {
        if (!echo || sessionChannels.has(id)) return

        const channel = echo
            .private(`live-chat-session.${id}`)
            .listen('.live-chat.message.sent', (event: { session_id: number; message: LiveChatMessage; session: LiveChatSession }) => {
                if (event.session_id !== id) return
                handleIncomingMessage(event)
            })

        sessionChannels.set(id, channel)
    }

    function unsubscribeSessionChannel(id: number): void {
        sessionChannels.get(id)?.unsubscribe()
        sessionChannels.delete(id)
    }

    /**
     * Xử lý tin nhắn tới từ kênh realtime.
     *
     * Phiên đang mở → hiện thẳng trong khung chat.
     * Phiên khác → đánh dấu chưa đọc để nhân viên thấy chấm đỏ trên thẻ.
     */
    function handleIncomingMessage(event: { session_id: number; message: LiveChatMessage; session: LiveChatSession }): void {
        if (event.session_id === activeId.value) {
            pushMessage(event.message)
            upsertSession(event.session)
            // Tin khách vừa tới là lúc gợi ý hữu ích nhất — nạp sẵn để nhân
            // viên chỉ cần bấm.
            if (event.message.sender_type === 'customer') void loadSuggestions()
        } else {
            upsertSession(event.session)
            markUnread(event.session_id)
        }
    }

    function markUnread(id: number): void {
        if (unreadSessionIds.value.includes(id)) return
        unreadSessionIds.value = [...unreadSessionIds.value, id]
    }

    function markRead(id: number): void {
        unreadSessionIds.value = unreadSessionIds.value.filter((sessionId) => sessionId !== id)
    }

    // ===== State helpers =====

    function pushMessage(message: LiveChatMessage): void {
        if (messages.value.some((m) => m.id === message.id)) return
        messages.value.push(message)
    }

    /** Cập nhật hoặc thêm phiên trong danh sách, không nhân bản. */
    function upsertSession(session: LiveChatSession): void {
        const apply = (list: LiveChatSession[]): LiveChatSession[] => {
            const index = list.findIndex((s) => s.id === session.id)
            if (index === -1) return [...list, session]
            const next = [...list]
            next[index] = session
            return next
        }
        waiting.value = apply(waiting.value)
        mine.value = apply(mine.value)
    }

    return {
        waiting, mine, activeId, activeSession, canReply, messages,
        suggestions, suggestionSource, unreadSessionIds,
        loadingQueue, loadingSession, loadingSuggestions, sending, accepting, error, connected,
        fetchQueue, openSession, closeSessionView, acceptSession, sendMessage, closeSession,
        loadSuggestions, clearError, start, stop,
    }
})

async function authenticateChannel(params: { socketId: string; channelName: string }): Promise<ChannelAuthData> {
    const { default: apiClient } = await import('@/api/client')
    const staffToken = localStorage.getItem('staff_token')
    const response = await apiClient.post(
        '/live-chat/broadcast-auth',
        { socket_id: params.socketId, channel_name: params.channelName },
        { headers: staffToken ? { Authorization: `Bearer ${staffToken}` } : undefined },
    )
    return response.data as ChannelAuthData
}
