import { beginRead } from '@/utils/realtime'
import { defineStore } from 'pinia'
import * as ordersApi from '@/api/orders'
import { useAuthStore } from './auth'
import type {
    OrderDetail,
    OrderListMeta,
    OrderListQuery,
    OrderStatus,
    OrderSummary,
    SimulatedPaymentMethod,
} from '@/types/orders'

interface OrderError extends Error {
    response?: { data?: { message?: string } }
}

export const useOrderStore = defineStore('orders', {
    state: () => ({
        items: [] as OrderSummary[],
        meta: {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
        } as OrderListMeta,
        current: null as OrderDetail | null,

        loadingList: false,
        loadingDetail: false,
        loadingPayment: false,
        loadingConfirm: false,
        updatingStatus: false,

        error: null as string | null,
        lastQuery: {} as OrderListQuery,
        listRevision: 0,
        detailRevision: 0,
        /** Số điểm thưởng đã được cộng cho đơn vừa xác nhận (dùng để hiển thị toast). */
        lastPointsAwarded: 0 as number,
        /** Tổng điểm của user sau khi xác nhận. */
        lastLoyaltyPoints: 0 as number,
    }),

    getters: {
        /** Đếm số đơn theo trạng thái (dùng cho badge filter) */
        counts: (state) => {
            const c: Record<OrderStatus, number> = {
                PENDING: 0, CONFIRMED: 0, PREPARING: 0,
                READY: 0, READY_NOTIFY_SHIPPER: 0, SERVED: 0,
                DELIVERING: 0, DELIVERED: 0, COMPLETED: 0, CANCELLED: 0,
            }
            for (const o of state.items) c[o.status] = (c[o.status] ?? 0) + 1
            return c
        },
        hasOrders: (state) => state.meta.total > 0,
    },

    actions: {
        /**
         * Lấy danh sách đơn hàng của customer hiện tại.
         */
        async fetchList(query: OrderListQuery = {}, append = false, quiet = false): Promise<boolean> {
            const auth = useAuthStore()
            if (!auth.isAuthenticated) { this.$reset(); return false }
            const revision = auth.sessionRevision
            const token = auth.token
            if (!auth.isCurrentSession(revision, token)) return false
            const fresh = beginRead(this, 'list', quiet)
            const request = ++this.listRevision
            const current = () => auth.isCurrentSession(revision, token) && request === this.listRevision
            if (!quiet) this.loadingList = true
            if (!quiet) this.error = null
            this.lastQuery = query
            try {
                const res = await ordersApi.fetchMyOrders(query)
                if (!current() || !fresh()) return false
                if (append && query.page && query.page > 1) {
                    this.items = [...this.items, ...res.data]
                } else {
                    this.items = res.data
                }
                this.meta = res.meta
                return true
            } catch (err) {
                if (quiet) throw err
                if (!current()) return false
                this.items = []
                this.meta = { current_page: 1, last_page: 1, per_page: 10, total: 0 }
                this.error = (err as OrderError)?.response?.data?.message
                    ?? 'Không thể tải danh sách đơn hàng.'
                return false
            } finally {
                if (current() && !quiet) this.loadingList = false
            }
        },

        /**
         * Lấy chi tiết 1 đơn hàng.
         */
        async fetchDetail(id: number | string, quiet = false): Promise<boolean> {
            const auth = useAuthStore()
            if (!auth.isAuthenticated) { this.$reset(); return false }
            const revision = auth.sessionRevision
            const token = auth.token
            if (!auth.isCurrentSession(revision, token)) return false
            const request = ++this.detailRevision
            const valid = () => auth.isCurrentSession(revision, token) && request === this.detailRevision
            if (!quiet) this.current = null
            if (!quiet) this.loadingDetail = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'realtime', quiet)
            try {
                const order = await ordersApi.fetchMyOrderDetail(id)
                if (!fresh() || !valid() || (quiet && (this.loadingPayment || this.loadingConfirm))) return false
                if (!this.current) this.error = null
                this.current = order
                return true
            } catch (err) {
                if (quiet) throw err
                if (!valid()) return false
                this.error = (err as OrderError)?.response?.data?.message
                    ?? 'Không thể tải chi tiết đơn hàng.'
                return false
            } finally {
                if (valid() && !quiet) this.loadingDetail = false
            }
        },

        /**
         * Gọi thanh toán mô phỏng cho đơn ONLINE.
         * Sau khi thành công sẽ cập nhật lại `current` để UI phản ánh
         * trạng thái đã thanh toán + timeline mới.
         */
        async payOnline(id: number | string, method: SimulatedPaymentMethod): Promise<boolean> {
            const auth = useAuthStore()
            if (!auth.isAuthenticated) { return false }
            if (this.loadingPayment) return false
            this.loadingPayment = true
            this.error = null
            try {
                const order = await ordersApi.simulateOrderPayment(id, method)
                this.current = order
                return true
            } catch (err) {
                this.error = (err as OrderError)?.response?.data?.message
                    ?? 'Không thể thanh toán đơn. Vui lòng thử lại.'
                return false
            } finally {
                this.loadingPayment = false
            }
        },

        /**
         * Khách hàng xác nhận đã nhận hàng.
         *   - DELIVERED → COMPLETED, cộng điểm thưởng (nếu đơn > 0).
         *   - COMPLETED  → idempotent, không cộng thêm.
         * Cập nhật `current` + lưu `lastPointsAwarded` để UI hiển thị thông báo.
         *
         * @returns { ok, pointsAwarded, totalPoints }
         */
        async confirmReceipt(id: number | string): Promise<{
            ok: boolean
            pointsAwarded: number
            totalPoints: number
        }> {
            const auth = useAuthStore()
            if (!auth.isAuthenticated) return { ok: false, pointsAwarded: 0, totalPoints: 0 }
            if (this.loadingConfirm) return { ok: false, pointsAwarded: 0, totalPoints: 0 }

            this.loadingConfirm = true
            this.error = null
            try {
                const res = await ordersApi.confirmOrderReceipt(id)
                this.current = res.data
                const pointsAwarded = (res.data.points_awarded ?? res.points_awarded ?? 0) as number
                const totalPoints = res.loyalty_points ?? 0
                this.lastPointsAwarded = pointsAwarded
                this.lastLoyaltyPoints = totalPoints

                // Cập nhật luôn auth session để header (nếu có) hiển thị điểm + hạng.
                if (auth.user) {
                    auth.user = { ...auth.user, loyalty_points: totalPoints }
                }
                // Refresh hạng thành viên — phòng trường hợp vừa thăng hạng.
                // Chạy fire-and-forget để không chặn UI; nếu lỗi thì bỏ qua.
                auth.refreshLiveProfile().catch(() => { /* ignore */ })
                return { ok: true, pointsAwarded, totalPoints }
            } catch (err) {
                this.error = (err as OrderError)?.response?.data?.message
                    ?? 'Không thể xác nhận đã nhận hàng. Vui lòng thử lại.'
                return { ok: false, pointsAwarded: 0, totalPoints: 0 }
            } finally {
                this.loadingConfirm = false
            }
        },

        /**
         * Reset danh sách (vd khi filter mới).
         */
        resetList(): void {
            this.listRevision++
            this.loadingList = false
            this.items = []
            this.meta = {
                current_page: 1,
                last_page: 1,
                per_page: 10,
                total: 0,
            }
        },
    },
})
