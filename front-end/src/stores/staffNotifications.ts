import { defineStore } from 'pinia'
import { ref } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import apiClient from '@/api/client'
import type { StaffSession } from '@/stores/staff'

export const STAFF_LIVE_EVENT = 'staff:live-update'
export interface StaffNotification {
    id: string
    data: { event: string; order_id: number; order_code: string; message: string; url: string }
    read_at: string | null
    created_at: string
}
interface NotificationPage {
    data: { data: StaffNotification[]; current_page: number; last_page: number }
    unread_count: number
}

export const useStaffNotificationsStore = defineStore('staffNotifications', () => {
    const items = ref<StaffNotification[]>([])
    const unreadCount = ref(0)
    const connected = ref(false)
    const loading = ref(false)
    const error = ref('')
    const latest = ref<StaffNotification | null>(null)
    const page = ref(1)
    const lastPage = ref(1)
    const soundEnabled = ref(localStorage.getItem('staff_notification_sound') === 'true')
    let echo: Echo<'reverb'> | null = null
    let retry: ReturnType<typeof setInterval> | undefined
    let generation = 0
    let revision = 0
    let audio: AudioContext | undefined
    const seen = new Set<string>()
    const signal = () => window.dispatchEvent(new Event(STAFF_LIVE_EVENT))
    const unlockSound = () => { void prepareSound() }

    async function fetchPage(nextPage = 1) {
        if (loading.value) return
        const currentGeneration = generation
        const currentRevision = revision
        loading.value = true
        try {
            const response = await apiClient.get<NotificationPage>('/staff/notifications', { params: { page: nextPage } })
            if (generation !== currentGeneration) return
            if (revision !== currentRevision) return
            const incoming = response.data.data.data
            for (const item of incoming) seen.add(item.id)
            const merged = nextPage === 1 ? incoming : [...items.value, ...incoming]
            items.value = [...new Map(merged.map(item => [item.id, item])).values()]
            unreadCount.value = response.data.unread_count
            page.value = response.data.data.current_page
            lastPage.value = response.data.data.last_page
            error.value = ''
        } catch {
            if (generation === currentGeneration) error.value = 'Chưa tải được thông báo. Hệ thống sẽ tự thử lại.'
        } finally {
            if (generation === currentGeneration) loading.value = false
            if (generation === currentGeneration && revision !== currentRevision) void fetchPage()
        }
    }

    async function markRead(item: StaffNotification) {
        if (item.read_at) return
        const currentGeneration = generation
        await apiClient.patch(`/staff/notifications/${item.id}/read`)
        if (currentGeneration !== generation) return
        if (!item.read_at) {
            revision++
            item.read_at = new Date().toISOString()
            unreadCount.value = Math.max(0, unreadCount.value - 1)
        }
    }

    async function toggleSound() {
        soundEnabled.value = !soundEnabled.value
        localStorage.setItem('staff_notification_sound', String(soundEnabled.value))
        await prepareSound()
    }

    async function prepareSound() {
        if (soundEnabled.value) {
            audio ??= new AudioContext()
            await audio.resume().catch(() => {})
        }
    }

    function playSound() {
        if (!soundEnabled.value || !audio || audio.state !== 'running') return
        const oscillator = audio.createOscillator()
        const gain = audio.createGain()
        oscillator.frequency.value = 880
        gain.gain.setValueAtTime(0.08, audio.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.2)
        oscillator.connect(gain).connect(audio.destination)
        oscillator.start()
        oscillator.stop(audio.currentTime + 0.2)
    }

    function stop() {
        generation++
        revision++
        window.removeEventListener('pointerdown', unlockSound)
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

    function start(session: StaffSession) {
        stop()
        const currentGeneration = generation
        const startedAt = Date.now()
        window.addEventListener('pointerdown', unlockSound, { once: true })
        void fetchPage()
        const key = import.meta.env.VITE_REVERB_APP_KEY
        if (!key) {
            error.value = 'Chưa cấu hình kết nối real-time.'
            return
        }
        const secure = (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '')) === 'https'
        const port = Number(import.meta.env.VITE_REVERB_PORT ?? (secure ? 443 : 8080))
        const client = new Pusher(key, {
            cluster: 'mt1', wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
            wsPort: port, wssPort: port, forceTLS: secure, enabledTransports: ['ws', 'wss'],
            channelAuthorization: {
                customHandler: (params, callback) => {
                    void apiClient.post('/broadcasting/auth', { socket_id: params.socketId, channel_name: params.channelName }, {
                        headers: { Authorization: `Bearer ${localStorage.getItem('staff_token')}` },
                    }).then(response => callback(null, response.data))
                        .catch(() => callback(new Error('Không thể xác thực kênh real-time'), null))
                },
            },
        })
        echo = new Echo({ broadcaster: 'reverb', client })
        const roles = session.roles.filter(role => ['MANAGER', 'KITCHEN'].includes(role))
        const subscribed = new Set<string>()
        const ready = (channel: string) => {
            if (currentGeneration !== generation) return
            subscribed.add(channel)
            connected.value = subscribed.size === roles.length + 1
            if (connected.value) { void fetchPage(); signal() }
        }
        client.connection.bind('state_change', ({ current }: { current: string }) => {
            if (currentGeneration !== generation) return
            if (current !== 'connected') {
                subscribed.clear()
                connected.value = false
            }
        })
        echo.private(`staff.${session.user_id}`)
            .subscribed(() => ready('personal'))
            .listen('.staff.notification.created', (item: StaffNotification) => {
                if (currentGeneration !== generation || seen.has(item.id)) return
                seen.add(item.id)
                revision++
                items.value.unshift(item)
                unreadCount.value++
                if (Date.parse(item.created_at) >= startedAt) {
                    latest.value = item
                    playSound()
                }
                signal()
                void fetchPage()
            })
            .listen('.staff.notification.read', ({ id }: { id: string }) => {
                if (currentGeneration !== generation) return
                revision++
                const item = items.value.find(item => item.id === id)
                if (item && !item.read_at) {
                    item.read_at = new Date().toISOString()
                    unreadCount.value = Math.max(0, unreadCount.value - 1)
                } else void fetchPage()
            })
            .listen('.staff.order.updated', signal)
        for (const role of roles) {
            echo.private(`staff-role.${role}`).subscribed(() => ready(role)).listen('.staff.order.updated', signal)
        }
        retry = setInterval(() => {
            if (currentGeneration === generation && navigator.onLine && (!connected.value || error.value)) void fetchPage()
        }, 3000)
    }

    return { items, unreadCount, connected, loading, error, latest, page, lastPage, soundEnabled,
        start, stop, fetchPage, markRead, toggleSound, prepareSound }
})
