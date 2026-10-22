import { beginRead } from '@/utils/realtime'
import { isAxiosError } from 'axios'
import { defineStore } from 'pinia'
import { fetchHomePayload } from '@/api/home'
import type { HomePayload } from '@/types/home'

/**
 * Store trang chủ - chỉ chịu trách nhiệm fetch payload tổng và cache.
 * Mọi component đều đọc từ đây để tránh gọi API trùng lặp.
 */
export const useHomeStore = defineStore('home', {
    state: () => ({
        payload: null as HomePayload | null,
        loading: false,
        error: null as string | null,
        lastFetchedAt: null as number | null,
    }),

    getters: {
        restaurant: (state) => state.payload?.restaurant,
        stats: (state) => state.payload?.stats,
        categories: (state) => state.payload?.categories ?? [],
        featured: (state) => state.payload?.featured ?? [],
        bestSellers: (state) => state.payload?.best_sellers ?? [],
        tables: (state) => state.payload?.tables,
        blogs: (state) => state.payload?.blogs ?? [],
        isReady: (state) => state.payload !== null,
    },

    actions: {
        async load(force = false, quiet = false): Promise<void> {
            // Cache 60s để tránh re-fetch khi navigate qua lại
            const cacheAge = this.lastFetchedAt ? Date.now() - this.lastFetchedAt : Infinity
            if (!force && this.payload && cacheAge < 60_000) {
                return
            }

            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'home', quiet)
            try {
                const payload = await fetchHomePayload()
                if (!fresh()) return
                this.error = null
                this.payload = payload
                this.lastFetchedAt = Date.now()
            } catch (err) {
                if (!fresh()) return
                if (quiet) throw err
                this.error = isAxiosError(err) && (err.code === 'ECONNABORTED' || err.code === 'ETIMEDOUT')
                    ? 'Tải dữ liệu quá lâu. Bạn có thể thử lại.'
                    : 'Không thể tải dữ liệu trang chủ. Vui lòng thử lại.'
                // eslint-disable-next-line no-console
                console.error('[homeStore.load]', err)
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async refresh(): Promise<void> {
            await this.load(true)
        },
    },
})
