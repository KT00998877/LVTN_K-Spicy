import api from './client'

export async function createVNPay(orderId: number) {
    const { data } = await api.post<{ payment_url: string; payment_code: string }>('/payments/vnpay/create', { order_id: orderId, platform: 'web' })
    return data
}

export interface PaymentStatus {
    status: 'paid' | 'pending' | 'failed' | 'cancelled' | 'expired' | 'refunded'
    order_id: number
    order_code?: string
    order_status?: string
    payment_status?: PaymentStatus['status']
    message?: string
    amount: number
    transaction_id: string | null
    paid_at: string | null
    can_retry: boolean
}

export async function paymentStatus(code: string) {
    return (await api.get<PaymentStatus>(`/payments/${encodeURIComponent(code)}/status`)).data
}
