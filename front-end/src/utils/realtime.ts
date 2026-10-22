/** Read-only refresh loop. Writes are never retried by this module. */
export function createRefreshLoop(
    refresh: () => unknown | Promise<unknown>,
    options: {
        enabled?: () => boolean
        interval?: number
        schedule?: (fn: () => void, delay: number) => ReturnType<typeof setTimeout>
        cancel?: (timer: ReturnType<typeof setTimeout>) => void
    } = {},
) {
    const schedule = options.schedule ?? setTimeout
    const cancel = options.cancel ?? clearTimeout
    const interval = options.interval ?? 3000
    let timer: ReturnType<typeof setTimeout> | undefined
    let stopped = false
    let running = false
    let pending = false
    let failures = 0
    function later(delay: number) {
        if (stopped) return
        if (timer !== undefined) cancel(timer)
        timer = schedule(() => { timer = undefined; void run() }, delay)
    }
    async function run() {
        if (stopped) return
        if (running) { pending = true; return }
        if (options.enabled && !options.enabled()) { later(interval); return }
        running = true
        try { await refresh(); failures = 0 }
        catch { failures = Math.min(failures + 1, 4) }
        finally {
            running = false
            const delay = pending ? 250 : Math.min(interval * 2 ** failures, 30000)
            pending = false
            later(delay)
        }
    }
    later(interval)
    return {
        wake() { if (running) pending = true; else later(250) },
        stop() { stopped = true; if (timer !== undefined) cancel(timer) },
    }
}

export const REFRESH_EVENT = 'restaurant:data-changed'
export const REFRESH_STORAGE_KEY = 'restaurant:refresh'

let writeRevision = 0
export function markDataWrite() { writeRevision++ }
const reads = new WeakMap<object, Map<string, number>>()
/** A late background snapshot must not overwrite a newer read or local write. */
export function beginRead(owner: object, key: string, background: boolean) {
    let versions = reads.get(owner)
    if (!versions) { versions = new Map(); reads.set(owner, versions) }
    const version = (versions.get(key) ?? 0) + 1
    versions.set(key, version)
    const write = writeRevision
    return () => versions.get(key) === version && (!background || write === writeRevision)
}

/** The cross-tab signal contains no customer data or credentials. */
export function announceDataChange() {
    markDataWrite()
    window.dispatchEvent(new Event(REFRESH_EVENT))
    try { localStorage.setItem(REFRESH_STORAGE_KEY, `${Date.now()}:${Math.random()}`) }
    catch { /* Polling still works if browser storage is unavailable. */ }
}
