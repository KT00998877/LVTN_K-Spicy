import { onMounted, onScopeDispose } from 'vue'
import { useStaffNotificationsStore, STAFF_LIVE_EVENT } from '@/stores/staffNotifications'
import { createRefreshLoop, REFRESH_EVENT } from '@/utils/realtime'

/** Push-triggered reads, with automatic recovery while the socket is unavailable. */
export function useStaffLiveRefresh(refresh: () => unknown | Promise<unknown>, enabled = () => true) {
    const live = useStaffNotificationsStore()
    let pending = false
    let loop: ReturnType<typeof createRefreshLoop> | undefined
    const wake = () => { pending = true; loop?.wake() }
    onMounted(() => {
        loop = createRefreshLoop(async () => {
            pending = false
            try { await refresh() } catch (error) { pending = true; throw error }
        }, { enabled: () => navigator.onLine && document.visibilityState === 'visible'
            && enabled() && (pending || !live.connected) })
        window.addEventListener(STAFF_LIVE_EVENT, wake)
        window.addEventListener(REFRESH_EVENT, wake)
        window.addEventListener('online', wake)
        window.addEventListener('focus', wake)
        document.addEventListener('visibilitychange', wake)
    })
    onScopeDispose(() => {
        loop?.stop()
        window.removeEventListener(STAFF_LIVE_EVENT, wake)
        window.removeEventListener(REFRESH_EVENT, wake)
        window.removeEventListener('online', wake)
        window.removeEventListener('focus', wake)
        document.removeEventListener('visibilitychange', wake)
    })
}
