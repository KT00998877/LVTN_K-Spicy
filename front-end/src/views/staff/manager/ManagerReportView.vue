<template>
    <div class="manager-report">
        <header class="manager-heading"><div><span class="eyebrow">PHÂN TÍCH KINH DOANH</span><h2>Báo cáo doanh thu</h2><p>Theo dõi doanh thu và món bán chạy theo khoảng thời gian.</p></div></header>
        <div class="toolbar">
            <div class="period-tabs">
                <button
                    v-for="p in periods"
                    :key="p.value"
                    class="period-tab" :disabled="loading"
                    :class="{ active: period === p.value }"
                    @click="setPeriod(p.value)"
                >
                    {{ p.label }}
                </button>
            </div>
            <input v-model="fromDate" aria-label="Từ ngày" type="date" :disabled="loading" :max="vnDate(new Date())" @change="period = 'custom'" class="date-input" />
            <span>→</span>
            <input v-model="toDate" aria-label="Đến ngày" type="date" class="date-input" />
            <button class="btn-apply" :disabled="loading" @click="applyFilter">Áp dụng</button>
        </div>

        <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p><p v-if="loading" class="manager-notice" role="status">Đang tải báo cáo…</p>
        <div v-if="report && !loading && !error" class="report-content">
            <div class="toolbar"><button class="btn-apply" @click="printReport">In / Lưu PDF</button><button class="btn-apply" @click="exportCsv">Xuất CSV</button><span>Số liệu đã tải: {{ report.from_date }} – {{ report.to_date }}</span></div>
            <div class="chart-card">
                <h3 class="chart-title">Diễn biến doanh thu</h3>
                <p v-if="!report.chart_data?.length" class="manager-notice">Chưa có dữ liệu trong khoảng thời gian này.</p><div v-else class="chart">
                    <div v-for="point in report.chart_data" :key="point.label" class="chart-bar">
                        <div class="bar-value">{{ formatVND(point.value) }}</div>
                        <div class="bar-fill" :style="{ height: barHeight(point.value) + '%' }"></div>
                        <div class="bar-label">{{ point.label }}</div>
                    </div>
                </div>
            </div>

            <RevenueReportSheet :report="report" />
        </div>

        <!-- EXPORT — Xuất báo cáo Excel/PDF (MANAGER full quyền) -->
        <section class="export-section">
            <ExportPanel :default-type="'revenue'" />
        </section>

        <Teleport to="body"><div v-if="printing && report" class="manager-report-print"><RevenueReportSheet :report="report" /></div></Teleport>
    </div>
</template>

<script setup lang="ts">
import { beginRead } from '@/utils/realtime'
const liveReads = {}
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { onMounted, ref, nextTick } from 'vue'
import RevenueReportSheet from '@/components/staff/RevenueReportSheet.vue'
import ExportPanel from '@/components/report/ExportPanel.vue'
import { useDashboardStore } from '@/stores/staffDashboard'
import { useToast, formatVND } from '@/composables/useStaffUtils'
import { staffDashboard, type RevenueReport } from '@/api/staff'

const dashboardStore = useDashboardStore()
const toast = useToast()

const report = ref<RevenueReport | null>(null)
const period = ref<'today' | 'day' | 'month' | 'custom'>('month')
const printing = ref(false)

const loading = ref(false)
const error = ref('')
const vnDate = (d: Date) => d.toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
const today = new Date()
const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)

const fromDate = ref(vnDate(today).slice(0, 7) + '-01')
const toDate = ref(vnDate(today))

const periods: Array<{ value: 'today' | 'day' | 'month'; label: string }> = [
    { value: 'today', label: 'Hôm nay' },
    { value: 'month', label: 'Tháng này' },
    { value: 'day',   label: '7 ngày qua' },
]

onMounted(() => loadReport())

async function setPeriod(p: 'today' | 'day' | 'month'): Promise<void> {
    period.value = p
    const now = new Date()
    if (p === 'today') { fromDate.value = vnDate(now); toDate.value = vnDate(now) }
    else if (p === 'month') {
        fromDate.value = vnDate(now).slice(0, 7) + '-01'
        toDate.value = vnDate(now)
    } else {
        const start = new Date(now)
        start.setDate(start.getDate() - 6)
        fromDate.value = vnDate(start)
        toDate.value = vnDate(now)
    }
    await loadReport()
}

async function applyFilter(): Promise<void> {
    period.value = 'custom'
    await loadReport()
}

let appliedDates: { from: string; to: string } | null = null
async function loadReport(quiet = false): Promise<void> {
    if (loading.value) return
    if (!quiet) error.value = ''
    if (!quiet && (!fromDate.value || !toDate.value || fromDate.value > toDate.value)) { error.value = 'Vui lòng chọn khoảng ngày hợp lệ.'; return }
    if (!quiet) loading.value = true
    const fresh = beginRead(liveReads, 'list', quiet); try {
        if (!quiet) appliedDates = { from: fromDate.value, to: toDate.value }
        if (appliedDates) { const result = await staffDashboard.revenue(appliedDates); if (fresh()) report.value = result }
    } catch (err: any) { if (quiet) throw err;
        error.value = err?.response?.data?.message || 'Không tải được báo cáo. Vui lòng thử áp dụng lại bộ lọc.'
    } finally { if (!quiet) loading.value = false }
}

function barHeight(value: number): number {
    if (!report.value || !report.value.chart_data || report.value.chart_data.length === 0) return 0
    const max = Math.max(...report.value.chart_data.map(p => p.value))
    return max === 0 ? 0 : (value / max) * 75
}

function trendWidth(sold: number): number {
    if (!report.value || !report.value.top_dishes || report.value.top_dishes.length === 0) return 0
    const max = Math.max(...report.value.top_dishes.map(d => d.sold_count))
    return max === 0 ? 0 : (sold / max) * 100
}
async function printReport() { printing.value = true; await nextTick(); try { window.print() } finally { printing.value = false } }
function exportCsv() {
    const r = report.value; if (!r) return
    const rows: (string | number)[][] = [
        ['Báo cáo thống kê', r.from_date, r.to_date], ['Giờ Việt Nam', r.generated_at || ''],
        ['Tổng thu', r.total_revenue], ['Hóa đơn có thu', r.total_invoices], ['Giao dịch thu', r.payment_count || 0],
        ['Kỳ trước', r.previous_from || '', r.previous_to || '', r.previous_revenue || 0],
        [], ['Phương thức', 'Giao dịch', 'Số tiền'],
        ...(r.by_method || []).map(d => [d.code, d.count, d.amount]),
        [], ['Hình thức', 'Hóa đơn', 'Số tiền'], ...(r.by_order_type || []).map(d => [d.code, d.count, d.amount]),
        [], ['Trạng thái đơn tạo trong kỳ', 'Số đơn'], ...(r.order_statuses || []).map(d => [d.status, d.count]),
        [], ['Ngày', 'Hóa đơn có thu', 'Số tiền'], ...r.daily.map(d => [d.date, d.count, d.revenue]),
        [], ['Món bán chạy (đơn hoàn tất)', 'Số phần', 'Giá trị trước giảm giá/thuế'], ...(r.top_dishes || []).map(d => [d.name, d.sold_count, d.revenue]),
    ]
    const cell = (v: string | number) => {
        let value = String(v); if (typeof v === 'string' && /^[=+@\\-\\t\\r\\n]/.test(value)) value = "'" + value
        return '"' + value.replace(/"/g, '""') + '"'
    }
    const url = URL.createObjectURL(new Blob(['\uFEFF' + rows.map(row => row.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' }))
    const a = document.createElement('a'); a.href = url; a.download = 'bao-cao-' + r.from_date + '-' + r.to_date + '.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
useRealtimeRefresh(() => loadReport(true), () => !loading.value && !printing.value)
</script>

<style scoped>
.manager-report { display: flex; flex-direction: column; gap: 16px; }

.toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    background: #fff;
    padding: 12px 16px;
    border-radius: 10px;
}

.period-tabs {
    display: flex;
    gap: 4px;
    background: #f3f4f6;
    padding: 4px;
    border-radius: 8px;
}

.period-tab {
    padding: 7px 12px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    color: #6b7280;
}

.period-tab.active { background: #7c3aed; color: #fff; }

.date-input {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
}

.btn-apply {
    background: #7c3aed;
    color: #fff;
    border: none;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
}

.report-content { display: flex; flex-direction: column; gap: 14px; }

.kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.kpi-card {
    background: #fff;
    padding: 18px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.kpi-card.primary {
    background: linear-gradient(135deg, var(--color-brand-primary), var(--color-brand-dark));
    color: #fff;
}

.kpi-label { font-size: 12px; color: #6b7280; }
.kpi-card.primary .kpi-label { color: rgba(255, 255, 255, 0.85); }
.kpi-value { font-size: 22px; font-weight: 700; margin-top: 4px; }

.chart-card,
.rank-card {
    background: #fff;
    padding: 18px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.chart-title {
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 16px;
}

.chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    height: 220px;
    gap: 8px;
}

.chart-bar {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
    justify-content: flex-end;
}

.bar-value {
    font-size: 10px;
    color: #6b7280;
    margin-bottom: 4px;
    white-space: nowrap;
}

.bar-fill {
    width: 100%;
    background: linear-gradient(180deg, #7c3aed, #6d28d9);
    border-radius: 6px 6px 0 0;
    min-height: 8px;
    transition: height 0.4s ease;
}

.bar-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 6px;
    font-weight: 600;
}

.rank-table {
    width: 100%;
    border-collapse: collapse;
}

.rank-table th,
.rank-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13px;
}

.rank-table th {
    background: #f9fafb;
    color: #6b7280;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
}

.rank-num { font-weight: 700; color: #7c3aed; }
.text-right { text-align: right; }

.trend-bar {
    background: linear-gradient(90deg, #7c3aed, #6d28d9);
    height: 8px;
    border-radius: 4px;
    transition: width 0.4s ease;
}

.loading {
    text-align: center;
    padding: 60px;
    color: #9ca3af;
}
</style>
<style>
.manager-report-print { display: none; }
@media print { body:has(> .manager-report-print)>* { display: none !important; }body>.manager-report-print { display: block !important; } }
</style>
