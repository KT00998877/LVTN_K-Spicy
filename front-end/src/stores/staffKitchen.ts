import { beginRead } from '@/utils/realtime'
/**
 * Staff Kitchen Store — quản lý danh sách món cần chế biến (Bếp).
 * Dữ liệu được lấy từ backend Laravel qua api/staff.ts.
 */
import { defineStore } from 'pinia'
import { staffKitchen, type KitchenItem } from '@/api/staff'

export const useKitchenStore = defineStore('staffKitchen', {
    state: () => ({
        pendingItems: [] as KitchenItem[],
        preparingItems: [] as KitchenItem[],
        readyItems: [] as KitchenItem[],
        loading: false,
        error: null as string | null,
        updatingIds: [] as number[],
        revision: 0,
    }),

    getters: {
        totalActiveItems(): number {
            return this.pendingItems.length + this.preparingItems.length + this.readyItems.length
        },
    },

    actions: {
        async fetchAll(quiet = false): Promise<void> {
            if (this.loading) return
            const fresh = beginRead(this, 'kitchen', quiet)
            const revision = this.revision
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            try {
                const all = await staffKitchen.listItems()
                if (!fresh() || revision !== this.revision) return
                this.pendingItems = all.filter(x => x.item.status === 'PENDING')
                this.preparingItems = all.filter(x => x.item.status === 'PREPARING')
                this.readyItems = all.filter(x => x.item.status === 'READY')
            } catch (err) {
                if (quiet) throw err
                this.error = err instanceof Error ? err.message : 'Lỗi tải danh sách món'
            } finally {
                if (!quiet) this.loading = false
            }
        },

        async updateItemStatus(
            orderId: number,
            itemId: number,
            status: 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED',
            reason?: string
        ): Promise<void> {
            if (this.updatingIds.includes(itemId)) return
            this.updatingIds.push(itemId)
            this.revision++
            try {
                const order = await staffKitchen.updateItemStatus(orderId, itemId, status, reason)
                this.revision++
                const entry = [...this.pendingItems, ...this.preparingItems, ...this.readyItems].find(e => e.item.id === itemId)
                this.pendingItems = this.pendingItems.filter(e => e.item.id !== itemId)
                this.preparingItems = this.preparingItems.filter(e => e.item.id !== itemId)
                this.readyItems = this.readyItems.filter(e => e.item.id !== itemId)
                const updated = order.items.find(i => i.id === itemId)
                if (entry && updated) {
                    Object.assign(entry.item, updated)
                    if (status === 'PENDING') this.pendingItems.push(entry)
                    if (status === 'PREPARING') this.preparingItems.push(entry)
                    if (status === 'READY') this.readyItems.push(entry)
                }
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Lỗi cập nhật món'
                throw err
            } finally {
                this.updatingIds = this.updatingIds.filter(id => id !== itemId)
            }
        },
    },
})
