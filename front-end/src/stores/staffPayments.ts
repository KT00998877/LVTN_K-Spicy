/**
 * Staff Payments Store — quản lý hóa đơn và thanh toán (Thu ngân).
 * Dữ liệu được lấy từ backend Laravel qua api/staff.ts.
 */
import { defineStore } from 'pinia'
import { staffInvoices, type Invoice } from '@/api/staff'

export type PaymentMethod = 'CASH' | 'TRANSFER' | 'QR'

export const usePaymentsStore = defineStore('staffPayments', {
    state: () => ({
        invoices: [] as Invoice[],
        current: null as Invoice | null,
        loading: false,
        error: null as string | null,
    }),

    getters: {
        unpaidInvoices: (state) => state.invoices.filter(i => i.status === 'UNPAID' || i.status === 'PARTIAL'),
        paidInvoices: (state) => state.invoices.filter(i => i.status === 'PAID'),
        todayPaidInvoices: (state) => {
            const today = new Date().toISOString().slice(0, 10)
            return state.invoices.filter(i => i.status === 'PAID' && i.paid_at?.slice(0, 10) === today)
        },
        todayRevenue(): number {
            const today = new Date().toISOString().slice(0, 10)
            return this.invoices
                .filter(i => i.status === 'PAID' && i.paid_at?.slice(0, 10) === today)
                .reduce((sum, i) => sum + i.paid, 0)
        },
        todayCashRevenue(): number {
            const today = new Date().toISOString().slice(0, 10)
            return this.invoices
                .filter(i => i.status === 'PAID' && i.paid_at?.slice(0, 10) === today)
                .reduce((sum, i) => sum + i.payments.filter(p => p.method === 'CASH' && p.status === 'SUCCESS').reduce((s, p) => s + p.amount, 0), 0)
        },
        todayTransferRevenue(): number {
            return this.todayRevenue - this.todayCashRevenue
        },
    },

    actions: {
        async fetchAll(params?: { status?: string; from?: string; to?: string }): Promise<void> {
            this.loading = true
            this.error = null
            try {
                this.invoices = await staffInvoices.list(params)
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Lỗi tải danh sách hóa đơn'
            } finally {
                this.loading = false
            }
        },

        async fetchDetail(id: number): Promise<void> {
            try {
                this.current = await staffInvoices.get(id)
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Lỗi tải chi tiết hóa đơn'
            }
        },

        async createFromOrder(orderId: number): Promise<Invoice> {
            try {
                const invoice = await staffInvoices.createFromOrder(orderId)
                this.invoices.unshift(invoice)
                return invoice
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Lỗi tạo hóa đơn'
                throw err
            }
        },

        async processPayment(
            invoiceId: number,
            payload: { method: PaymentMethod; amount: number; transaction_ref?: string; note?: string }
        ): Promise<Invoice> {
            try {
                const updated = await staffInvoices.pay(invoiceId, payload)
                const idx = this.invoices.findIndex(i => i.id === invoiceId)
                if (idx >= 0) this.invoices[idx] = updated
                if (this.current?.id === invoiceId) this.current = updated
                return updated
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Lỗi xử lý thanh toán'
                throw err
            }
        },
    },
})
