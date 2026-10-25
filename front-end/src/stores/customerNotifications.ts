import { defineStore } from 'pinia'
import { ref } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import apiClient from '@/api/client'

/**
 * Chuông thông báo ưu đãi cho khách hàng (Phase 1).
 *
 * Sao chép pattern từ `stores/staffNotifications.ts` nhưng dùng token khách
 * (`auth_token`) và kênh `customer.{id}` thay vì `staff.{id}`.
 *
 * Kênh broadcast được mở khi khách đăng nhập; nếu không có cấu hình Reverb
 * thì store vẫn hoạt động qua HTTP polling (chậm hơn nhưng không chết).
 */
export interface PromoNotification {
    id: string
    data: {
        event: string
        campaign_id: number
        name: string
        message: string
        voucher_code: string
        banner_image_url: string | null
        accent_color: string
        cta_text: string
        landing_url: string | null
    }
    read_at: string | null
    created_at: string
}

interface NotificationPage {
    data: { data: PromoNotification[]; current_page: number; last_page: number }
    unread_count: number
}

export const useCustomerNotificationsStore = defineStore('customerNotifications', () => {
    const items = ref<PromoNotification[]>([])
    const unreadCount = ref(0)
    const connected = ref(false)
    const loading = ref(false)
    const error = ref('')
    const latest = ref<PromoNotification | null>(null)

    let echo: Echo<'reverb'> | null = null
    let retry: ReturnType<typeof setInterval> | undefined
    let generation = 0
    let revision = 0
    let audio: AudioContext | undefined
    const seen = new Set<string>()

    async function fetchPage(nextPage = 1) {
        if (loading.value) return
        const currentGeneration = generation
        const currentRevision = revision
        loading.value = true
        try {
            const response = await apiClient.get<NotificationPage>('/notifications', { params: { page: nextPage } })
            if (generation !== currentGeneration) return
            if (revision !== currentRevision) return
            const incoming = response.data.data.data
            for (const item of incoming) seen.add(item.id)
            const merged = nextPage === 1 ? incoming : [...items.value, ...incoming]
            items.value = [...new Map(merged.map(item => [item.id, item])).values()]
            unreadCount.value = response.data.unread_count
            error.value = ''
        } catch {
            if (generation === currentGeneration) error.value = 'Chưa tải được thông báo. Hệ thống sẽ tự thử lại.'
        } finally {
            if (generation === currentGeneration) loading.value = false
        }
    }

    async function markRead(item: PromoNotification) {
        if (item.read_at) return
        const currentGeneration = generation
        try {
            await apiClient.patch(`/notifications/${item.id}/read`)
        } catch {
            return
        }
        if (generation !== currentGeneration) return
        revision++
        item.read_at = new Date().toISOString()
        unreadCount.value = Math.max(0, unreadCount.value - 1)
    }

    async function markAllRead() {
        const currentGeneration = generation
        const unread = items.value.filter(i => !i.read_at)
        for (const item of unread) {
            try {
                await apiClient.patch(`/notifications/${item.id}/read`)
            } catch {
                // Bỏ qua lỗi từng item — mục tiêu là đánh dấu hết những gì đọc được.
            }
        }
        if (generation !== currentGeneration) return
        revision++
        for (const item of unread) item.read_at = new Date().toISOString()
        unreadCount.value = 0
    }

    function playSound() {
        try {
            audio ??= new AudioContext()
            const oscillator = audio.createOscillator()
            const gain = audio.createGain()
            oscillator.frequency.value = 880
            gain.gain.setValueAtTime(0.08, audio.currentTime)
            gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.2)
            oscillator.connect(gain)
            gain.connect(audio.destination)
            oscillator.start()
            oscillator.stop(audio.currentTime + 0.2)
        } catch {
            // Trình duyệt chặn audio hoặc thiếu WebAudio — bỏ qua, không cần báo lỗi.
        }
    }

    function stop() {
        generation++
        revision++
        echo?.disconnect()
        echo = null
        if (retry) clearInterval(retry)
        retry = undefined
        connected.value = false
        loading.value = false
        items.value = []
        seen.clear()
        latest.value = null
        unreadCount.value = 0
        error.value = ''
        void audio?.close()
        audio = undefined
    }

    /**
     * Bật chuông. `userId` = id khách hiện tại.
     */
    function start(userId: number) {
        stop()
        const currentGeneration = generation
        void fetchPage()

        const key = import.meta.env.VITE_REVERB_APP_KEY
        if (!key) {
            error.value = 'Chưa cấu hình kết nối real-time.'
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
                    void apiClient
                        .post('/broadcasting/auth', { socket_id: params.socketId, channel_name: params.channelName }, {
                            headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
                        })
                        .then(response => callback(null, response.data))
                        .catch(() => callback(new Error('Không thể xác thực kênh real-time'), null))
                },
            },
        })

        echo = new Echo({ broadcaster: 'reverb', client })
        client.connection.bind('state_change', ({ current }: { current: string }) => {
            if (currentGeneration !== generation) return
            if (current !== 'connected') connected.value = false
        })

        echo.private(`customer.${userId}`)
            .subscribed(() => {
                if (currentGeneration !== generation) return
                connected.value = true
                void fetchPage()
            })
            .listen('.customer.promo.created', (item: PromoNotification) => {
                if (currentGeneration !== generation || seen.has(item.id)) return
                seen.add(item.id)
                revision++
                items.value.unshift(item)
                unreadCount.value++
                latest.value = item
                playSound()
            })
            .listen('.customer.notification.read', ({ id }: { id: string }) => {
                if (currentGeneration !== generation) return
                revision++
                const item = items.value.find(i => i.id === id)
                if (item && !item.read_at) {
                    item.read_at = new Date().toISOString()
                    unreadCount.value = Math.max(0, unreadCount.value - 1)
                } else void fetchPage()
            })

        // Fallback polling nếu realtime không kết nối được.
        retry = setInterval(() => {
            if (currentGeneration === generation && navigator.onLine && (!connected.value || error.value)) {
                void fetchPage()
            }
        }, 15000)
    }

    return {
        items, unreadCount, connected, loading, error, latest,
        start, stop, fetchPage, markRead, markAllRead, playSound,
    }
})
