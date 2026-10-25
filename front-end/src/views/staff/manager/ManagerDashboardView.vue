<template>
    <div class="mgr-dashboard">
        <!-- =========================================================
             PAGE HEADER — Where am I + primary action
             ========================================================= -->
        <header class="mgr-header">
            <div class="mgr-header__intro">
                <span class="mgr-eyebrow">QUẢN LÝ NHÀ HÀNG</span>
                <h1 class="mgr-header__title">Tổng quan ngày {{ todayLabel }}</h1>
                <p class="mgr-header__sub">
                    Theo dõi <strong>doanh thu</strong>, <strong>trạng thái bếp</strong>,
                    <strong>lượt khách</strong> và những <strong>cảnh báo vận hành</strong> cần xử lý ngay.
                </p>
            </div>
            <div class="mgr-header__actions">
                <button class="mgr-btn mgr-btn--ghost" :disabled="refreshing" @click="refresh()">
                    <svg
                        :class="['mgr-btn__icon', { 'mgr-btn__icon--spin': refreshing }]"
                        width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" aria-hidden="true"
                    >
                        <path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
                        <polyline points="21 3 21 8 16 8" />
                    </svg>
                    {{ refreshing ? 'Đang tải…' : 'Làm mới' }}
                </button>
                <router-link to="/staff/manager/report" class="mgr-btn mgr-btn--primary">
                    Xem thống kê & báo cáo
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <polyline points="9 18 15 12 9 6" />
                    </svg>
                </router-link>
            </div>
        </header>

        <!-- =========================================================
             LOADING / ERROR STATES
             ========================================================= -->
        <div v-if="dashboardStore.loading && !dashboardStore.stats" class="mgr-state mgr-state--loading" role="status">
            <span class="mgr-spinner" aria-hidden="true"></span>
            <p>Đang tải dữ liệu nhà hàng…</p>
        </div>
        <div v-else-if="dashboardStore.error" class="mgr-state mgr-state--error" role="alert">
            <span class="mgr-state__icon" aria-hidden="true">!</span>
            <div>
                <strong>Không tải được dữ liệu</strong>
                <p>{{ dashboardStore.error }}</p>
            </div>
            <button class="mgr-btn mgr-btn--ghost" @click="refresh()">Thử lại</button>
        </div>

        <!-- =========================================================
             HERO METRIC — Today's revenue (the single most important number)
             ========================================================= -->
        <section v-if="dashboardStore.stats" class="mgr-hero" aria-labelledby="mgr-hero-title">
            <div class="mgr-hero__main">
                <span class="mgr-hero__label">Doanh thu hôm nay</span>
                <div class="mgr-hero__value">
                    <span id="mgr-hero-title" class="mgr-hero__amount">{{ formatVND(stats.today_revenue) }}</span>
                    <span
                        v-if="stats.revenue_growth_pct != null"
                        class="mgr-hero__trend"
                        :class="trendClass(stats.revenue_growth_pct)"
                        :aria-label="`So với hôm qua: ${trendWord(stats.revenue_growth_pct)} ${Math.abs(stats.revenue_growth_pct).toFixed(1)} phần trăm`"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <polyline v-if="stats.revenue_growth_pct >= 0" points="6 15 12 9 18 15" />
                            <polyline v-else points="6 9 12 15 18 9" />
                        </svg>
                        {{ Math.abs(stats.revenue_growth_pct).toFixed(1) }}%
                    </span>
                </div>
                <p class="mgr-hero__hint">
                    Tính theo giao dịch thành công,
                    <span>giờ Việt Nam</span>.
                </p>
            </div>
            <div class="mgr-hero__spark" aria-hidden="true">
                <svg viewBox="0 0 320 80" preserveAspectRatio="none" width="100%" height="80">
                    <path :d="sparklinePath" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                    <path :d="`${sparklinePath} L 320 80 L 0 80 Z`" fill="currentColor" opacity="0.12" />
                </svg>
            </div>
        </section>

        <!-- =========================================================
             OPERATIONAL STATUS — Critical info (kitchen + tables + cash)
             Grouping: anything that affects service RIGHT NOW
             ========================================================= -->
        <section v-if="dashboardStore.stats" class="mgr-ops" aria-labelledby="mgr-ops-title">
            <div class="mgr-section-head">
                <h2 id="mgr-ops-title" class="mgr-section-title">
                    <span class="mgr-pulse" aria-hidden="true"></span>
                    Vận hành ngay bây giờ
                </h2>
                <span class="mgr-section-meta">{{ formatVNTime(new Date()) }}</span>
            </div>

            <div class="mgr-ops__grid">
                <!-- Tables -->
                <article class="mgr-tile mgr-tile--tables" aria-label="Tình trạng bàn">
                    <header class="mgr-tile__head">
                        <h3 class="mgr-tile__title">Bàn phục vụ</h3>
                        <router-link to="/staff/waiter/tables" class="mgr-tile__link">Mở</router-link>
                    </header>
                    <div class="mgr-tile__big">
                        {{ stats.active_tables }}
                        <small>/ {{ stats.active_tables + stats.available_tables }}</small>
                    </div>
                    <p class="mgr-tile__sub">đang có khách trong tổng số bàn</p>
                    <div class="mgr-bar" :aria-label="`Tỷ lệ bàn có khách ${tableOccupancyPct.toFixed(0)} phần trăm`">
                        <div class="mgr-bar__fill" :style="{ width: Math.min(100, tableOccupancyPct) + '%' }"></div>
                    </div>
                    <div class="mgr-tile__legend">
                        <span class="mgr-dot mgr-dot--busy"></span> Có khách
                        <span class="mgr-dot mgr-dot--free"></span> Trống
                    </div>
                </article>

                <!-- Kitchen pipeline -->
                <article class="mgr-tile mgr-tile--kitchen" aria-label="Tiến độ chế biến">
                    <header class="mgr-tile__head">
                        <h3 class="mgr-tile__title">Bếp đang chạy</h3>
                        <router-link to="/staff/kitchen/queue" class="mgr-tile__link">Mở</router-link>
                    </header>
                    <div class="mgr-pipeline">
                        <div class="mgr-pipeline__step">
                            <div class="mgr-pipeline__num">{{ stats.pending_items ?? 0 }}</div>
                            <div class="mgr-pipeline__label">Chờ làm</div>
                        </div>
                        <div class="mgr-pipeline__arrow" aria-hidden="true">→</div>
                        <div class="mgr-pipeline__step">
                            <div class="mgr-pipeline__num mgr-pipeline__num--hot">{{ stats.preparing_items }}</div>
                            <div class="mgr-pipeline__label">Đang nấu</div>
                        </div>
                        <div class="mgr-pipeline__arrow" aria-hidden="true">→</div>
                        <div class="mgr-pipeline__step">
                            <div class="mgr-pipeline__num mgr-pipeline__num--ok">{{ stats.ready_items }}</div>
                            <div class="mgr-pipeline__label">Sẵn sàng</div>
                        </div>
                    </div>
                    <p v-if="kitchenBottleneck" class="mgr-pipewarn mgr-pipewarn--warn">
                        <span aria-hidden="true">⚠</span>
                        Bếp đang tích hàng — {{ stats.preparing_items }} món đang nấu, {{ stats.ready_items }} đã ra.
                    </p>
                    <p v-else class="mgr-pipewarn mgr-pipewarn--ok">
                        <span aria-hidden="true">✓</span>
                        Dòng chảy bếp đang thông thoáng.
                    </p>
                </article>

                <!-- Orders received today -->
                <article class="mgr-tile mgr-tile--orders" aria-label="Đơn hàng hôm nay">
                    <header class="mgr-tile__head">
                        <h3 class="mgr-tile__title">Đơn hàng hôm nay</h3>
                        <router-link to="/staff/cashier/invoices" class="mgr-tile__link">Xem hóa đơn</router-link>
                    </header>
                    <div class="mgr-tile__big">
                        {{ stats.today_orders }}
                        <small>đơn</small>
                    </div>
                    <p class="mgr-tile__sub">
                        <strong>{{ stats.today_invoices }}</strong> hóa đơn có phát sinh thu
                    </p>
                    <p class="mgr-tile__hint">
                        Giá trị trung bình:
                        <strong>{{ formatVND(avgOrderValue) }}</strong>/đơn
                    </p>
                    <p v-if="stats.orders_growth_pct != null" class="mgr-tile__hint">
                        So với hôm qua:
                        <strong :class="trendClass(stats.orders_growth_pct)">{{ Math.abs(stats.orders_growth_pct).toFixed(1) }}%</strong>
                        {{ trendWord(stats.orders_growth_pct) }}
                    </p>
                </article>

                <!-- Staff on duty -->
                <article class="mgr-tile mgr-tile--staff" aria-label="Nhân viên đang hoạt động">
                    <header class="mgr-tile__head">
                        <h3 class="mgr-tile__title">Đội ngũ</h3>
                        <router-link to="/staff/manager/staff" class="mgr-tile__link">Quản lý</router-link>
                    </header>
                    <div class="mgr-tile__big">
                        {{ stats.total_staff }}
                        <small>nhân viên</small>
                    </div>
                    <ul class="mgr-roster" aria-label="Tóm tắt vai trò">
                        <li
                            v-for="role in rolesSummary.slice(0, 4)"
                            :key="role.code"
                            class="mgr-roster__row"
                        >
                            <span class="mgr-roster__name">{{ role.label }}</span>
                            <span class="mgr-roster__count">{{ role.count }}</span>
                        </li>
                    </ul>
                </article>
            </div>
        </section>

        <!-- =========================================================
             ANALYTICS — Secondary, lower visual weight
             Revenue trend + order volume + payment mix
             ========================================================= -->
        <section v-if="dashboardStore.report" class="mgr-analytics" aria-labelledby="mgr-analytics-title">
            <div class="mgr-section-head">
                <h2 id="mgr-analytics-title" class="mgr-section-title">Phân tích tháng này</h2>
                <span class="mgr-section-meta">
                    Từ {{ reportMeta.from }} → {{ reportMeta.to }}
                </span>
            </div>

            <div class="mgr-analytics__grid">
                <!-- Revenue trend chart -->
                <article class="mgr-chart">
                    <header class="mgr-chart__head">
                        <div>
                            <h3 class="mgr-chart__title">Diễn biến doanh thu</h3>
                            <p class="mgr-chart__sub">
                                Tổng tháng:
                                <strong>{{ formatVND(report.total_revenue) }}</strong>
                                ·
                                <span :class="trendClass(report.growth_pct ?? 0)">
                                    {{ trendWord(report.growth_pct ?? 0) }}
                                    {{ report.growth_pct != null ? Math.abs(report.growth_pct).toFixed(1) + '%' : '—' }}
                                </span>
                                so với kỳ trước
                            </p>
                        </div>
                        <div class="mgr-chart__legend">
                            <span class="mgr-legend-item"><i class="mgr-legend-swatch mgr-legend-swatch--chili"></i> Doanh thu / ngày</span>
                        </div>
                    </header>
                    <div v-if="!report.chart_data?.length" class="mgr-chart__empty">
                        Chưa có dữ liệu trong khoảng thời gian này.
                    </div>
                    <div v-else class="mgr-bars" :aria-label="`Biểu đồ doanh thu ${report.daily.length} ngày`">
                        <div
                            v-for="(point, idx) in report.chart_data"
                            :key="idx"
                            class="mgr-bars__col"
                            :title="`${point.label} · ${formatVND(point.value)} · ${point.count} hóa đơn`"
                        >
                            <div class="mgr-bars__val">{{ shortVND(point.value) }}</div>
                            <div
                                class="mgr-bars__bar"
                                :style="{ height: Math.max(4, barHeight(point.value)) + '%' }"
                                :aria-label="`${formatVND(point.value)} ngày ${point.label}`"
                            ></div>
                            <div class="mgr-bars__lbl">{{ point.label }}</div>
                        </div>
                    </div>
                </article>

                <!-- Payment + order-type breakdown (answers: where is money coming from) -->
                <article class="mgr-mix">
                    <h3 class="mgr-mix__title">Nguồn thu trong kỳ</h3>

                    <h4 class="mgr-mix__group">Theo hình thức phục vụ</h4>
                    <ul class="mgr-mix__list">
                        <li v-for="row in (report.by_order_type || [])" :key="`ot-${row.code}`">
                            <div class="mgr-mix__head">
                                <span class="mgr-mix__chip" :class="`mgr-mix__chip--${orderTypeKey(row.code)}`">
                                    {{ orderTypeLabel(row.code) }}
                                </span>
                                <span class="mgr-mix__val">{{ formatVND(row.amount) }}</span>
                            </div>
                            <div class="mgr-mix__bar">
                                <div
                                    class="mgr-mix__fill"
                                    :class="`mgr-mix__fill--${orderTypeKey(row.code)}`"
                                    :style="{ width: pctOf(row.amount, report.total_revenue) + '%' }"
                                ></div>
                            </div>
                            <div class="mgr-mix__meta">
                                {{ row.count }} hóa đơn
                                ·
                                <strong>{{ pctOf(row.amount, report.total_revenue).toFixed(1) }}%</strong>
                            </div>
                        </li>
                        <li v-if="!report.by_order_type?.length" class="mgr-mix__empty">
                            Chưa có giao dịch trong kỳ.
                        </li>
                    </ul>

                    <h4 class="mgr-mix__group mgr-mix__group--mt">Theo phương thức thanh toán</h4>
                    <ul class="mgr-mix__mini">
                        <li v-for="row in paymentMix" :key="`pm-${row.code}`">
                            <span class="mgr-mix__pay">{{ paymentLabel(row.code) }}</span>
                            <span class="mgr-mix__pay-val">{{ formatVND(row.amount) }}</span>
                        </li>
                    </ul>
                </article>
            </div>
        </section>

        <!-- =========================================================
             BOTTOM ROW — Top dishes + Operations checklist + Staff pulse
             ========================================================= -->
        <section v-if="dashboardStore.report || dashboardStore.staffUsers.length" class="mgr-bottom">
            <!-- Top dishes -->
            <article class="mgr-card mgr-card--dishes" aria-labelledby="mgr-dishes-title">
                <header class="mgr-card__head">
                    <h2 id="mgr-dishes-title" class="mgr-card__title">
                        Món bán chạy tháng này
                    </h2>
                    <router-link to="/staff/manager/report" class="mgr-card__link">Báo cáo →</router-link>
                </header>
                <div v-if="refreshing && !report" class="mgr-mini-empty">Đang tải…</div>
                <div v-else-if="!dashboardStore.report?.top_dishes?.length" class="mgr-mini-empty">
                    Chưa có đơn hoàn tất trong kỳ này.
                </div>
                <ol v-else class="mgr-dishes">
                    <li
                        v-for="(dish, idx) in dashboardStore.report.top_dishes.slice(0, 5)"
                        :key="dish.id"
                        class="mgr-dishes__item"
                    >
                        <span class="mgr-dishes__rank" :class="rankClass(idx)">{{ idx + 1 }}</span>
                        <div class="mgr-dishes__main">
                            <div class="mgr-dishes__name">{{ dish.name }}</div>
                            <div class="mgr-dishes__meta">
                                <span class="mgr-pill">{{ dish.sold_count }} phần</span>
                                <span class="mgr-dishes__rev">{{ formatVND(dish.revenue) }}</span>
                            </div>
                        </div>
                        <div class="mgr-dishes__bar" aria-hidden="true">
                            <div
                                class="mgr-dishes__fill"
                                :style="{ width: dishShare(dish.sold_count) + '%' }"
                            ></div>
                        </div>
                    </li>
                </ol>
            </article>

            <!-- Menu availability (operational — surfaces 86'd items fast) -->
            <article class="mgr-card mgr-card--menu" aria-labelledby="mgr-menu-title">
                <header class="mgr-card__head">
                    <h2 id="mgr-menu-title" class="mgr-card__title">Trạng thái thực đơn</h2>
                    <router-link to="/staff/manager/menu" class="mgr-card__link">Quản lý →</router-link>
                </header>
                <div class="mgr-avail">
                    <div class="mgr-avail__stat mgr-avail__stat--ok">
                        <span class="mgr-avail__num">{{ menuAvailable.length }}</span>
                        <span class="mgr-avail__lab">Đang bán</span>
                    </div>
                    <div class="mgr-avail__stat mgr-avail__stat--off">
                        <span class="mgr-avail__num">{{ menuUnavailable.length }}</span>
                        <span class="mgr-avail__lab">Tạm hết / ẩn</span>
                    </div>
                </div>
                <p v-if="menuUnavailable.length" class="mgr-card__hint mgr-card__hint--warn">
                    {{ menuUnavailable.length }} món đang ẩn — kiểm tra trước giờ cao điểm.
                </p>
                <p v-else class="mgr-card__hint mgr-card__hint--ok">Tất cả món trong thực đơn đang sẵn sàng phục vụ.</p>
                <ul v-if="menuUnavailable.length" class="mgr-offlist">
                    <li v-for="m in menuUnavailable.slice(0, 4)" :key="`off-${m.id}`">
                        <span class="mgr-offlist__name">{{ m.name }}</span>
                        <span class="mgr-offlist__cat">{{ m.category || 'Khác' }}</span>
                    </li>
                </ul>
            </article>

            <!-- Staff pulse -->
            <article class="mgr-card mgr-card--people" aria-labelledby="mgr-people-title">
                <header class="mgr-card__head">
                    <h2 id="mgr-people-title" class="mgr-card__title">Nhân viên</h2>
                    <router-link to="/staff/manager/staff" class="mgr-card__link">Tất cả →</router-link>
                </header>
                <div v-if="!dashboardStore.staffUsers.length" class="mgr-mini-empty">Chưa có nhân viên.</div>
                <ul v-else class="mgr-people">
                    <li
                        v-for="user in dashboardStore.staffUsers.slice(0, 6)"
                        :key="user.id"
                        class="mgr-people__item"
                        :class="{ 'mgr-people__item--off': !user.is_active }"
                    >
                        <span class="mgr-people__avatar" :style="avatarStyle(user.name)">
                            {{ shortName(user.name) }}
                        </span>
                        <div class="mgr-people__info">
                            <div class="mgr-people__name">{{ user.name }}</div>
                            <div class="mgr-people__role">
                                {{ user.roles.map((r) => roleNames[r] || r).join(' · ') }}
                            </div>
                        </div>
                        <span
                            class="mgr-people__pill"
                            :class="user.is_active ? 'mgr-people__pill--on' : 'mgr-people__pill--off'"
                        >
                            {{ user.is_active ? 'Hoạt động' : 'Tạm ngưng' }}
                        </span>
                    </li>
                </ul>
            </article>
        </section>

        <p class="mgr-footnote">
            Doanh thu tính theo giao dịch <strong>thành công</strong>, múi giờ Việt Nam.
            Món bán chạy chỉ tính trên đơn <strong>đã hoàn tất</strong>.
            Dashboard tự động cập nhật — bấm <strong>Làm mới</strong> để lấy số liệu mới nhất.
        </p>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref } from 'vue'
import { useDashboardStore } from '@/stores/staffDashboard'
import {
    formatVND,
    formatTime,
    shortName,
} from '@/composables/useStaffUtils'
import type { DashboardStats, RevenueReport } from '@/api/staff'

const dashboardStore = useDashboardStore()

/**
 * Empty fallbacks used only when the data hasn't loaded yet.
 * Inside the rendered sections we always check
 *   <section v-if="dashboardStore.stats"> before showing the metrics,
 * so the real data is guaranteed to be present at that point.
 * The fallbacks exist purely so that `stats` and `report` keep
 * non-nullable types and Vue templates type-check cleanly.
 */
const EMPTY_STATS: DashboardStats = {
    today_revenue: 0,
    today_orders: 0,
    today_invoices: 0,
    active_tables: 0,
    available_tables: 0,
    preparing_items: 0,
    pending_items: 0,
    ready_items: 0,
    total_staff: 0,
    revenue_growth_pct: null,
    orders_growth_pct: null,
}

const EMPTY_REPORT: RevenueReport = {
    from_date: '',
    to_date: '',
    total_revenue: 0,
    total_orders: 0,
    total_invoices: 0,
    avg_order_value: 0,
    cash_revenue: 0,
    transfer_revenue: 0,
    daily: [],
    chart_data: [],
    top_dishes: [],
    by_method: [],
    by_order_type: [],
    order_statuses: [],
}

const roleNames: Record<string, string> = {
    WAITER: 'Phục vụ',
    KITCHEN: 'Bếp',
    CASHIER: 'Thu ngân',
    SHIPPER: 'Giao hàng',
    MANAGER: 'Quản lý',
}

const refreshing = ref(false)

/**
 * Always non-null inside the rendered sections.
 * We narrow via v-if in the template (see <section v-if="dashboardStore.stats">),
 * so this fallback is only used as a TypeScript-friendly stand-in.
 */
const stats = computed<DashboardStats>(() => {
    const s = dashboardStore.stats
    return s ?? EMPTY_STATS
})
const report = computed<RevenueReport>(() => {
    const r = dashboardStore.report
    return r ?? EMPTY_REPORT
})

const todayLabel = computed(() => {
    const d = new Date()
    return d.toLocaleDateString('vi-VN', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    })
})

const reportMeta = computed(() => ({
    from: report.value.from_date,
    to: report.value.to_date,
}))

const tableOccupancyPct = computed(() => {
    const total = stats.value.active_tables + stats.value.available_tables
    return total > 0 ? (stats.value.active_tables / total) * 100 : 0
})

const avgOrderValue = computed(() => {
    if (stats.value.today_orders <= 0) return 0
    return Math.round(stats.value.today_revenue / stats.value.today_orders)
})

const kitchenBottleneck = computed(() => {
    const pending = stats.value.preparing_items + (stats.value.pending_items ?? 0)
    return pending > stats.value.ready_items && pending > 4
})

const rolesSummary = computed(() => {
    const counts: Record<string, number> = {}
    dashboardStore.staffUsers.forEach((u) => {
        u.roles.forEach((r) => {
            counts[r] = (counts[r] || 0) + 1
        })
    })
    const order = ['WAITER', 'KITCHEN', 'CASHIER', 'SHIPPER', 'MANAGER']
    return order
        .filter((code) => counts[code] !== undefined)
        .map((code) => ({ code, label: roleNames[code], count: counts[code] }))
})

const menuAvailable = computed(() => dashboardStore.menuItems.filter((m) => m.is_available))
const menuUnavailable = computed(() => dashboardStore.menuItems.filter((m) => !m.is_available))

const paymentMix = computed(() => {
    const methods = report.value.by_method || []
    return methods
        .filter((m) => m.amount > 0)
        .sort((a, b) => b.amount - a.amount)
        .slice(0, 4)
})

const sparklinePath = computed(() => {
    const data = report.value.chart_data || []
    if (!data.length) return ''
    const values = data.map((d) => d.value)
    const max = Math.max(...values, 1)
    const min = Math.min(...values, 0)
    const range = max - min || 1
    const width = 320
    const height = 80
    const stepX = data.length > 1 ? width / (data.length - 1) : 0
    return data
        .map((point, idx) => {
            const x = idx * stepX
            const y = height - ((point.value - min) / range) * (height - 12) - 6
            return `${idx === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`
        })
        .join(' ')
})

async function refresh(quiet = false) {
    if (refreshing.value) return
    refreshing.value = true
    try {
        await dashboardStore.fetchAll(quiet)
        const today = new Date()
        const date = today.toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
        await dashboardStore.fetchReport(`${date.slice(0, 7)}-01`, date, quiet)
    } finally {
        refreshing.value = false
    }
}

onMounted(refresh)

function trendClass(value: number): string {
    if (value >= 0) return 'mgr-trend--up'
    return 'mgr-trend--down'
}

function trendWord(value: number): string {
    return value >= 0 ? 'Tăng' : 'Giảm'
}

function barHeight(value: number): number {
    const data = report.value.chart_data || []
    if (!data.length) return 0
    const max = Math.max(...data.map((p) => p.value))
    return max === 0 ? 0 : (value / max) * 100
}

function pctOf(amount: number, total: number): number {
    if (!total) return 0
    return Math.min(100, (amount / total) * 100)
}

function shortVND(value: number): string {
    if (value >= 1_000_000_000) return `${(value / 1_000_000_000).toFixed(1)}tỷ`
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}tr`
    if (value >= 1_000) return `${Math.round(value / 1_000)}k`
    return value.toLocaleString('vi-VN')
}

function dishShare(sold: number): number {
    const list = report.value.top_dishes?.slice(0, 5) || []
    if (!list.length) return 0
    const max = Math.max(...list.map((d) => d.sold_count))
    return max === 0 ? 0 : (sold / max) * 100
}

function rankClass(idx: number): string {
    if (idx === 0) return 'mgr-dishes__rank--gold'
    if (idx === 1) return 'mgr-dishes__rank--silver'
    if (idx === 2) return 'mgr-dishes__rank--bronze'
    return ''
}

function orderTypeLabel(code: string): string {
    const map: Record<string, string> = {
        DINE_IN: 'Tại bàn',
        TAKEAWAY: 'Mang đi',
        ONLINE: 'Giao hàng',
    }
    return map[code] || code
}

function orderTypeKey(code: string): string {
    const map: Record<string, string> = {
        DINE_IN: 'dine',
        TAKEAWAY: 'take',
        ONLINE: 'online',
    }
    return map[code] || 'other'
}

function paymentLabel(code: string): string {
    const map: Record<string, string> = {
        CASH: 'Tiền mặt',
        BANK_TRANSFER: 'Chuyển khoản',
        QR: 'QR / ví điện tử',
        ONLINE_GATEWAY: 'Thanh toán online',
        OTHER: 'Khác',
    }
    return map[code] || code
}

function formatVNTime(d: Date): string {
    return formatTime(d.toISOString())
}

/**
 * Stable color for an avatar based on user name.
 */
function avatarStyle(name: string): Record<string, string> {
    const palette: string[] = ['#D71920', '#A80F17', '#FFB000', '#245440', '#5A3825', '#3B5BAB', '#1F8A4C']
    const fallback = palette[0] ?? '#D71920'
    if (!name) return { backgroundColor: fallback }
    let hash = 0
    for (let i = 0; i < name.length; i++) hash = (hash << 5) - hash + name.charCodeAt(i)
    const color = palette[Math.abs(hash) % palette.length] ?? fallback
    return { backgroundColor: color }
}
useRealtimeRefresh(() => refresh(true), () => !refreshing.value)
</script>

<style scoped>
/* ================================================================
   Korean Spicy Dashboard
   - Cream surface · charcoal text · chili-red accent
   - Information dense, but grouped and breathable
   - Group "ops first, analytics second", then "bottom-list"
   ================================================================ */
.mgr-dashboard {
    display: flex;
    flex-direction: column;
    gap: 28px;
    color: var(--mgr-charcoal-black, #111111);
}

/* ============== HEADER ============== */
.mgr-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    flex-wrap: wrap;
}
.mgr-header__intro { max-width: 720px; }
.mgr-header__title {
    font-size: clamp(24px, 3vw, 32px);
    line-height: 1.2;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 6px 0 8px;
    color: var(--mgr-charcoal-black, #111111);
    text-transform: capitalize;
}
.mgr-header__sub {
    font-size: 14px;
    color: var(--mgr-gray-500, #6b7280);
    line-height: 1.6;
    margin: 0;
}
.mgr-header__sub strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }
.mgr-eyebrow {
    color: var(--mgr-chili-red, #d71920);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.mgr-eyebrow::before {
    content: '';
    width: 18px;
    height: 2px;
    background: var(--mgr-chili-red, #d71920);
}
.mgr-header__actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

/* ============== BUTTONS ============== */
.mgr-btn {
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
    transition: background-color 150ms cubic-bezier(0.25, 0.8, 0.25, 1),
                color 150ms cubic-bezier(0.25, 0.8, 0.25, 1),
                transform 150ms cubic-bezier(0.25, 0.8, 0.25, 1),
                box-shadow 150ms cubic-bezier(0.25, 0.8, 0.25, 1);
}
.mgr-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.mgr-btn--primary {
    background: var(--mgr-chili-red, #d71920);
    color: #fff;
}
.mgr-btn--primary:hover { background: var(--mgr-dark-red, #a80f17); transform: translateY(-1px); }
.mgr-btn--ghost {
    background: var(--mgr-white, #fff);
    border-color: var(--mgr-gray-200, #e5e7eb);
    color: var(--mgr-charcoal-black, #111111);
}
.mgr-btn--ghost:hover {
    border-color: var(--mgr-chili-red, #d71920);
    color: var(--mgr-chili-red, #d71920);
}
.mgr-btn__icon--spin { animation: mgr-spin 0.9s linear infinite; }
@keyframes mgr-spin { to { transform: rotate(360deg); } }

/* ============== STATES ============== */
.mgr-state {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 22px;
    border-radius: 14px;
    font-size: 14px;
}
.mgr-state--loading {
    background: var(--mgr-cream-dark, #fff5e6);
    color: var(--mgr-gray-600, #4b5563);
}
.mgr-state--error {
    background: #fee2e2;
    color: #991b1b;
    justify-content: space-between;
}
.mgr-state--error p { margin: 4px 0 0; font-size: 13px; opacity: 0.85; }
.mgr-state__icon {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #991b1b; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
}
.mgr-spinner {
    width: 18px; height: 18px;
    border-radius: 50%;
    border: 2px solid var(--mgr-gray-300, #d1d5db);
    border-top-color: var(--mgr-chili-red, #d71920);
    animation: mgr-spin 0.8s linear infinite;
}

/* ============== HERO REVENUE TILE ============== */
.mgr-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 24px;
    align-items: center;
    background: linear-gradient(135deg, #111111 0%, #1b1b1b 100%);
    border-radius: 18px;
    padding: 26px 30px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.mgr-hero::after {
    content: '';
    position: absolute;
    right: -40px; top: -40px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(215, 25, 32, 0.25), transparent 70%);
    pointer-events: none;
}
.mgr-hero__main { position: relative; z-index: 1; }
.mgr-hero__label {
    font-size: 12px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 700;
}
.mgr-hero__value {
    display: flex;
    align-items: baseline;
    gap: 14px;
    margin: 10px 0 6px;
    flex-wrap: wrap;
}
.mgr-hero__amount {
    font-size: clamp(32px, 4.5vw, 44px);
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.mgr-hero__hint {
    font-size: 13px;
    margin: 0;
    color: rgba(255, 255, 255, 0.65);
}
.mgr-hero__hint span {
    color: var(--mgr-golden, #ffb000);
    font-weight: 700;
}
.mgr-hero__spark {
    color: var(--mgr-chili-red, #d71920);
    position: relative;
    z-index: 1;
    min-width: 0;
}
.mgr-hero__trend {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.mgr-trend--up {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
}
.mgr-trend--down {
    background: rgba(239, 68, 68, 0.18);
    color: #fca5a5;
}

/* ============== SECTIONS ============== */
.mgr-section-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 16px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.mgr-section-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    letter-spacing: -0.3px;
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}
.mgr-section-meta {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    font-variant-numeric: tabular-nums;
}
.mgr-pulse {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--mgr-chili-red, #d71920);
    box-shadow: 0 0 0 0 rgba(215, 25, 32, 0.6);
    animation: mgr-pulse 1.6s ease-out infinite;
}
@keyframes mgr-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(215, 25, 32, 0.55); }
    70%  { box-shadow: 0 0 0 10px rgba(215, 25, 32, 0); }
    100% { box-shadow: 0 0 0 0 rgba(215, 25, 32, 0); }
}

/* ============== OPS GRID ============== */
.mgr-ops__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}
.mgr-tile {
    background: var(--mgr-white, #fff);
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: border-color 200ms ease;
}
.mgr-tile:hover { border-color: var(--mgr-gray-300, #d1d5db); }
.mgr-tile__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.mgr-tile__title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--mgr-gray-600, #4b5563);
    margin: 0;
}
.mgr-tile__link {
    font-size: 12px;
    font-weight: 700;
    color: var(--mgr-chili-red, #d71920);
    text-decoration: none;
}
.mgr-tile__link:hover { color: var(--mgr-dark-red, #a80f17); }
.mgr-tile__big {
    font-size: 40px;
    font-weight: 800;
    line-height: 1;
    color: var(--mgr-charcoal-black, #111111);
    letter-spacing: -1px;
    font-variant-numeric: tabular-nums;
    margin-top: 4px;
}
.mgr-tile__big small {
    font-size: 16px;
    color: var(--mgr-gray-500, #6b7280);
    font-weight: 600;
    letter-spacing: 0;
}
.mgr-tile__sub {
    font-size: 13px;
    color: var(--mgr-gray-500, #6b7280);
    margin: 0;
}
.mgr-tile__sub strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }
.mgr-tile__hint {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    margin: 0;
}
.mgr-tile__hint strong { color: var(--mgr-charcoal-black, #111111); font-weight: 700; }

/* Tables tile */
.mgr-bar {
    height: 8px;
    background: var(--mgr-gray-100, #f3f4f6);
    border-radius: 9999px;
    overflow: hidden;
}
.mgr-bar__fill {
    height: 100%;
    background: linear-gradient(90deg, var(--mgr-chili-red, #d71920), var(--mgr-dark-red, #a80f17));
    transition: width 400ms cubic-bezier(0.25, 0.8, 0.25, 1);
}
.mgr-tile__legend {
    display: flex;
    gap: 14px;
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    margin-top: 2px;
}
.mgr-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
}
.mgr-dot--busy { background: var(--mgr-chili-red, #d71920); }
.mgr-dot--free { background: var(--mgr-gray-300, #d1d5db); }

/* Kitchen pipeline */
.mgr-pipeline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding: 6px 0;
}
.mgr-pipeline__step { flex: 1; text-align: center; }
.mgr-pipeline__num {
    font-size: 28px;
    font-weight: 800;
    line-height: 1;
    color: var(--mgr-charcoal-black, #111111);
    font-variant-numeric: tabular-nums;
}
.mgr-pipeline__num--hot { color: var(--mgr-chili-red, #d71920); }
.mgr-pipeline__num--ok { color: var(--mgr-fresh-green, #22c55e); }
.mgr-pipeline__label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--mgr-gray-500, #6b7280);
    margin-top: 4px;
}
.mgr-pipeline__arrow {
    color: var(--mgr-gray-300, #d1d5db);
    font-size: 16px;
    font-weight: 800;
}
.mgr-pipewarn {
    font-size: 12px;
    margin: 4px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.mgr-pipewarn--warn { color: #b45309; }
.mgr-pipewarn--ok { color: #15803d; }

/* Roster tile */
.mgr-roster {
    list-style: none;
    margin: 4px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.mgr-roster__row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: var(--mgr-gray-700, #374151);
}
.mgr-roster__name { color: var(--mgr-charcoal-black, #111111); }
.mgr-roster__count {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--mgr-chili-red, #d71920);
}

/* ============== ANALYTICS ============== */
.mgr-analytics__grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr);
    gap: 16px;
}
.mgr-chart {
    background: var(--mgr-white, #fff);
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 20px 22px;
}
.mgr-chart__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
}
.mgr-chart__title {
    font-size: 14px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}
.mgr-chart__sub {
    font-size: 13px;
    color: var(--mgr-gray-500, #6b7280);
    margin: 4px 0 0;
}
.mgr-chart__sub strong {
    color: var(--mgr-charcoal-black, #111111);
    font-weight: 700;
}
.mgr-chart__empty {
    text-align: center;
    padding: 40px 0;
    color: var(--mgr-gray-500, #6b7280);
    font-size: 13px;
}
.mgr-chart__legend {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
}
.mgr-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.mgr-legend-swatch {
    width: 14px; height: 6px;
    border-radius: 2px;
    display: inline-block;
}
.mgr-legend-swatch--chili { background: var(--mgr-chili-red, #d71920); }

.mgr-bars {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    height: 180px;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 4px;
    scrollbar-width: thin;
    scrollbar-color: var(--mgr-gray-300, #d1d5db) transparent;
}
.mgr-bars__col {
    flex: 1 1 16px;
    min-width: 22px;
    max-width: 48px;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 4px;
    position: relative;
}
.mgr-bars__val {
    font-size: 10px;
    color: var(--mgr-gray-500, #6b7280);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.mgr-bars__bar {
    width: 100%;
    background: linear-gradient(180deg, var(--mgr-chili-red, #d71920), #f48a8e);
    border-radius: 4px 4px 0 0;
    min-height: 4px;
    transition: height 400ms cubic-bezier(0.25, 0.8, 0.25, 1);
}
.mgr-bars__bar:hover { background: var(--mgr-dark-red, #a80f17); }
.mgr-bars__lbl {
    font-size: 10px;
    color: var(--mgr-gray-500, #6b7280);
    font-weight: 600;
}

/* ============== MIX (payment/order-type breakdown) ============== */
.mgr-mix {
    background: var(--mgr-white, #fff);
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 20px 22px;
}
.mgr-mix__title {
    font-size: 14px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    margin: 0 0 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.mgr-mix__group {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--mgr-gray-500, #6b7280);
    margin: 0 0 10px;
    font-weight: 700;
}
.mgr-mix__group--mt { margin-top: 20px; }
.mgr-mix__list, .mgr-mix__mini {
    list-style: none;
    margin: 0;
    padding: 0;
}
.mgr-mix__list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.mgr-mix__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.mgr-mix__chip {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.mgr-mix__chip--dine { background: #fff5e6; color: #b45309; }
.mgr-mix__chip--take { background: #fef3c7; color: #92400e; }
.mgr-mix__chip--online { background: #ede9fe; color: #6d28d9; }
.mgr-mix__chip--other { background: var(--mgr-gray-100, #f3f4f6); color: var(--mgr-gray-700, #374151); }
.mgr-mix__val {
    font-size: 14px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    font-variant-numeric: tabular-nums;
}
.mgr-mix__bar {
    height: 6px;
    background: var(--mgr-gray-100, #f3f4f6);
    border-radius: 3px;
    overflow: hidden;
}
.mgr-mix__fill {
    height: 100%;
    border-radius: 3px;
    transition: width 400ms ease;
}
.mgr-mix__fill--dine { background: var(--mgr-golden, #ffb000); }
.mgr-mix__fill--take { background: #f59e0b; }
.mgr-mix__fill--online { background: #8b5cf6; }
.mgr-mix__fill--other { background: var(--mgr-gray-500, #6b7280); }
.mgr-mix__meta {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    margin-top: 4px;
}
.mgr-mix__meta strong { color: var(--mgr-charcoal-black, #111111); }
.mgr-mix__empty {
    text-align: center;
    padding: 16px;
    color: var(--mgr-gray-500, #6b7280);
    font-size: 13px;
}

.mgr-mix__mini {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px;
}
.mgr-mix__mini li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px;
    background: var(--mgr-cream-dark, #fff5e6);
    border-radius: 8px;
    font-size: 12px;
}
.mgr-mix__pay {
    color: var(--mgr-charcoal-black, #111111);
    font-weight: 600;
}
.mgr-mix__pay-val {
    font-weight: 700;
    color: var(--mgr-chili-red, #d71920);
    font-variant-numeric: tabular-nums;
}

/* ============== BOTTOM CARDS ============== */
.mgr-bottom {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}
.mgr-card {
    background: var(--mgr-white, #fff);
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.mgr-card__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}
.mgr-card__title {
    font-size: 14px;
    font-weight: 800;
    color: var(--mgr-charcoal-black, #111111);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}
.mgr-card__link {
    font-size: 12px;
    font-weight: 700;
    color: var(--mgr-chili-red, #d71920);
    text-decoration: none;
}
.mgr-card__link:hover { color: var(--mgr-dark-red, #a80f17); }
.mgr-card__hint {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    margin: 0;
}
.mgr-card__hint--warn { color: #b45309; }
.mgr-card__hint--warn::before { content: '⚠ '; }
.mgr-card__hint--ok { color: #15803d; }
.mgr-card__hint--ok::before { content: '✓ '; }
.mgr-mini-empty {
    text-align: center;
    padding: 24px 12px;
    color: var(--mgr-gray-500, #6b7280);
    font-size: 13px;
    border: 1px dashed var(--mgr-gray-200, #e5e7eb);
    border-radius: 10px;
}

/* Top dishes */
.mgr-dishes {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.mgr-dishes__item {
    display: grid;
    grid-template-columns: 32px 1fr;
    align-items: center;
    gap: 10px;
}
.mgr-dishes__rank {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    background: var(--mgr-gray-100, #f3f4f6);
    color: var(--mgr-gray-600, #4b5563);
    font-size: 14px;
}
.mgr-dishes__rank--gold {
    background: linear-gradient(135deg, #fde68a, #f59e0b);
    color: #7c2d12;
}
.mgr-dishes__rank--silver {
    background: linear-gradient(135deg, #f1f5f9, #94a3b8);
    color: #1e293b;
}
.mgr-dishes__rank--bronze {
    background: linear-gradient(135deg, #fed7aa, #ea580c);
    color: #7c2d12;
}
.mgr-dishes__main { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.mgr-dishes__name {
    font-size: 14px;
    font-weight: 700;
    color: var(--mgr-charcoal-black, #111111);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mgr-dishes__meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.mgr-pill {
    display: inline-block;
    padding: 2px 8px;
    background: var(--mgr-cream-dark, #fff5e6);
    color: #b45309;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
}
.mgr-dishes__rev {
    font-size: 12px;
    color: var(--mgr-chili-red, #d71920);
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.mgr-dishes__bar {
    grid-column: 1 / 3;
    height: 4px;
    background: var(--mgr-gray-100, #f3f4f6);
    border-radius: 2px;
    overflow: hidden;
}
.mgr-dishes__fill {
    height: 100%;
    background: linear-gradient(90deg, var(--mgr-chili-red, #d71920), var(--mgr-golden, #ffb000));
    border-radius: 2px;
}

/* Availability */
.mgr-avail {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.mgr-avail__stat {
    text-align: center;
    padding: 16px 8px;
    border-radius: 12px;
    background: var(--mgr-gray-50, #f9fafb);
}
.mgr-avail__stat--ok { background: #dcfce7; }
.mgr-avail__stat--off { background: #fee2e2; }
.mgr-avail__num {
    display: block;
    font-size: 28px;
    font-weight: 800;
    line-height: 1;
    color: var(--mgr-charcoal-black, #111111);
}
.mgr-avail__stat--ok .mgr-avail__num { color: #166534; }
.mgr-avail__stat--off .mgr-avail__num { color: #991b1b; }
.mgr-avail__lab {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--mgr-gray-600, #4b5563);
    display: block;
    margin-top: 4px;
}
.mgr-offlist {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.mgr-offlist li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px dashed var(--mgr-gray-200, #e5e7eb);
    font-size: 13px;
}
.mgr-offlist li:last-child { border-bottom: none; }
.mgr-offlist__name {
    color: var(--mgr-charcoal-black, #111111);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 60%;
}
.mgr-offlist__cat {
    font-size: 11px;
    color: var(--mgr-gray-500, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* People */
.mgr-people {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.mgr-people__item {
    display: grid;
    grid-template-columns: 36px 1fr auto;
    align-items: center;
    gap: 10px;
}
.mgr-people__item--off { opacity: 0.55; }
.mgr-people__avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 800;
    font-size: 13px;
}
.mgr-people__name {
    font-size: 13px;
    font-weight: 700;
    color: var(--mgr-charcoal-black, #111111);
    line-height: 1.3;
}
.mgr-people__role {
    font-size: 11px;
    color: var(--mgr-gray-500, #6b7280);
    line-height: 1.4;
}
.mgr-people__pill {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 9999px;
}
.mgr-people__pill--on { background: #dcfce7; color: #166534; }
.mgr-people__pill--off { background: var(--mgr-gray-100, #f3f4f6); color: var(--mgr-gray-600, #4b5563); }

/* ============== FOOTNOTE ============== */
.mgr-footnote {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    line-height: 1.6;
    margin: 0;
    border-top: 1px dashed var(--mgr-gray-200, #e5e7eb);
    padding-top: 16px;
}
.mgr-footnote strong { color: var(--mgr-charcoal-black, #111111); }

/* ============== RESPONSIVE ============== */
@media (max-width: 960px) {
    .mgr-hero { grid-template-columns: 1fr; }
    .mgr-hero__spark { display: none; }
    .mgr-analytics__grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .mgr-header { flex-direction: column; align-items: stretch; }
    .mgr-header__actions { justify-content: stretch; }
    .mgr-header__actions .mgr-btn { flex: 1; justify-content: center; }
    .mgr-ops__grid { grid-template-columns: 1fr; }
    .mgr-bottom { grid-template-columns: 1fr; }
    .mgr-hero { padding: 22px; }
}
</style>
