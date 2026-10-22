import { beginRead } from '@/utils/realtime'
/**
 * Store quản lý dữ liệu đơn giao hàng (ONLINE + TAKEAWAY) cho nhân viên.
 *
 * Hai tập người dùng:
 *  - Manager/Cashier: xác nhận, từ chối, bàn giao shipper (READY_NOTIFY_SHIPPER).
 *  - Shipper: nhận đơn → Bước 1 pickup → Bước 2 arrived (ONLINE) → Bước 3 complete.
 *
 * Cùng dùng `DeliveryOrder` từ `api/staff.ts` để tránh trùng kiểu.
 */

import { defineStore } from 'pinia'
import { onlineOrders, deliveries, type DeliveryOrder, type DeliveryShipper } from '@/api/staff'

interface State {
    orders: DeliveryOrder[]
    shippers: DeliveryShipper[]
    loading: boolean
    busyId: number | null
    error: string | null
}

export const useOnlineOrdersStore = defineStore('onlineOrders', {
    state: (): State => ({
        orders: [],
        shippers: [],
        loading: false,
        busyId: null,
        error: null,
    }),

    getters: {
        /** Các đơn mới tạo, chờ thu ngân / manager xác nhận */
        pendingConfirmation: (s) => s.orders.filter(o => o.status === 'PENDING'),
        /**
         * Đơn đang chế biến: CONFIRMED, PREPARING.
         * (Khi tất cả món READY → chuyển sang READY_NOTIFY_SHIPPER → sang tab "Sẵn sàng giao".)
         */
        preparing: (s) => s.orders.filter(o => ['CONFIRMED', 'PREPARING'].includes(o.status)),
        /** Đơn đã thông báo shipper, chờ cashier/manager bàn giao. */
        readyForHandover: (s) => s.orders.filter(o => o.status === 'READY_NOTIFY_SHIPPER'),
        /** Đã bàn giao nhưng shipper chưa hoàn tất. */
        delivering: (s) => s.orders.filter(o => o.status === 'DELIVERING'),
        /** Shipper đã giao xong, chờ khách bấm "Tôi đã nhận hàng". */
        delivered: (s) => s.orders.filter(o => o.status === 'DELIVERED'),
        /** Hoàn tất hoặc đã hủy. */
        finished: (s) => s.orders.filter(o => ['COMPLETED', 'CANCELLED'].includes(o.status)),
    },

    actions: {
        async fetchOrders(status?: string, quiet = false) {
            if (this.loading) return
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'orders', quiet)
            try {
                const data = await onlineOrders.list(status)
                if (!fresh()) return
                this.orders = data
            } catch (e: any) {
                if (quiet) throw e
                this.error = e?.response?.data?.message ?? 'Không tải được danh sách đơn giao hàng.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async fetchShippers(force = false, quiet = false) {
            if (!force && this.shippers.length) return
            try {
                this.shippers = await onlineOrders.listShippers()
            } catch (error) {
                if (quiet) throw error
                this.shippers = []
            }
        },

        async confirm(id: number, shipperId?: number): Promise<DeliveryOrder | null> {
            return this._guard(id, () => onlineOrders.confirm(id, shipperId))
        },

        async reject(id: number, reason: string): Promise<DeliveryOrder | null> {
            return this._guard(id, () => onlineOrders.reject(id, reason))
        },

        async handover(id: number, shipperId: number): Promise<DeliveryOrder | null> {
            return this._guard(id, () => onlineOrders.handover(id, shipperId))
        },

        async findById(id: number): Promise<DeliveryOrder | null> {
            return this.orders.find(o => o.id === id) ?? null
        },

        /** Thay thế hoặc chèn order vào cache (dùng sau khi hành động). */
        upsert(order: DeliveryOrder) {
            const idx = this.orders.findIndex(o => o.id === order.id)
            if (idx >= 0) this.orders.splice(idx, 1, order)
            else this.orders.unshift(order)
        },

        async _guard(id: number, fn: () => Promise<DeliveryOrder>): Promise<DeliveryOrder | null> {
            if (this.busyId !== null) return null
            this.busyId = id
            this.error = null
            try {
                const updated = await fn()
                this.upsert(updated)
                return updated
            } catch (e: any) {
                this.error = e?.response?.data?.message ?? 'Hành động thất bại.'
                return null
            } finally {
                this.busyId = null
            }
        },
    },
})

/** Store riêng cho Shipper, sử dụng `/staff/deliveries`. */
export const useDeliveriesStore = defineStore('deliveries', {
    state: (): { list: DeliveryOrder[]; loading: boolean; busyId: number | null; error: string | null } => ({
        list: [],
        loading: false,
        busyId: null,
        error: null,
    }),

    getters: {
        active: (s) => s.list.filter(o => o.status === 'DELIVERING'),
        /** Đơn shipper đã giao xong, chờ khách bấm "Tôi đã nhận hàng" */
        awaitingReceipt: (s) => s.list.filter(o => o.status === 'DELIVERED'),
        recent: (s) => s.list.filter(o => o.status === 'COMPLETED'),
    },

    actions: {
        async fetch(quiet = false) {
            if (this.loading) return
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'deliveries', quiet)
            try {
                const list = await deliveries.list()
                if (!fresh()) return
                this.list = list
            } catch (e: any) {
                if (quiet) throw e
                this.error = e?.response?.data?.message ?? 'Không tải được đơn giao hàng của bạn.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        /** Bước 1: Xác nhận đã lấy món từ bếp */
        async pickup(id: number): Promise<DeliveryOrder | null> {
            return this._guard(id, () => deliveries.pickup(id))
        },

        /** Bước 2: Xác nhận đã tới điểm giao (chỉ ONLINE) */
        async arrived(id: number): Promise<DeliveryOrder | null> {
            return this._guard(id, () => deliveries.arrived(id))
        },

        /** Bước 3: Xác nhận giao thành công (shipper thu COD nếu cần) */
        async complete(id: number): Promise<DeliveryOrder | null> {
            return this._guard(id, () => deliveries.complete(id))
        },

        async reportIssue(id: number, reason: string): Promise<DeliveryOrder | null> {
            return this._guard(id, () => deliveries.reportIssue(id, reason))
        },

        upsert(order: DeliveryOrder) {
            const idx = this.list.findIndex(o => o.id === order.id)
            if (idx >= 0) this.list.splice(idx, 1, order)
            else this.list.unshift(order)
        },

        async _guard(id: number, fn: () => Promise<DeliveryOrder>): Promise<DeliveryOrder | null> {
            if (this.busyId !== null) return null
            this.busyId = id
            this.error = null
            try {
                const updated = await fn()
                this.upsert(updated)
                return updated
            } catch (e: any) {
                this.error = e?.response?.data?.message ?? 'Hành động thất bại.'
                return null
            } finally {
                this.busyId = null
            }
        },
    },
})
