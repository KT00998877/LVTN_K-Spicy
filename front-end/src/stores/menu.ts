import { beginRead } from '@/utils/realtime'
import { defineStore } from 'pinia'
import { fetchMenuItems } from '@/api/menu'
import { fetchHomeCategories } from '@/api/home'
import type { MenuItemCard, CategoryItem } from '@/types/home'
import type { MenuFilters } from '@/types/menu'

const PAGE_SIZE = 12

/**
 * Store cho trang thực đơn.
 * - Quản lý filter state (đồng bộ với query string)
 * - Gọi API mỗi khi filter thay đổi
 * - Cache danh mục để sidebar hiển thị
 */
export const useMenuStore = defineStore('menu', {
    state: () => ({
        items: [] as MenuItemCard[],
        categories: [] as CategoryItem[],
        loading: false,
        loadingMore: false,
        loadMoreError: null as string | null,
        currentPage: 0,
        lastPage: 1,
        error: null as string | null,
        totalCount: 0,

        // Filter state
        filters: {
            category: null,
            search: '',
            size: null,
            min_price: null,
            max_price: null,
            sort: 'default',
        } as MenuFilters,
    }),

    getters: {
        hasMore: (state) => state.currentPage > 0 && state.currentPage < state.lastPage,
        isReady: (state) => !state.loading && state.items.length > 0,
        activeFilterCount: (state) => {
            const f = state.filters
            let n = 0
            if (f.category) n++
            if (f.search.trim()) n++
            if (f.size) n++
            if (f.min_price !== null && f.min_price !== undefined && f.min_price > 0) n++
            if (f.max_price !== null && f.max_price !== undefined && f.max_price > 0) n++
            if (f.sort && f.sort !== 'default') n++
            return n
        },
    },

    actions: {
        /**
         * Tải danh mục (cache trong store → chỉ gọi 1 lần).
         */
        async loadCategories(force = false): Promise<void> {
            if (!force && this.categories.length > 0) return
            try {
                this.categories = await fetchHomeCategories()
            } catch (err) {
                // eslint-disable-next-line no-console
                console.error('[menuStore.loadCategories]', err)
            }
        },

        /**
         * Tải danh sách món theo filter hiện tại.
         */
        async loadItems(quiet = false): Promise<void> {
            if (quiet && (this.loading || this.loadingMore)) return
            const pagesToLoad = quiet ? Math.max(1, this.currentPage) : 1
            const filters = { ...this.filters }
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            if (!quiet) {
                this.loadingMore = false
                this.loadMoreError = null
                this.items = []
                this.currentPage = 0
                this.lastPage = 1
            }
            const fresh = beginRead(this, 'items', quiet)
            try {
                const items: MenuItemCard[] = []
                for (let page = 1; page <= pagesToLoad; page++) {
                    const res = await fetchMenuItems(filters, page, PAGE_SIZE)
                    if (!fresh()) return
                    items.push(...res.data)
                    if (page === pagesToLoad || page >= res.meta.last_page) {
                        this.error = null
                        this.items = items
                        this.totalCount = res.meta.total
                        this.currentPage = page
                        this.lastPage = res.meta.last_page
                        break
                    }
                }
            } catch (err) {
                if (!fresh()) return
                if (quiet) throw err
                this.error = err instanceof Error ? err.message : 'Không thể tải thực đơn'
                // eslint-disable-next-line no-console
                console.error('[menuStore.loadItems]', err)
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async loadMore(): Promise<void> {
            if (this.loading || this.loadingMore || !this.hasMore) return
            this.loadingMore = true
            this.loadMoreError = null
            const fresh = beginRead(this, 'items', false)
            try {
                const res = await fetchMenuItems({ ...this.filters }, this.currentPage + 1, PAGE_SIZE)
                if (!fresh()) return
                this.items = [...new Map([...this.items, ...res.data].map(item => [item.id, item])).values()]
                this.currentPage = res.meta.current_page
                this.lastPage = res.meta.last_page
                this.totalCount = res.meta.total
            } catch {
                if (fresh()) this.loadMoreError = 'Không thể tải thêm món. Vui lòng thử lại.'
            } finally {
                if (fresh()) this.loadingMore = false
            }
        },

        /**
         * Cập nhật 1 filter (re-fetch ngay).
         */
        async setFilter<K extends keyof MenuFilters>(key: K, value: MenuFilters[K]): Promise<void> {
            this.filters = { ...this.filters, [key]: value }
            await this.loadItems()
        },

        /**
         * Cập nhật nhiều filter cùng lúc (1 lần re-fetch).
         */
        async setFilters(patch: Partial<MenuFilters>): Promise<void> {
            this.filters = { ...this.filters, ...patch }
            await this.loadItems()
        },

        /**
         * Reset toàn bộ filter về default.
         */
        async resetFilters(): Promise<void> {
            this.filters = {
                category: null,
                search: '',
                size: null,
                min_price: null,
                max_price: null,
                sort: 'default',
            }
            await this.loadItems()
        },

        /**
         * Khởi tạo từ URL query (gọi 1 lần khi vào trang).
         */
        async initFromQuery(initial: MenuFilters): Promise<void> {
            this.filters = { ...initial }
            await Promise.all([
                this.loadCategories(),
                this.loadItems(),
            ])
        },
    },
})
