/** Latest-request-wins polling. Network errors never mark a payment paid or failed. */
export function createPaymentPoller<T extends { status: string }>(options: {
    read: (code: string) => Promise<T>
    currentSession: () => () => boolean
    result: (value: T) => void
    error: (message: string) => void
    busy: (value: boolean) => void
    schedule?: (fn: () => void, delay: number) => ReturnType<typeof setTimeout>
    cancel?: (timer: ReturnType<typeof setTimeout>) => void
}) {
    const schedule = options.schedule ?? setTimeout
    const cancel = options.cancel ?? clearTimeout
    let timer: ReturnType<typeof setTimeout> | undefined
    let generation = 0
    let code = ''
    let busy = false
    let attempts = 0
    const clear = () => { if (timer !== undefined) cancel(timer); timer = undefined }
    async function refresh(manual = false) {
        if (busy || !code) return
        if (manual) attempts = 0
        clear()
        const request = generation
        const session = options.currentSession()
        const valid = () => request === generation && session()
        busy = true
        options.busy(true)
        options.error('')
        let again = true
        try {
            const result = await options.read(code)
            if (!valid()) return
            options.result(result)
            again = ['pending', 'expired'].includes(result.status)
        } catch {
            if (valid()) options.error('Chưa lấy được kết quả thanh toán. Hệ thống sẽ thử lại khi có kết nối.')
        } finally {
            if (request === generation) { busy = false; options.busy(false) }
            if (valid() && again && ++attempts < 30) timer = schedule(() => { void refresh() }, 4000)
            else if (valid() && again) options.error('Chưa xác nhận được kết quả. Bạn có thể kiểm tra lại hoặc xem đơn hàng; không cần đặt đơn mới.')
        }
    }
    return {
        start(value: string) { generation++; clear(); busy = false; attempts = 0; code = value; void refresh() },
        refresh: () => refresh(true),
        stop() { generation++; clear(); code = ''; busy = false; options.busy(false) },
    }
}
