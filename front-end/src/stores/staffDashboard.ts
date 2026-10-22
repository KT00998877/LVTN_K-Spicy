import { beginRead } from '@/utils/realtime'
/**
 * Staff Dashboard Store — dữ liệu cho Manager Dashboard.
 * Dữ liệu được lấy từ backend Laravel qua api/staff.ts.
 */
import { defineStore } from 'pinia'
import {
    staffDashboard,
    type DashboardStats,
    type RevenueReport,
    type MenuItemAdmin,
    type StaffUserAdmin,
} from '@/api/staff'

export const useDashboardStore = defineStore('staffDashboard', {
    state: () => ({
        stats: null as DashboardStats | null,
        report: null as RevenueReport | null,
        menuItems: [] as MenuItemAdmin[],
        staffUsers: [] as StaffUserAdmin[],
        loading: false,
        error: null as string | null,
    }),

    actions: {
        async fetchAll(quiet = false): Promise<void> {
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'list', quiet)
            try {
                const [stats, menuItems, staffUsers] = await Promise.all([
                    staffDashboard.stats(),
                    staffDashboard.menuItems(),
                    staffDashboard.staffUsers(),
                ])
                if (!fresh()) return
                this.stats = stats
                this.menuItems = menuItems
                this.staffUsers = staffUsers
            } catch (err) {
                if (quiet) throw err
                this.error = err instanceof Error ? err.message : 'Lỗi tải dữ liệu dashboard'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async fetchReport(fromDate: string, toDate: string, quiet = false): Promise<void> {
            try {
                this.report = await staffDashboard.revenue({ from: fromDate, to: toDate })
            } catch (err) {
                if (quiet) throw err
                this.report = null
                this.error = err instanceof Error ? err.message : 'Lỗi tải báo cáo'
            }
        },

        async toggleMenuItemAvailability(id: number): Promise<void> {
            const item = this.menuItems.find(m => m.id === id)
            if (!item) return
            const updated = await staffDashboard.updateMenuItem(id, { is_available: !item.is_available })
            const idx = this.menuItems.findIndex(m => m.id === id)
            if (idx >= 0) this.menuItems[idx] = updated
        },

        async updateMenuItemPrice(id: number, price: number): Promise<void> {
            const updated = await staffDashboard.updateMenuItem(id, { price })
            const idx = this.menuItems.findIndex(m => m.id === id)
            if (idx >= 0) this.menuItems[idx] = updated
        },

        async updateStaffUser(id: number, patch: { is_active?: boolean; name?: string }): Promise<void> {
            const updated = await staffDashboard.updateStaffUser(id, patch)
            const idx = this.staffUsers.findIndex(u => u.id === id)
            if (idx >= 0) this.staffUsers[idx] = updated
        },
    },
})
