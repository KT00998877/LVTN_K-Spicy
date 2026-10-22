import { beginRead } from '@/utils/realtime'
/**
 * Staff Orders Store — quản lý đơn hàng của nhà hàng (dùng cho phục vụ & bếp).
 * Lấy dữ liệu từ BACKEND Laravel (qua api/staff.ts).
 */
import { defineStore } from 'pinia'
import { staffOrders as api } from '@/api/staff'
import type { Order } from '@/api/staff'
import type { OrderItemStatus } from '@/types/staff'

export const useStaffOrdersStore = defineStore('staffOrders', {
    state: () => ({
        orders: [] as Order[],
        current: null as Order | null,
        loading: false,
        error: null as string | null,
    }),

    getters: {
        activeOrders: (state) =>
            state.orders.filter((o) => o.status !== 'COMPLETED' && o.status !== 'CANCELLED'),
        byTable: (state) => (tableCode: string) =>
            state.orders.find(
                (o) =>
                    o.table_code === tableCode &&
                    o.status !== 'COMPLETED' &&
                    o.status !== 'CANCELLED'
            ),
    },

    actions: {
        async fetchAll(status?: string, quiet = false): Promise<void> {
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'list', quiet)
            try {
                const orders = await api.list(status)
                if (!fresh()) return
                this.orders = orders
            } catch (err: any) {
                if (quiet) throw err
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi tải danh sách đơn.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async fetchDetail(id: number): Promise<Order | null> {
            try {
                const order = await api.get(id)
                this.current = order
                return order
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi tải chi tiết đơn.'
                return null
            }
        },

        async createFromSession(tableSessionId: number): Promise<Order> {
            try {
                const order = await api.create(tableSessionId)
                // Thêm vào list nếu chưa có
                const idx = this.orders.findIndex((o) => o.id === order.id)
                if (idx >= 0) this.orders[idx] = order
                else this.orders.unshift(order)
                this.current = order
                return order
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi tạo đơn.'
                throw err
            }
        },

        async addItem(
            orderId: number,
            payload: {
                menu_item_id: number
                quantity?: number
                spicy_level?: number
                note?: string | null
                topping_ids?: number[]
            }
        ): Promise<void> {
            try {
                const updated = await api.addItem(orderId, payload)
                const idx = this.orders.findIndex((o) => o.id === orderId)
                if (idx >= 0) this.orders[idx] = updated
                if (this.current?.id === orderId) this.current = updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi thêm món.'
                throw err
            }
        },

        async updateItem(
            orderId: number,
            itemId: number,
            payload: {
                quantity?: number
                spicy_level?: number
                note?: string | null
                topping_ids?: number[]
            }
        ): Promise<void> {
            try {
                const updated = await api.updateItem(orderId, itemId, payload)
                const idx = this.orders.findIndex((o) => o.id === orderId)
                if (idx >= 0) this.orders[idx] = updated
                if (this.current?.id === orderId) this.current = updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi cập nhật món.'
                throw err
            }
        },

        async updateItemStatus(
            orderId: number,
            itemId: number,
            status: OrderItemStatus,
            reason?: string
        ): Promise<void> {
            try {
                const updated = await api.updateItemStatus(orderId, itemId, status, reason)
                const idx = this.orders.findIndex((o) => o.id === orderId)
                if (idx >= 0) this.orders[idx] = updated
                if (this.current?.id === orderId) this.current = updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi cập nhật trạng thái món.'
                throw err
            }
        },
    },
})
