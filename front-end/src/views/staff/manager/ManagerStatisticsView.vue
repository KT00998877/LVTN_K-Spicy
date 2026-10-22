<template>
    <div class="stats-dashboard">
        <!-- ============================================================
             HEADER
             ============================================================ -->
        <header class="stats-header">
            <div class="stats-header__intro">
                <span class="stats-eyebrow">QUẢN LÝ NHÀ HÀNG · THỐNG KÊ</span>
                <h1 class="stats-header__title">Dashboard thống kê</h1>
                <p class="stats-header__sub">
                    Toàn bộ số liệu <strong>doanh thu</strong>, <strong>đơn hàng</strong>,
                    <strong>thanh toán</strong>, <strong>giờ cao điểm</strong> và
                    <strong>cảnh báo kho</strong> theo bộ lọc thời gian bên dưới.
                </p>
            </div>
            <button
                class="stats-btn stats-btn--ghost"
                :disabled="statsStore.isAnyLoading"
                @click="onRefresh"
            >
                <svg
                    :class="['stats-btn__icon', { 'stats-btn__icon--spin': statsStore.isAnyLoading }]"
                    width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" aria-hidden="true"
                >
                    <path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
                    <polyline points="21 3 21 8 16 8" />
                </svg>
                {{ statsStore.isAnyLoading ? 'Đang tải…' : 'Làm mới' }}
            </button>
        </header>

        <!-- ============================================================
             FILTER
             ============================================================ -->
        <PeriodFilter
            :period="statsStore.period"
            :from="statsStore.fromDate"
            :to="statsStore.toDate"
            :loading="statsStore.isAnyLoading"
            :period-label="periodLabel"
            @update:period="onPeriod"
            @update:from="onFrom"
            @update:to="onTo"
            @apply="onApply"
        />

        <!-- ============================================================
             1. KPI CARDS
             ============================================================ -->
        <section class="stats-kpis" aria-labelledby="kpi-title">
            <h2 id="kpi-title" class="visually-hidden">KPI tổng quan</h2>
            <article class="stats-kpi stats-kpi--hero">
                <span class="stats-kpi__label">Doanh thu thực</span>
                <div class="stats-kpi__value">
                    {{ formatVND(overview?.revenue ?? 0) }}
                </div>
                <GrowthPill :value="overview?.growth.revenue ?? null" suffix="so với kỳ trước" />
            </article>
            <article class="stats-kpi">
                <span class="stats-kpi__label">Đơn hàng</span>
                <div class="stats-kpi__value">{{ formatInt(overview?.total_orders ?? 0) }}</div>
                <span class="stats-kpi__meta">
                    Hoàn thành <strong>{{ formatInt(overview?.completed_orders ?? 0) }}</strong>
                    · Hủy <strong>{{ formatInt(overview?.cancelled_orders ?? 0) }}</strong>
                </span>
                <GrowthPill :value="overview?.growth.total_orders ?? null" suffix="so với kỳ trước" />
            </article>
            <article class="stats-kpi">
                <span class="stats-kpi__label">Giá trị TB / đơn</span>
                <div class="stats-kpi__value">{{ formatVND(overview?.avg_order_value ?? 0) }}</div>
                <span class="stats-kpi__meta">
                    Đã thanh toán <strong>{{ formatInt(overview?.paid_orders ?? 0) }}</strong>
                    · Chưa <strong>{{ formatInt(overview?.unpaid_orders ?? 0) }}</strong>
                </span>
            </article>
            <article class="stats-kpi">
                <span class="stats-kpi__label">Món đã bán</span>
                <div class="stats-kpi__value">{{ formatInt(overview?.sold_quantity ?? 0) }}</div>
                <span class="stats-kpi__meta">
                    Khách mới <strong>{{ formatInt(overview?.new_customers ?? 0) }}</strong>
                    · Tổng <strong>{{ formatInt(overview?.total_customers ?? 0) }}</strong>
                </span>
                <GrowthPill :value="overview?.growth.sold_quantity ?? null" suffix="lượng món" />
            </article>
            <article class="stats-kpi">
                <span class="stats-kpi__label">Đặt bàn</span>
                <div class="stats-kpi__value">{{ formatInt(overview?.total_reservations ?? 0) }}</div>
                <span class="stats-kpi__meta">
                    Khách mới <strong>{{ formatInt(overview?.new_customers ?? 0) }}</strong> trong kỳ
                </span>
                <GrowthPill :value="overview?.growth.total_reservations ?? null" suffix="so với kỳ trước" />
            </article>
        </section>

        <!-- ============================================================
             2. REVENUE TREND
             ============================================================ -->
        <section class="stats-section" aria-labelledby="rev-title">
            <div class="stats-section__head">
                <h2 id="rev-title" class="stats-section__title">Diễn biến doanh thu</h2>
                <span class="stats-section__meta">
                    Tổng: <strong>{{ formatVND(revenueChart?.total ?? 0) }}</strong>
                    · {{ revenueChart?.count?.reduce((a, b) => a + b, 0) ?? 0 }} giao dịch thành công
                </span>
            </div>
            <div class="stats-section__body">
                <WidgetState
                    :loading="statsStore.loading.revenueChart"
                    :error="statsStore.errors.revenueChart ?? null"
                    :empty="!revenueChart || (revenueChart.data?.length ?? 0) === 0"
                    empty-message="Chưa có giao dịch thành công trong khoảng thời gian này."
                    @retry="statsStore.retry('revenueChart')"
                >
                    <StatsLineChart
                        v-if="revenueChart && revenueChart.labels.length"
                        :labels="revenueChart.labels"
                        :data="revenueChart.data"
                        :label="revenueChart.unit === 'hour' ? 'Doanh thu / giờ' : revenueChart.unit === 'day' ? 'Doanh thu / ngày' : 'Doanh thu / tháng'"
                        :ariaLabel="`Biểu đồ đường doanh thu theo ${revenueChart.unit}`"
                        :formatValue="formatVND"
                        :height="260"
                    />
                </WidgetState>
            </div>
        </section>

        <!-- ============================================================
             3. ORDER STATUS + 4. TOP PRODUCTS
             ============================================================ -->
        <section class="stats-grid">
            <article class="stats-section" aria-labelledby="ord-title">
                <div class="stats-section__head">
                    <h2 id="ord-title" class="stats-section__title">Trạng thái đơn hàng</h2>
                    <span class="stats-section__meta">
                        {{ formatInt(orderStatus?.total_orders ?? 0) }} đơn trong kỳ
                    </span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.orderStatus"
                        :error="statsStore.errors.orderStatus ?? null"
                        :empty="!orderStatus || (orderStatus.breakdown?.length ?? 0) === 0"
                        empty-message="Chưa có đơn nào trong kỳ."
                        @retry="statsStore.retry('orderStatus')"
                    >
                        <StatsDoughnutChart
                            v-if="orderStatus && orderStatus.breakdown.length"
                            :labels="orderStatus.breakdown.map((b) => statusLabel(b.status))"
                            :data="orderStatus.breakdown.map((b) => b.count)"
                            :ariaLabel="'Biểu đồ tròn trạng thái đơn'"
                            :height="260"
                        />
                    </WidgetState>
                </div>
                <ul v-if="orderStatus && orderStatus.breakdown.length" class="stats-status-list" aria-label="Chi tiết trạng thái">
                    <li v-for="b in orderStatus.breakdown" :key="`st-${b.status}`">
                        <span class="stats-status-list__chip" :class="`stats-status-list__chip--${b.status.toLowerCase()}`">
                            {{ statusLabel(b.status) }}
                        </span>
                        <span class="stats-status-list__val">
                            <strong>{{ formatInt(b.count) }}</strong>
                            <small>· {{ b.percentage.toFixed(1) }}%</small>
                        </span>
                    </li>
                </ul>
            </article>

            <article class="stats-section" aria-labelledby="topprod-title">
                <div class="stats-section__head">
                    <h2 id="topprod-title" class="stats-section__title">Món bán chạy</h2>
                    <span class="stats-section__meta">
                        Top {{ topProducts?.limit ?? statsStore.topLimit }} theo số phần
                    </span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.topProducts"
                        :error="statsStore.errors.topProducts ?? null"
                        :empty="!topProducts || (topProducts.items?.length ?? 0) === 0"
                        empty-message="Chưa có đơn hoàn tất trong kỳ."
                        @retry="statsStore.retry('topProducts')"
                    >
                        <StatsBarChart
                            v-if="topProducts && topProducts.items.length"
                            :labels="topProducts.items.map((i) => i.name)"
                            :data="topProducts.items.map((i) => i.sold_quantity)"
                            :label="'Số phần bán'"
                            :ariaLabel="'Biểu đồ thanh ngang món bán chạy'"
                            :color="'#d71920'"
                            :height="260"
                        />
                    </WidgetState>
                </div>
                <table v-if="topProducts && topProducts.items.length" class="stats-rank-table" aria-label="Bảng xếp hạng món bán chạy">
                    <thead>
                        <tr><th>#</th><th>Món</th><th>Danh mục</th><th class="ar">Đã bán</th><th class="ar">Doanh thu</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in topProducts.items.slice(0, 8)" :key="`tp-${item.id}`">
                            <td class="stats-rank-table__num">{{ item.rank }}</td>
                            <td>
                                <div class="stats-rank-table__dish">
                                    <img v-if="item.image" :src="item.image" :alt="item.name" loading="lazy" />
                                    <span v-else class="stats-rank-table__ph" aria-hidden="true">🍜</span>
                                    <strong>{{ item.name }}</strong>
                                </div>
                            </td>
                            <td>{{ item.category || '—' }}</td>
                            <td class="ar">{{ formatInt(item.sold_quantity) }}</td>
                            <td class="ar">
                                {{ formatVND(item.revenue) }}
                                <small class="stats-rank-table__share">{{ item.revenue_share.toFixed(1) }}%</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </section>

        <!-- ============================================================
             5. CATEGORY PERFORMANCE + 6. PAYMENT METHODS
             ============================================================ -->
        <section class="stats-grid">
            <article class="stats-section" aria-labelledby="cat-title">
                <div class="stats-section__head">
                    <h2 id="cat-title" class="stats-section__title">Hiệu quả theo danh mục</h2>
                    <span class="stats-section__meta">{{ formatVND(categories?.total_revenue ?? 0) }}</span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.categories"
                        :error="statsStore.errors.categories ?? null"
                        :empty="!categories || (categories.categories?.length ?? 0) === 0"
                        empty-message="Chưa có doanh thu theo danh mục trong kỳ."
                        @retry="statsStore.retry('categories')"
                    >
                        <StatsDoughnutChart
                            v-if="categories && categories.categories.length"
                            :labels="categories.categories.map((c) => c.category_name)"
                            :data="categories.categories.map((c) => c.revenue)"
                            :ariaLabel="'Biểu đồ tròn doanh thu theo danh mục'"
                            :height="260"
                        />
                    </WidgetState>
                </div>
            </article>

            <article class="stats-section" aria-labelledby="pay-title">
                <div class="stats-section__head">
                    <h2 id="pay-title" class="stats-section__title">Phương thức thanh toán</h2>
                    <span class="stats-section__meta">{{ formatVND(payments?.total_revenue ?? 0) }}</span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.payments"
                        :error="statsStore.errors.payments ?? null"
                        :empty="!payments || (payments.methods?.length ?? 0) === 0"
                        empty-message="Chưa có thanh toán thành công trong kỳ."
                        @retry="statsStore.retry('payments')"
                    >
                        <StatsDoughnutChart
                            v-if="payments && payments.methods.length"
                            :labels="payments.methods.map((m) => m.name)"
                            :data="payments.methods.map((m) => m.amount)"
                            :ariaLabel="'Biểu đồ tròn phương thức thanh toán'"
                            :colors="paymentColors"
                            :height="260"
                        />
                    </WidgetState>
                </div>
                <ul v-if="payments && payments.methods.length" class="stats-pay-list" aria-label="Chi tiết phương thức thanh toán">
                    <li v-for="m in payments.methods" :key="`pm-${m.code}`">
                        <span class="stats-pay-list__dot" :style="{ background: paymentColor(m.code) }" aria-hidden="true"></span>
                        <span class="stats-pay-list__name">{{ m.name }}</span>
                        <span class="stats-pay-list__amt">{{ formatVND(m.amount) }}</span>
                        <GrowthPill :value="m.growth_pct" small />
                    </li>
                </ul>
            </article>
        </section>

        <!-- ============================================================
             7. PEAK HOURS
             ============================================================ -->
        <section class="stats-section" aria-labelledby="peak-title">
            <div class="stats-section__head">
                <h2 id="peak-title" class="stats-section__title">Giờ cao điểm</h2>
                <span class="stats-section__meta">{{ formatInt(totalOrders) }} đơn không bị hủy trong kỳ</span>
            </div>
            <div class="stats-section__body stats-section__body--wide">
                <WidgetState
                    :loading="statsStore.loading.peakHours"
                    :error="statsStore.errors.peakHours ?? null"
                    :empty="!peakHours || (peakHours.by_hour?.length ?? 0) === 0"
                    empty-message="Chưa có đơn nào trong kỳ."
                    @retry="statsStore.retry('peakHours')"
                >
                    <StatsBarChart
                        v-if="peakHours && peakHours.by_hour.length"
                        :labels="peakHours.by_hour.map((h) => `${h.hour.toString().padStart(2, '0')}h`)"
                        :data="peakHours.by_hour.map((h) => h.order_count)"
                        :label="'Số đơn / giờ'"
                        :ariaLabel="'Biểu đồ thanh đứng giờ cao điểm'"
                        :color="'#ffb000'"
                        :height="260"
                    />
                </WidgetState>
            </div>
        </section>

        <!-- ============================================================
             8. INVENTORY ALERTS + 9. TOP CONSUMED INGREDIENTS
             ============================================================ -->
        <section class="stats-grid">
            <article class="stats-section" aria-labelledby="inv-title">
                <div class="stats-section__head">
                    <h2 id="inv-title" class="stats-section__title">Cảnh báo kho</h2>
                    <span class="stats-section__meta">
                        {{ statsStore.inventoryAlerts.length }} nguyên liệu cần xử lý
                    </span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.inventoryAlerts"
                        :error="statsStore.errors.inventoryAlerts ?? null"
                        :empty="statsStore.inventoryAlerts.length === 0 && !statsStore.loading.inventoryAlerts"
                        empty-message="Mọi nguyên liệu đang ổn. Không có cảnh báo nào."
                        @retry="statsStore.retry('inventoryAlerts')"
                    >
                        <ul class="stats-inv-list" aria-label="Danh sách cảnh báo nguyên liệu">
                            <li
                                v-for="alert in statsStore.inventoryAlerts.slice(0, 8)"
                                :key="`inv-${alert.id}`"
                                class="stats-inv-item"
                                :class="`stats-inv-item--${alert.severity.toLowerCase()}`"
                            >
                                <span class="stats-inv-item__sev" :aria-label="severityLabel(alert.severity)">
                                    {{ severityBadge(alert.severity) }}
                                </span>
                                <div class="stats-inv-item__main">
                                    <strong>{{ alert.name }}</strong>
                                    <span class="stats-inv-item__code">{{ alert.code }} · {{ alert.unit }}</span>
                                </div>
                                <span class="stats-inv-item__qty">
                                    Còn <strong>{{ formatQty(alert.quantity_in_stock) }}</strong>
                                    / Min <strong>{{ formatQty(alert.min_stock_level) }}</strong>
                                </span>
                                <span class="stats-inv-item__msg">{{ alert.message }}</span>
                            </li>
                        </ul>
                    </WidgetState>
                </div>
                <div v-if="inventoryStats" class="stats-inv-stats" aria-label="Thống kê tổng quan kho">
                    <div class="stats-inv-stat"><span>{{ inventoryStats.total_ingredients }}</span><small>Tổng NL</small></div>
                    <div class="stats-inv-stat stats-inv-stat--low"><span>{{ inventoryStats.low_stock }}</span><small>Sắp hết</small></div>
                    <div class="stats-inv-stat stats-inv-stat--out"><span>{{ inventoryStats.out_of_stock }}</span><small>Hết hàng</small></div>
                    <div class="stats-inv-stat stats-inv-stat--ne"><span>{{ inventoryStats.near_expiry }}</span><small>Sắp hết hạn</small></div>
                    <div class="stats-inv-stat stats-inv-stat--exp"><span>{{ inventoryStats.expired }}</span><small>Đã hết hạn</small></div>
                    <div class="stats-inv-stat stats-inv-stat--val"><span>{{ formatVND(inventoryStats.inventory_value) }}</span><small>Giá trị tồn ghi nhận</small></div>
                </div>
                <p class="stats-section__meta">Số tồn và cảnh báo dựa trên lần bếp chốt gần nhất cùng chứng từ kho; chưa trừ phần đã nấu trong ngày.</p>
            </article>

            <article class="stats-section" aria-labelledby="ingt-title">
                <div class="stats-section__head">
                    <h2 id="ingt-title" class="stats-section__title">Nguyên liệu đã dùng theo sổ bếp</h2>
                    <span class="stats-section__meta">Top {{ topIngredients?.limit ?? statsStore.topLimit }}</span>
                </div>
                <div class="stats-section__body">
                    <WidgetState
                        :loading="statsStore.loading.topIngredients"
                        :error="statsStore.errors.topIngredients ?? null"
                        :empty="!topIngredients || (topIngredients.items?.length ?? 0) === 0"
                        empty-message="Chưa có sổ bếp đã chốt trong kỳ."
                        @retry="statsStore.retry('topIngredients')"
                    >
                        <StatsBarChart
                            v-if="topIngredients && topIngredients.items.length"
                            :labels="topIngredients.items.map((i) => `${i.name} (${i.unit})`)"
                            :data="topIngredients.items.map((i) => i.consumed_quantity)"
                            :label="'Lượng tiêu thụ'"
                            :ariaLabel="'Biểu đồ thanh ngang nguyên liệu tiêu thụ'"
                            :color="'#245440'"
                            :formatValue="(v) => formatQty(v)"
                            :height="260"
                        />
                    </WidgetState>
                </div>
            </article>
        </section>

        <p class="stats-footnote">
            Số liệu tính theo giao dịch <strong>thành công</strong>, múi giờ Việt Nam.
            Doanh thu <strong>không</strong> tính đơn hủy hoặc thanh toán thất bại.
            Khoảng "kỳ trước" được tính bằng cùng độ dài ngay trước kỳ hiện tại.
        </p>
    </div>
</template>

<script setup lang="ts">
/**
 * ManagerStatisticsView — dashboard thống kê end-to-end.
 *  - Dùng managerStatistics store (Pinia) + Phase 6 REST API.
 *  - KHÔNG mock data.
 *  - Mỗi widget có loading / error / empty / retry.
 *  - Filter thay đổi → refetch toàn bộ widget song song.
 *  - Realtime: tự refresh khi có write event (qua useRealtimeRefresh).
 */
import { computed, onMounted, ref } from 'vue'

import { useManagerStatisticsStore } from '@/stores/managerStatistics'
import type { StatisticsPeriod } from '@/api/statistics'

import PeriodFilter from '@/components/staff/PeriodFilter.vue'
import StatsLineChart from '@/components/staff/StatsLineChart.vue'
import StatsBarChart from '@/components/staff/StatsBarChart.vue'
import StatsDoughnutChart from '@/components/staff/StatsDoughnutChart.vue'
import GrowthPill from '@/components/staff/GrowthPill.vue'
import WidgetState from '@/components/staff/WidgetState.vue'

import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { formatVND } from '@/composables/useStaffUtils'

const statsStore = useManagerStatisticsStore()

const overview = computed(() => statsStore.overview)
const revenueChart = computed(() => statsStore.revenueChart)
const orderStatus = computed(() => statsStore.orderStatus)
const topProducts = computed(() => statsStore.topProducts)
const categories = computed(() => statsStore.categories)
const payments = computed(() => statsStore.payments)
const peakHours = computed(() => statsStore.peakHours)
const inventoryStats = computed(() => statsStore.inventoryStats)
const topIngredients = computed(() => statsStore.topIngredients)

const totalOrders = computed(() => {
    const list = peakHours.value?.by_hour ?? []
    return list.reduce((s, h) => s + h.order_count, 0)
})

const periodLabel = computed(() => statsStore.overview?.period?.label ?? '')

const PAYMENT_COLORS: Record<string, string> = {
    CASH: '#d71920',
    BANK_TRANSFER: '#ffb000',
    QR: '#245440',
    ONLINE_GATEWAY: '#8b5cf6',
    OTHER: '#5A3825',
}
const paymentColors = computed(() => {
    const list = payments.value?.methods ?? []
    return list.map((m) => PAYMENT_COLORS[m.code] ?? '#d71920')
})
function paymentColor(code: string): string {
    return PAYMENT_COLORS[code] ?? '#d71920'
}

const STATUS_LABELS: Record<string, string> = {
    PENDING: 'Chờ xác nhận',
    CONFIRMED: 'Đã xác nhận',
    PREPARING: 'Đang nấu',
    READY: 'Sẵn sàng',
    SERVED: 'Đã phục vụ',
    DELIVERING: 'Đang giao',
    DELIVERED: 'Đã giao',
    COMPLETED: 'Hoàn thành',
    CANCELLED: 'Đã hủy',
}
function statusLabel(code: string): string {
    return STATUS_LABELS[code] ?? code
}

const SEVERITY_LABELS: Record<string, string> = {
    OUT: 'Hết hàng',
    LOW: 'Sắp hết',
    EXPIRED: 'Hết hạn',
    NEAR_EXPIRY: 'Sắp hết hạn',
}
const SEVERITY_BADGE: Record<string, string> = {
    OUT: 'OUT',
    LOW: 'LOW',
    EXPIRED: 'EXP',
    NEAR_EXPIRY: 'NEAR',
}
function severityLabel(s: string): string { return SEVERITY_LABELS[s] ?? s }
function severityBadge(s: string): string { return SEVERITY_BADGE[s] ?? s }

/* ============================================================
 *  Format helpers (dùng nội bộ view)
 * ========================================================== */
function formatInt(n: number): string { return Math.round(n).toLocaleString('vi-VN') }
function formatQty(n: number): string { return Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 3 }) }

/* ============================================================
 *  Filter wiring (v-model -> store)
 * ========================================================== */
function onPeriod(p: StatisticsPeriod): void { statsStore.setPeriod(p) }
function onFrom(v: string): void { statsStore.fromDate = v }
function onTo(v: string): void { statsStore.toDate = v }

const isFirstLoad = ref(true)
async function onApply(): Promise<void> {
    await statsStore.fetchAll(false)
    isFirstLoad.value = false
}

async function onRefresh(): Promise<void> {
    await statsStore.fetchAll(false)
}

onMounted(() => {
    if (isFirstLoad.value) onApply()
})

// Realtime refresh: khi có write event → fetchAll(quiet=true) không flash loading.
useRealtimeRefresh(
    () => statsStore.fetchAll(true),
    () => !statsStore.isAnyLoading
)
</script>

<style scoped>
/* =============================================================
   Korean Spicy Statistics Dashboard
   - Cream + charcoal + chili red (brand)
   - Dense information, grouped by KPI → trend → breakdown
   ============================================================= */
.stats-dashboard {
    display: flex;
    flex-direction: column;
    gap: 22px;
    color: var(--mgr-charcoal-black, #111111);
}

.visually-hidden {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

/* ============== HEADER ============== */
.stats-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    flex-wrap: wrap;
}
.stats-header__intro { max-width: 720px; }
.stats-header__title {
    font-size: clamp(24px, 3vw, 30px);
    font-weight: 800;
    margin: 6px 0 6px;
    letter-spacing: -0.5px;
    color: var(--mgr-charcoal-black, #111111);
    line-height: 1.2;
}
.stats-header__sub {
    font-size: 14px;
    color: var(--mgr-gray-500, #6b7280);
    line-height: 1.6;
    margin: 0;
}
.stats-header__sub strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }
.stats-eyebrow {
    color: var(--mgr-chili-red, #d71920);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.stats-eyebrow::before {
    content: '';
    width: 18px; height: 2px;
    background: var(--mgr-chili-red, #d71920);
}

/* ============== BUTTON ============== */
.stats-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 9999px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    border: 1.5px solid transparent;
    transition: background-color 150ms ease, color 150ms ease, transform 150ms ease;
}
.stats-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.stats-btn--ghost {
    background: #fff;
    border-color: var(--mgr-gray-200, #e5e7eb);
    color: var(--mgr-charcoal-black, #111111);
}
.stats-btn--ghost:hover {
    border-color: var(--mgr-chili-red, #d71920);
    color: var(--mgr-chili-red, #d71920);
}
.stats-btn__icon--spin { animation: stats-spin 0.9s linear infinite; }
@keyframes stats-spin { to { transform: rotate(360deg); } }

/* ============== KPI CARDS ============== */
.stats-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.stats-kpi {
    background: #fff;
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: border-color 200ms ease;
}
.stats-kpi:hover { border-color: var(--mgr-gray-300, #d1d5db); }
.stats-kpi--hero {
    background: linear-gradient(135deg, #111111 0%, #1b1b1b 100%);
    border-color: transparent;
    color: #fff;
}
.stats-kpi--hero .stats-kpi__label,
.stats-kpi--hero .stats-kpi__meta,
.stats-kpi--hero .stats-kpi__meta strong {
    color: rgba(255, 255, 255, 0.75);
}
.stats-kpi--hero .stats-kpi__value { color: #fff; }
.stats-kpi__label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    color: var(--mgr-gray-500, #6b7280);
}
.stats-kpi__value {
    font-size: clamp(24px, 3vw, 30px);
    font-weight: 800;
    line-height: 1.1;
    color: var(--mgr-charcoal-black, #111111);
    letter-spacing: -0.5px;
    font-variant-numeric: tabular-nums;
    margin-top: 2px;
}
.stats-kpi__meta {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
}
.stats-kpi__meta strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }

/* ============== SECTIONS ============== */
.stats-section {
    background: #fff;
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.stats-section__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 12px;
}
.stats-section__title {
    font-size: 15px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--mgr-charcoal-black, #111111);
    margin: 0;
}
.stats-section__meta {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    font-variant-numeric: tabular-nums;
}
.stats-section__meta strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }
.stats-section__body {
    position: relative;
    min-height: 260px;
}
.stats-section__body--wide { min-height: 280px; }

.stats-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
}

/* ============== ORDER STATUS LIST ============== */
.stats-status-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.stats-status-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 10px;
    background: var(--mgr-gray-50, #f9fafb);
}
.stats-status-list__chip {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 4px 10px;
    border-radius: 9999px;
}
.stats-status-list__chip--pending { background: #fff5e6; color: #b45309; }
.stats-status-list__chip--confirmed { background: #dcfce7; color: #166534; }
.stats-status-list__chip--preparing { background: #fee2e2; color: #991b1b; }
.stats-status-list__chip--ready { background: #ede9fe; color: #6d28d9; }
.stats-status-list__chip--served { background: #d1fae5; color: #065f46; }
.stats-status-list__chip--delivering { background: #fef3c7; color: #92400e; }
.stats-status-list__chip--delivered { background: #cffafe; color: #155e75; }
.stats-status-list__chip--completed { background: #dcfce7; color: #166534; }
.stats-status-list__chip--cancelled { background: var(--mgr-gray-100, #f3f4f6); color: var(--mgr-gray-600, #4b5563); }
.stats-status-list__val {
    font-size: 13px;
    color: var(--mgr-charcoal-black, #111111);
    font-variant-numeric: tabular-nums;
}
.stats-status-list__val strong { font-weight: 800; margin-right: 6px; }
.stats-status-list__val small { color: var(--mgr-gray-500, #6b7280); }

/* ============== TOP PRODUCTS TABLE ============== */
.stats-rank-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.stats-rank-table th {
    background: var(--mgr-gray-50, #f9fafb);
    color: var(--mgr-gray-500, #6b7280);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    text-align: left;
    padding: 10px 12px;
    border-bottom: 1px solid var(--mgr-gray-200, #e5e7eb);
}
.stats-rank-table th.ar, .stats-rank-table td.ar { text-align: right; }
.stats-rank-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--mgr-gray-100, #f3f4f6);
}
.stats-rank-table__num {
    font-weight: 800;
    color: var(--mgr-chili-red, #d71920);
    width: 36px;
    font-variant-numeric: tabular-nums;
}
.stats-rank-table__dish {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.stats-rank-table__dish img {
    width: 36px; height: 36px;
    border-radius: 8px;
    object-fit: cover;
    flex: 0 0 36px;
    background: var(--mgr-gray-100, #f3f4f6);
}
.stats-rank-table__ph {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: var(--mgr-cream-dark, #fff5e6);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex: 0 0 36px;
}
.stats-rank-table__dish strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--mgr-charcoal-black, #111111);
}
.stats-rank-table__share {
    margin-left: 6px;
    color: var(--mgr-gray-500, #6b7280);
    font-size: 11px;
}

/* ============== PAYMENT LIST ============== */
.stats-pay-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px;
}
.stats-pay-list li {
    display: grid;
    grid-template-columns: 12px 1fr auto;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--mgr-gray-50, #f9fafb);
    border-radius: 10px;
}
.stats-pay-list__dot {
    width: 10px; height: 10px;
    border-radius: 50%;
}
.stats-pay-list__name {
    font-size: 12px;
    color: var(--mgr-charcoal-black, #111111);
    font-weight: 600;
}
.stats-pay-list__amt {
    font-size: 12px;
    font-weight: 800;
    color: var(--mgr-chili-red, #d71920);
    font-variant-numeric: tabular-nums;
    margin-left: auto;
}

/* ============== INVENTORY ALERTS ============== */
.stats-inv-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.stats-inv-item {
    display: grid;
    grid-template-columns: 56px 1fr auto;
    grid-template-rows: auto auto;
    gap: 6px 12px;
    padding: 10px 14px;
    background: var(--mgr-gray-50, #f9fafb);
    border-radius: 10px;
    border-left: 4px solid var(--mgr-gray-300, #d1d5db);
}
.stats-inv-item--out { border-left-color: #d71920; background: #fee2e2; }
.stats-inv-item--low { border-left-color: #ffb000; background: #fff5e6; }
.stats-inv-item--expired { border-left-color: #6b7280; background: var(--mgr-gray-100, #f3f4f6); }
.stats-inv-item--near_expiry { border-left-color: #8b5cf6; background: #ede9fe; }
.stats-inv-item__sev {
    grid-row: 1 / 3;
    align-self: center;
    font-size: 11px;
    font-weight: 800;
    color: #fff;
    padding: 4px 10px;
    border-radius: 6px;
    background: var(--mgr-charcoal-black, #111111);
    text-align: center;
    letter-spacing: 0.5px;
}
.stats-inv-item--out .stats-inv-item__sev { background: #d71920; }
.stats-inv-item--low .stats-inv-item__sev { background: #ffb000; color: #111; }
.stats-inv-item--expired .stats-inv-item__sev { background: #4b5563; }
.stats-inv-item--near_expiry .stats-inv-item__sev { background: #8b5cf6; }
.stats-inv-item__main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.stats-inv-item__main strong {
    font-size: 14px;
    color: var(--mgr-charcoal-black, #111111);
}
.stats-inv-item__code {
    font-size: 11px;
    color: var(--mgr-gray-500, #6b7280);
}
.stats-inv-item__qty {
    font-size: 12px;
    color: var(--mgr-gray-700, #374151);
    font-variant-numeric: tabular-nums;
    align-self: center;
    white-space: nowrap;
}
.stats-inv-item__qty strong { font-weight: 700; color: var(--mgr-charcoal-black, #111111); }
.stats-inv-item__msg {
    grid-column: 2 / 4;
    font-size: 12px;
    color: var(--mgr-gray-600, #4b5563);
    margin-top: 2px;
}
.stats-inv-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    padding-top: 8px;
    border-top: 1px dashed var(--mgr-gray-200, #e5e7eb);
}
.stats-inv-stat {
    background: var(--mgr-gray-50, #f9fafb);
    border-radius: 10px;
    padding: 12px 10px;
    text-align: center;
}
.stats-inv-stat span {
    display: block;
    font-size: 22px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    font-variant-numeric: tabular-nums;
}
.stats-inv-stat small {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--mgr-gray-500, #6b7280);
    display: block;
    margin-top: 2px;
}
.stats-inv-stat--low span { color: #b45309; }
.stats-inv-stat--out span { color: #991b1b; }
.stats-inv-stat--ne span { color: #6d28d9; }
.stats-inv-stat--exp span { color: #4b5563; }
.stats-inv-stat--val span { color: var(--mgr-chili-red, #d71920); }

/* ============== FOOTNOTE ============== */
.stats-footnote {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    line-height: 1.6;
    margin: 0;
    border-top: 1px dashed var(--mgr-gray-200, #e5e7eb);
    padding-top: 16px;
}
.stats-footnote strong { color: var(--mgr-charcoal-black, #111111); }

/* ============== RESPONSIVE ============== */
@media (max-width: 960px) {
    .stats-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .stats-header { flex-direction: column; align-items: stretch; }
    .stats-pay-list { grid-template-columns: 1fr; }
    .stats-inv-item { grid-template-columns: 48px 1fr; }
    .stats-inv-item__qty { grid-column: 2 / 3; }
}
</style>
