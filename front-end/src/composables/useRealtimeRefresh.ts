import { onMounted, onScopeDispose } from 'vue'
import { createRefreshLoop, markDataWrite, REFRESH_EVENT, REFRESH_STORAGE_KEY } from '@/utils/realtime'

/** Keep the initial page load separate; refresh only the data, never the form. */
export function useRealtimeRefresh(
    refresh: () => unknown | Promise<unknown>,
    enabled: () => boolean = () => true,
    interval = 3000,
) {
    let loop: ReturnType<typeof createRefreshLoop> | undefined
    const wake = () => loop?.wake()
    const storage = (event: StorageEvent) => {
        if (event.key === REFRESH_STORAGE_KEY) { markDataWrite(); wake() }
    }
    onMounted(() => {
        if (typeof window === 'undefined' || typeof document === 'undefined') return
        loop = createRefreshLoop(refresh, {
            interval,
            enabled: () => document.visibilityState === 'visible' && navigator.onLine && enabled(),
        })
        window.addEventListener(REFRESH_EVENT, wake)
        window.addEventListener('storage', storage)
        window.addEventListener('focus', wake)
        window.addEventListener('online', wake)
        document.addEventListener('visibilitychange', wake)
    })
    onScopeDispose(() => {
        loop?.stop()
        if (typeof window === 'undefined' || typeof document === 'undefined') return
        window.removeEventListener(REFRESH_EVENT, wake)
        window.removeEventListener('storage', storage)
        window.removeEventListener('focus', wake)
        window.removeEventListener('online', wake)
        document.removeEventListener('visibilitychange', wake)
    })
}
