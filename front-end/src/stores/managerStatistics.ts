/**
 * managerStatisticsStore — Pinia store cho dashboard thống kê & inventory alerts.
 *
 *  - Giữ 1 filter chung (period + from/to + limit) cho TOÀN BỘ widget.
 *  - Khi filter đổi → refetchAll() để tất cả widget reload theo filter mới.
 *  - Từng widget có state loading riêng (để skeleton không "đứng" cả page).
 *  - Dùng beginRead/markDataWrite realtime utilities để tránh race condition.
 */
import { defineStore } from 'pinia'
import { beginRead } from '@/utils/realtime'

import { inventoryAlerts, type IngredientAlert } from '@/api/inventory'
import {
    statistics,
    type OverviewData,
    type RevenueChartData,
    type OrderStatusBreakdown,
    type TopProductsData,
    type LowProductsData,
    type CategoryStatsData,
    type PaymentStatsData,
    type CustomerStatsData,
    type ReservationStatsData,
    type PeakHoursData,
    type InventoryStatsData,
    type TopIngredientsData,
    type FoodCostData,
    type StatisticsPeriod,
    type StatisticsQuery,
} from '@/api/statistics'

export const useManagerStatisticsStore = defineStore('managerStatistics', {
    state: () => ({
        // Filter chung
        period: 'last_7_days' as StatisticsPeriod,
        fromDate: '' as string,
        toDate: '' as string,
        topLimit: 10 as number,

        // Data
        overview: null as OverviewData | null,
        revenueChart: null as RevenueChartData | null,
        orderStatus: null as OrderStatusBreakdown | null,
        topProducts: null as TopProductsData | null,
        lowProducts: null as LowProductsData | null,
        categories: null as CategoryStatsData | null,
        payments: null as PaymentStatsData | null,
        customers: null as CustomerStatsData | null,
        reservations: null as ReservationStatsData | null,
        peakHours: null as PeakHoursData | null,
        inventoryStats: null as InventoryStatsData | null,
        topIngredients: null as TopIngredientsData | null,
        inventoryAlerts: [] as IngredientAlert[],

        // Loading riêng từng widget (cho skeleton + retry)
        loading: {
            overview: false,
            revenueChart: false,
            orderStatus: false,
            topProducts: false,
            lowProducts: false,
            categories: false,
            payments: false,
            customers: false,
            reservations: false,
            peakHours: false,
            inventoryStats: false,
            topIngredients: false,
            inventoryAlerts: false,
        },

        // Error riêng từng widget
        errors: {} as Record<string, string>,

        // Realtime token (cancel old fetches)
        liveReads: {} as Record<string, unknown>,
    }),

    getters: {
        /** Query thống nhất cho tất cả widget statistics. */
        queryBase(state): StatisticsQuery {
            const q: StatisticsQuery = { period: state.period }
            if (state.period === 'custom') {
                q.from = state.fromDate
                q.to = state.toDate
            }
            return q
        },

        /** Query cho top-X endpoints. */
        queryTop(state): StatisticsQuery {
            return { ...this.queryBase, limit: state.topLimit }
        },

        hasAnyData(state): boolean {
            return (
                state.overview !== null ||
                state.revenueChart !== null ||
                state.orderStatus !== null ||
                state.topProducts !== null ||
                state.lowProducts !== null ||
                state.categories !== null ||
                state.payments !== null ||
                state.customers !== null ||
                state.reservations !== null ||
                state.peakHours !== null ||
                state.inventoryStats !== null ||
                state.topIngredients !== null
            )
        },

        hasAnyError(state): boolean {
            return Object.keys(state.errors).length > 0
        },

        isAnyLoading(state): boolean {
            return Object.values(state.loading).some(Boolean)
        },
    },

    actions: {
        /* ============================================================
         *  Filter actions
         * ========================================================== */
        setPeriod(p: StatisticsPeriod): void {
            this.period = p
            if (p !== 'custom') {
                this.fromDate = ''
                this.toDate = ''
            }
        },

        setCustomRange(from: string, to: string): void {
            this.period = 'custom'
            this.fromDate = from
            this.toDate = to
        },

        setTopLimit(limit: number): void {
            this.topLimit = Math.max(5, Math.min(20, limit))
        },

        /* ============================================================
         *  Per-widget fetchers
         * ========================================================== */
        async fetchOverview(quiet = false): Promise<void> {
            await this._run('overview', quiet, () => statistics.overview(this.queryBase))
        },
        async fetchRevenueChart(quiet = false): Promise<void> {
            await this._run('revenueChart', quiet, () => statistics.revenue(this.queryBase))
        },
        async fetchOrderStatus(quiet = false): Promise<void> {
            await this._run('orderStatus', quiet, () => statistics.orders(this.queryBase))
        },
        async fetchTopProducts(quiet = false): Promise<void> {
            await this._run('topProducts', quiet, () => statistics.topProducts(this.queryTop))
        },
        async fetchLowProducts(quiet = false): Promise<void> {
            await this._run('lowProducts', quiet, () => statistics.lowProducts(this.queryTop))
        },
        async fetchCategories(quiet = false): Promise<void> {
            await this._run('categories', quiet, () => statistics.categories(this.queryBase))
        },
        async fetchPayments(quiet = false): Promise<void> {
            await this._run('payments', quiet, () => statistics.payments(this.queryBase))
        },
        async fetchCustomers(quiet = false): Promise<void> {
            await this._run('customers', quiet, () => statistics.customers(this.queryBase))
        },
        async fetchReservations(quiet = false): Promise<void> {
            await this._run('reservations', quiet, () => statistics.reservations(this.queryBase))
        },
        async fetchPeakHours(quiet = false): Promise<void> {
            await this._run('peakHours', quiet, () => statistics.peakHours(this.queryBase))
        },
        async fetchInventoryStats(quiet = false): Promise<void> {
            await this._run('inventoryStats', quiet, () => statistics.inventory(this.queryBase))
        },
        async fetchTopIngredients(quiet = false): Promise<void> {
            await this._run('topIngredients', quiet, () => statistics.topIngredients(this.queryTop))
        },
        async fetchInventoryAlerts(quiet = false): Promise<void> {
            await this._run('inventoryAlerts', quiet, () => inventoryAlerts.list(), (d) => d as IngredientAlert[])
        },

        /**
         * Generic helper: chạy 1 fetcher, cập nhật loading/error/data.
         *  - Dùng beginRead để huỷ các request cũ khi có realtime refresh.
         *  - Mỗi widget có slot riêng trong state.loading / state.errors.
         */
        async _run<T>(
            key: string,
            quiet: boolean,
            fetcher: () => Promise<T>,
            extractor?: (data: T) => unknown
        ): Promise<void> {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const self = this as any
            if (!quiet) self.loading[key] = true
            if (!quiet) delete self.errors[key]
            const fresh = beginRead(self.liveReads, key, quiet)
            try {
                const data = await fetcher()
                if (!fresh()) return
                const payload = extractor ? extractor(data) : data
                self[key] = payload
            } catch (err) {
                if (quiet) throw err
                const msg = err instanceof Error ? err.message : 'Không tải được dữ liệu'
                self.errors[key] = msg
                // eslint-disable-next-line no-console
                console.error(`[statistics] ${key} error:`, err)
            } finally {
                if (!quiet && fresh()) self.loading[key] = false
            }
        },

        /** Refetch toàn bộ widget theo filter hiện tại. */
        async fetchAll(quiet = false): Promise<void> {
            // Inventory alerts không phụ thuộc period → fetch song song 1 lần.
            await Promise.all([
                this.fetchOverview(quiet),
                this.fetchRevenueChart(quiet),
                this.fetchOrderStatus(quiet),
                this.fetchTopProducts(quiet),
                this.fetchLowProducts(quiet),
                this.fetchCategories(quiet),
                this.fetchPayments(quiet),
                this.fetchCustomers(quiet),
                this.fetchReservations(quiet),
                this.fetchPeakHours(quiet),
                this.fetchInventoryStats(quiet),
                this.fetchTopIngredients(quiet),
                this.fetchInventoryAlerts(quiet),
            ])
        },

        /** Retry 1 widget cụ thể (gọi từ nút "Thử lại"). */
        async retry(key: string): Promise<void> {
            const map: Record<string, () => Promise<void>> = {
                overview: () => this.fetchOverview(false),
                revenueChart: () => this.fetchRevenueChart(false),
                orderStatus: () => this.fetchOrderStatus(false),
                topProducts: () => this.fetchTopProducts(false),
                lowProducts: () => this.fetchLowProducts(false),
                categories: () => this.fetchCategories(false),
                payments: () => this.fetchPayments(false),
                customers: () => this.fetchCustomers(false),
                reservations: () => this.fetchReservations(false),
                peakHours: () => this.fetchPeakHours(false),
                inventoryStats: () => this.fetchInventoryStats(false),
                topIngredients: () => this.fetchTopIngredients(false),
                inventoryAlerts: () => this.fetchInventoryAlerts(false),
            }
            const fn = map[key]
            if (fn) await fn()
        },
    },
})
