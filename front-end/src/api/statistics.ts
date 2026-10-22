/**
 * statistics.ts — API client cho backend thống kê (Phase 6).
 *
 * Endpoints đều dưới `/api/staff/manager/statistics/*` — yêu cầu role MANAGER.
 * Filter chung: `period=`, `from=`, `to=`, `limit=` (tuỳ endpoint).
 *
 *  - period: today | yesterday | last_7_days | last_30_days
 *         | this_week | previous_week | this_month | previous_month
 *         | this_quarter | this_year | custom
 *  - custom yêu cầu from & to (YYYY-MM-DD)
 *  - limit: 5 | 10 | 20 (top-products, low-products, top-ingredients)
 */
import apiClient from './client'

/* ====================== Common types ====================== */

export type StatisticsPeriod =
    | 'today'
    | 'yesterday'
    | 'last_7_days'
    | 'last_30_days'
    | 'this_week'
    | 'previous_week'
    | 'this_month'
    | 'previous_month'
    | 'this_quarter'
    | 'this_year'
    | 'custom'

export interface StatisticsPeriodRange {
    from: string
    to: string
    label?: string
}

export interface GrowthMap {
    revenue?: number | null
    total_orders?: number | null
    sold_quantity?: number | null
    new_customers?: number | null
    total_reservations?: number | null
    [k: string]: number | null | undefined
}

/* ====================== Overview ====================== */

export interface OverviewData {
    revenue: number
    net_revenue: number
    total_orders: number
    completed_orders: number
    cancelled_orders: number
    paid_orders: number
    unpaid_orders: number
    avg_order_value: number
    sold_quantity: number
    total_customers: number
    new_customers: number
    total_reservations: number
    period: StatisticsPeriodRange
    previous: { from: string; to: string }
    growth: GrowthMap
}

/* ====================== Revenue chart ====================== */

export interface RevenueChartData {
    unit: 'hour' | 'day' | 'month'
    labels: string[]
    data: number[]
    count: number[]
    total: number
}

/* ====================== Order status ====================== */

export interface OrderStatusBreakdown {
    total_orders: number
    breakdown: Array<{
        status: string
        count: number
        value: number
        percentage: number
    }>
}

/* ====================== Top products ====================== */

export interface TopProductsData {
    limit: number
    total_revenue: number
    items: Array<{
        rank: number
        id: number
        name: string
        image: string | null
        category: string | null
        sold_quantity: number
        revenue: number
        revenue_share: number
    }>
}

/* ====================== Low-selling ====================== */

export interface LowProductsData {
    limit: number
    no_sales_count: number
    items: Array<{
        rank: number
        id: number
        name: string
        image: string | null
        category: string | null
        sold_quantity: number
        revenue: number
    }>
}

/* ====================== Categories ====================== */

export interface CategoryStat {
    category_id: number
    category_name: string
    sold_quantity: number
    revenue: number
    revenue_share: number
    quantity_share: number
}

export interface CategoryStatsData {
    total_revenue: number
    total_quantity: number
    categories: CategoryStat[]
}

/* ====================== Payment methods ====================== */

export interface PaymentMethodStat {
    code: string
    name: string
    amount: number
    count: number
    share: number
    prev_amount: number
    growth_pct: number | null
}

export interface PaymentStatsData {
    total_revenue: number
    methods: PaymentMethodStat[]
}

/* ====================== Customers ====================== */

export interface CustomerStatsData {
    total_customers: number
    new_customers: number
    returning_customers: number
    new_customers_growth_pct: number | null
    top_customers: Array<{
        id: number
        name: string
        phone: string | null
        email: string | null
        order_count: number
        total_spent: number
    }>
}

/* ====================== Reservations ====================== */

export interface ReservationStatsData {
    total_reservations: number
    pending: number
    confirmed: number
    arrived: number
    completed: number
    cancelled: number
    no_show: number
    total_growth_pct: number | null
}

/* ====================== Peak hours ====================== */

export interface PeakHoursData {
    by_hour: Array<{ hour: number; order_count: number; revenue: number }>
    by_weekday: Array<{ weekday: string; order_count: number; revenue: number }>
    peak_hours: Array<{ hour: number; order_count: number; revenue: number }>
}

/* ====================== Inventory snapshot ====================== */

export interface InventoryStatsData {
    total_ingredients: number
    low_stock: number
    out_of_stock: number
    near_expiry: number
    expired: number
    inventory_value: number
    import_value: number
    consumption: number | null
    as_of: string
}

/* ====================== Top consumed ingredients ====================== */

export interface TopIngredient {
    id: number
    name: string
    unit: string
    consumed_quantity: number
    consumed_value: number
}

export interface TopIngredientsData {
    limit: number
    items: TopIngredient[]
}

/* ====================== Food cost ====================== */

export interface FoodCostItem {
    id: number
    name: string
    selling_price: number
    ingredient_cost: number
    gross_profit: number
    food_cost_pct: number
    sold_quantity: number
    revenue: number
}

export interface FoodCostData {
    period: StatisticsPeriodRange
    items: FoodCostItem[]
    note: string
}

/* ====================== Query builder ====================== */

export interface StatisticsQuery {
    period?: StatisticsPeriod
    from?: string
    to?: string
    limit?: number
}

function buildParams(q: StatisticsQuery | undefined): Record<string, string | number> {
    const params: Record<string, string | number> = {}
    if (!q) return params
    if (q.period) params.period = q.period
    if (q.from) params.from = q.from
    if (q.to) params.to = q.to
    if (q.limit !== undefined && q.limit !== null) params.limit = q.limit
    return params
}

async function get<T>(url: string, q?: StatisticsQuery): Promise<T> {
    const res = await apiClient.get<{ success: boolean; data: T }>(url, { params: buildParams(q) })
    return res.data.data
}

/* ====================== Endpoint helpers ====================== */

export const statistics = {
    /** GET /api/staff/manager/statistics/overview */
    overview(q?: StatisticsQuery): Promise<OverviewData> {
        return get<OverviewData>('/staff/manager/statistics/overview', q)
    },

    /** GET /api/staff/manager/statistics/revenue */
    revenue(q?: StatisticsQuery): Promise<RevenueChartData> {
        return get<RevenueChartData>('/staff/manager/statistics/revenue', q)
    },

    /** GET /api/staff/manager/statistics/orders */
    orders(q?: StatisticsQuery): Promise<OrderStatusBreakdown> {
        return get<OrderStatusBreakdown>('/staff/manager/statistics/orders', q)
    },

    /** GET /api/staff/manager/statistics/top-products */
    topProducts(q?: StatisticsQuery): Promise<TopProductsData> {
        return get<TopProductsData>('/staff/manager/statistics/top-products', q)
    },

    /** GET /api/staff/manager/statistics/low-products */
    lowProducts(q?: StatisticsQuery): Promise<LowProductsData> {
        return get<LowProductsData>('/staff/manager/statistics/low-products', q)
    },

    /** GET /api/staff/manager/statistics/categories */
    categories(q?: StatisticsQuery): Promise<CategoryStatsData> {
        return get<CategoryStatsData>('/staff/manager/statistics/categories', q)
    },

    /** GET /api/staff/manager/statistics/payments */
    payments(q?: StatisticsQuery): Promise<PaymentStatsData> {
        return get<PaymentStatsData>('/staff/manager/statistics/payments', q)
    },

    /** GET /api/staff/manager/statistics/customers */
    customers(q?: StatisticsQuery): Promise<CustomerStatsData> {
        return get<CustomerStatsData>('/staff/manager/statistics/customers', q)
    },

    /** GET /api/staff/manager/statistics/reservations */
    reservations(q?: StatisticsQuery): Promise<ReservationStatsData> {
        return get<ReservationStatsData>('/staff/manager/statistics/reservations', q)
    },

    /** GET /api/staff/manager/statistics/peak-hours */
    peakHours(q?: StatisticsQuery): Promise<PeakHoursData> {
        return get<PeakHoursData>('/staff/manager/statistics/peak-hours', q)
    },

    /** GET /api/staff/manager/statistics/inventory */
    inventory(q?: StatisticsQuery): Promise<InventoryStatsData> {
        return get<InventoryStatsData>('/staff/manager/statistics/inventory', q)
    },

    /** GET /api/staff/manager/statistics/top-ingredients */
    topIngredients(q?: StatisticsQuery): Promise<TopIngredientsData> {
        return get<TopIngredientsData>('/staff/manager/statistics/top-ingredients', q)
    },

    /** GET /api/staff/manager/statistics/food-cost */
    foodCost(q?: StatisticsQuery): Promise<FoodCostData> {
        return get<FoodCostData>('/staff/manager/statistics/food-cost', q)
    },
}
