import { beginRead } from '@/utils/realtime'
/**
 * Staff Tables Store — quản lý bàn và phiên phục vụ.
 *
 * Dữ liệu được lấy từ BACKEND Laravel (qua api/staff.ts).
 * KHÔNG còn dùng staffMock (localStorage).
 */
import { defineStore } from 'pinia'
import { staffTables as api } from '@/api/staff'
import type { RestaurantTable, TableSession } from '@/api/staff'
import type { TableStatus } from '@/types/staff'

export const useTablesStore = defineStore('staffTables', {
    state: () => ({
        tables: [] as RestaurantTable[],
        sessions: [] as TableSession[],
        stats: {
            total: 0,
            available: 0,
            occupied: 0,
            reserved: 0,
            waiting_payment: 0,
            cleaning: 0,
        },
        loading: false,
        error: null as string | null,
    }),

    getters: {
        /** Bàn đang phục vụ */
        occupiedTables: (state) => state.tables.filter((t) => t.status === 'OCCUPIED'),
        /** Bàn trống */
        availableTables: (state) => state.tables.filter((t) => t.status === 'AVAILABLE'),
        /** Bàn chờ thanh toán */
        waitingPaymentTables: (state) => state.tables.filter((t) => t.status === 'WAITING_PAYMENT'),
        /** Phiên đang mở */
        openSessions: (state) => state.sessions.filter((s) => s.status === 'OPEN'),
    },

    actions: {
        /**
         * Lấy danh sách bàn + sessions + stats từ backend.
         */
        async fetchAll(quiet = false): Promise<void> {
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'tables', quiet)
            try {
                const res = await api.list()
                if (!fresh()) return
                this.tables = res.tables
                this.sessions = res.sessions
                this.stats = res.stats
            } catch (err: any) {
                if (quiet) throw err
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi tải dữ liệu bàn.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        /**
         * Cập nhật trạng thái bàn trực tiếp (AVAILABLE / CLEANING).
         */
        async updateStatus(tableId: number, status: TableStatus): Promise<void> {
            try {
                const updated = await api.updateStatus(tableId, status)
                const idx = this.tables.findIndex((t) => t.id === tableId)
                if (idx >= 0) this.tables[idx] = updated
                await this.fetchAll() // refresh stats
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi cập nhật trạng thái bàn.'
                throw err
            }
        },

        /**
         * Mở phiên phục vụ mới cho 1 bàn.
         * Trả về session vừa tạo.
         */
        async openSession(payload: {
            table_id: number
            guest_count: number
            customer_name?: string | null
        }): Promise<TableSession> {
            try {
                const session = await api.openSession(payload)
                this.sessions.push(session)
                await this.fetchAll()
                return session
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi mở bàn.'
                throw err
            }
        },

        /**
         * Đóng phiên.
         */
        async closeSession(sessionId: number): Promise<void> {
            try {
                await api.closeSession(sessionId)
                await this.fetchAll()
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi đóng phiên.'
                throw err
            }
        },

        /**
         * Hủy phiên.
         */
        async cancelSession(sessionId: number): Promise<void> {
            try {
                await api.cancelSession(sessionId)
                await this.fetchAll()
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi hủy phiên.'
                throw err
            }
        },

        /**
         * Đổi bàn: chuyển phiên sang bàn mới (giữ nguyên order + items).
         */
        async moveSession(sessionId: number, newTableId: number): Promise<TableSession> {
            try {
                const updated = await api.moveSession(sessionId, newTableId)
                await this.fetchAll()
                return updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi đổi bàn.'
                throw err
            }
        },

        /**
         * Phục vụ bấm yêu cầu thanh toán → bàn sang WAITING_PAYMENT.
         */
        async requestPayment(tableId: number): Promise<RestaurantTable> {
            try {
                const updated = await api.requestPayment(tableId)
                await this.fetchAll()
                return updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi yêu cầu thanh toán.'
                throw err
            }
        },
    },
})
