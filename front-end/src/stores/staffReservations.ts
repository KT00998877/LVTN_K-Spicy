import { beginRead } from '@/utils/realtime'
/**
 * Staff Reservations Store — quản lý đặt bàn trước.
 * Lấy dữ liệu từ BACKEND Laravel (qua api/staff.ts).
 */
import { defineStore } from 'pinia'
import { staffReservations as api } from '@/api/staff'
import type { Reservation } from '@/api/staff'
import { getReservationOptions } from '@/api/reservations'
import { restaurantNow } from '@/utils/reservation'

export const useReservationsStore = defineStore('staffReservations', {
    state: () => ({
        reservations: [] as Reservation[],
        loading: false,
        error: null as string | null,
        timezone: null as string | null,
    }),

    getters: {
        todayReservations(state): Reservation[] {
            if (!state.timezone) return []
            const today = restaurantNow(state.timezone).slice(0, 10)
            return state.reservations.filter((r) => r.reserved_date === today)
        },
        pendingReservations(state): Reservation[] {
            return state.reservations.filter((r) => r.status === 'PENDING')
        },
        upcomingReservations(state): Reservation[] {
            if (!state.timezone) return []
            const today = restaurantNow(state.timezone).slice(0, 10)
            return state.reservations
                .filter(
                    (r) =>
                        r.reserved_date >= today &&
                        r.status !== 'CANCELLED' &&
                        r.status !== 'COMPLETED'
                )
                .sort((a, b) => {
                    if (a.reserved_date !== b.reserved_date)
                        return a.reserved_date.localeCompare(b.reserved_date)
                    return a.reserved_time.localeCompare(b.reserved_time)
                })
        },
    },

    actions: {
        async fetchAll(date?: string, quiet = false): Promise<void> {
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'list', quiet)
            try {
                const options = await getReservationOptions()
                const reservations = await api.list(date)
                if (!fresh()) return
                this.timezone = options.timezone
                this.reservations = reservations
            } catch (err: any) {
                if (quiet) throw err
                this.error =
                    err?.response?.data?.errors?.status?.[0] ||
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi tải danh sách đặt bàn.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async updateStatus(id: number, status: Reservation['status']): Promise<void> {
            this.error = null
            try {
                const updated = await api.updateStatus(id, status)
                const idx = this.reservations.findIndex((r) => r.id === id)
                if (idx >= 0) this.reservations[idx] = updated
            } catch (err: any) {
                this.error =
                    err?.response?.data?.errors?.status?.[0] ||
                    err?.response?.data?.message ||
                    err?.message ||
                    'Lỗi cập nhật đặt bàn.'
                throw err
            }
        },
    },
})
