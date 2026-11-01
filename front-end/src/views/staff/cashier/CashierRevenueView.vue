<template>
    <div class="shift-page">
        <header class="page-heading">
            <div><h2>Đối soát cuối ngày</h2><p>Xem tiền hệ thống ghi nhận, kiểm đếm két rồi chốt bàn giao.</p></div>
            <button type="button" :disabled="busy" @click="newReport">Lập đối soát mới</button>
        </header>

        <section class="panel" aria-labelledby="step-one">
            <div class="step-heading"><span class="step-number">1</span><div><h3 id="step-one">Kiểm tra giao dịch đã thu</h3><p>Chọn ngày cần đối soát. Số liệu tính theo giờ Việt Nam, đến thời điểm xem.</p></div><button type="button" :disabled="overviewLoading || busy" @click="loadOverview">Làm mới giao dịch</button></div>
            <label class="date-field">Ngày đối soát<input v-model="form.date" type="date" required :max="vnTime(new Date()).slice(0, 10)" :disabled="busy" /></label>
            <p v-if="overviewLoading" role="status">Đang tải giao dịch…</p>
            <p v-else-if="overviewError" class="error" role="alert">{{ overviewError }} <button type="button" @click="loadOverview">Thử lại</button></p>
            <template v-else-if="overview">
                <div class="summary-grid">
                    <div><span>Hóa đơn đã thanh toán</span><strong>{{ overview.invoice_count }}</strong></div>
                    <div><span>Tổng thu cả nhà hàng</span><strong>{{ money(overview.total_collected) }}</strong></div>
                    <div><span>Tiền mặt bạn đã thu</span><strong>{{ money(overview.cashier_cash) }}</strong></div>
                </div>
                <p class="help">Chỉ “Tiền mặt bạn đã thu” được tự động cộng vào két của bạn. Các khoản thu của nhân viên khác chỉ cộng khi bạn thực nhận và nhập ở bước 2.</p>
                <div class="method-list"><span v-for="(amount, method) in overview.by_method" :key="method">{{ methodLabel(String(method)) }}: <strong>{{ money(amount) }}</strong></span></div>
                <details class="transactions">
                    <summary>Xem {{ overview.payment_count }} giao dịch thanh toán</summary>
                    <div v-if="overview.payments.length" class="table-scroll"><table><thead><tr><th>Thời gian</th><th>Hóa đơn</th><th>Người thu</th><th>Phương thức</th><th class="amount">Số tiền</th></tr></thead><tbody><tr v-for="payment in overview.payments" :key="payment.id"><td>{{ date(payment.processed_at) }}</td><td>{{ payment.invoice_code }}</td><td>{{ payment.collector || '—' }}</td><td>{{ methodLabel(payment.method) }}</td><td class="amount">{{ money(payment.amount) }}</td></tr></tbody></table></div>
                    <p v-else>Ngày này chưa có giao dịch thanh toán.</p>
                </details>
            </template>
        </section>

        <form class="panel" @submit.prevent="preview" aria-labelledby="step-two">
            <div class="step-heading"><span class="step-number">2</span><div><h3 id="step-two">Kiểm đếm tiền mặt</h3><p>Nhập số tiền thực tế trong két và các khoản nhận, chi trong ngày.</p></div></div>
            <div class="fields">
                <label>Tiền mặt đầu ngày<input v-model.number="form.opening_cash" type="number" min="0" step="1" required :disabled="busy" /><small>Số tiền có sẵn trong két khi bắt đầu ngày.</small></label>
                <label>Tiền nhận thêm<input v-model.number="form.cash_in" type="number" min="0" step="1" required :disabled="busy" /><small>Tiền nhân viên khác bàn giao hoặc được bổ sung vào két.</small></label>
                <label>Tiền đã chi / nộp ra<input v-model.number="form.cash_out" type="number" min="0" step="1" required :disabled="busy" /><small>Tiền đã lấy khỏi két; ghi lý do ở phần ghi chú.</small></label>
                <label>Tiền mặt kiểm đếm cuối ngày<input v-model.number="form.actual_cash" type="number" min="0" step="1" required :disabled="busy" placeholder="Nhập số tiền đếm được" /><small>Đếm tiền đang có trong két, không lấy số từ hệ thống.</small></label>
            </div>
            <label>Ghi chú bàn giao<textarea v-model="form.note" rows="3" maxlength="2000" :disabled="busy" placeholder="Người bàn giao tiền, khoản chi, lý do thừa hoặc thiếu…" /><small v-if="needsNote">Bắt buộc trước khi chốt nếu có tiền nhận thêm, tiền chi ra hoặc chênh lệch.</small></label>
            <div class="form-actions"><button type="submit" class="primary" :disabled="busy || overviewLoading || !!overviewError">{{ busy ? 'Đang xử lý…' : 'Tính chênh lệch' }}</button></div>
            <p v-if="error" role="alert" class="error">{{ error }}</p>
        </form>

        <section ref="resultSection" class="panel" aria-labelledby="step-three">
            <div class="step-heading"><span class="step-number">3</span><div><h3 id="step-three">Xem chênh lệch và chốt</h3><p>Kiểm tra phép tính và ghi chú trước khi lưu báo cáo bàn giao.</p></div></div>
            <p v-if="error" role="alert" class="error">{{ error }}</p>
            <template v-if="report">
                <p v-if="report.id"><strong>Báo cáo đã chốt: {{ report.code }}</strong></p>
                <template v-else>
                    <div class="reconcile"><div><span>Đầu ngày + bạn thu + nhận thêm − chi ra</span><strong>{{ money(report.expected_cash) }}</strong><small>Tiền dự kiến trong két</small></div><div><span>Tiền thực đếm</span><strong>{{ money(report.actual_cash) }}</strong></div><div><span>Thực đếm − dự kiến</span><strong>{{ money(report.difference) }}</strong><small>{{ report.difference === 0 ? 'Khớp' : report.difference > 0 ? 'Thừa tiền' : 'Thiếu tiền' }}</small></div></div>
                    <p v-if="changed" class="error" role="alert">Số liệu đã thay đổi. Bấm “Tính chênh lệch” để cập nhật trước khi chốt.</p>
                    <p v-else-if="needsNote && !form.note.trim()" class="error" role="alert">Hãy ghi lý do chênh lệch hoặc các khoản nhận, chi trước khi chốt.</p>
                </template>
                <p v-if="report.has_new_data" class="error" role="alert">Có giao dịch mới hoặc thay đổi sau khi chốt. Số liệu đã lưu được giữ nguyên; báo quản lý để đối soát bổ sung.</p>
                <div class="report-actions"><button v-if="!report.id" type="button" class="primary" :disabled="busy || changed || (needsNote && !form.note.trim())" @click="closeShift">Chốt và lưu bàn giao</button><button type="button" :disabled="busy || (!report.id && changed)" @click="printReport">In / Lưu PDF</button></div>
                <details class="report-detail"><summary>Xem biên bản chi tiết</summary><ShiftReportSheet :report="report" /></details>
            </template>
            <p v-else class="help">Nhập tiền kiểm đếm ở bước 2 rồi bấm “Tính chênh lệch” để xem kết quả tại đây.</p>
        </section>

        <section class="panel history"><h3>Lịch sử bàn giao của bạn</h3><p v-if="historyError" role="alert">{{ historyError }} <button type="button" @click="loadHistory()">Thử lại</button></p>
            <div v-if="history.length" class="table-scroll"><table><thead><tr><th>Mã báo cáo</th><th>Khoảng giờ</th><th class="amount">Tổng thu cả nhà hàng</th><th class="amount">Chênh lệch két</th><th></th></tr></thead><tbody><tr v-for="r in history" :key="r.id"><td>{{ r.code }}</td><td>{{ date(r.from) }} → {{ date(r.to) }}</td><td class="amount">{{ money(r.total_collected) }}</td><td class="amount">{{ money(r.difference) }}</td><td><button type="button" :disabled="busy" @click="openHistory(r.id!)">Xem / In</button></td></tr></tbody></table></div>
            <p v-else-if="!historyError">Chưa có báo cáo đã chốt.</p>
            <div v-if="lastPage > 1" class="pagination"><button type="button" :disabled="page <= 1 || busy" @click="loadHistory(page - 1)">Trước</button><span>Trang {{ page }} / {{ lastPage }}</span><button type="button" :disabled="page >= lastPage || busy" @click="loadHistory(page + 1)">Sau</button></div>
        </section>
        <details class="panel export-section"><summary>Xuất báo cáo doanh thu và đơn hàng</summary><ExportPanel :default-type="'shift'" :allowed-types="cashierAllowed" /></details>
        <Teleport to="body"><div v-if="printing && report" class="shift-print"><ShiftReportSheet :report="report" /></div></Teleport>
    </div>
</template>
<script setup lang="ts">
import { useAppDialog } from '@/composables/useAppDialog'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import apiClient from '@/api/client'
import ShiftReportSheet from '@/components/staff/ShiftReportSheet.vue'
import ExportPanel from '@/components/report/ExportPanel.vue'
import type { ReportTypeOption } from '@/types/report'
import type { ShiftReport } from '@/types/shiftReport'

const cashierAllowed: ReportTypeOption[] = [
    { code: 'shift', label: 'Ca làm việc của tôi', description: 'Lịch sử các ca bán hàng đã chốt', allowedRoles: ['CASHIER', 'MANAGER'], needsDateRange: true },
    { code: 'revenue', label: 'Doanh thu', description: 'Doanh thu theo ngày, phương thức thanh toán và loại đơn', allowedRoles: ['CASHIER', 'MANAGER'], needsDateRange: true },
    { code: 'orders', label: 'Đơn hàng', description: 'Danh sách đơn hàng trong khoảng thời gian', allowedRoles: ['CASHIER', 'MANAGER'], needsDateRange: true },
]
function vnTime(d: Date) {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d).replace(' ', 'T')
}
const form = reactive({ date: vnTime(new Date()).slice(0, 10), opening_cash: 0, cash_in: 0, cash_out: 0, actual_cash: null as number | null, note: '' })
const appDialog = useAppDialog()
const overview = ref<ShiftReport | null>(null), overviewLoading = ref(false), overviewError = ref('')
const report = ref<ShiftReport | null>(null), history = ref<Partial<ShiftReport>[]>([])
const resultSection = ref<HTMLElement | null>(null)
const page = ref(1), lastPage = ref(1)
const busy = ref(false), printing = ref(false)
const error = ref(''), historyError = ref(''), previewKey = ref('')
const changed = computed(() => previewKey.value !== JSON.stringify(form))
const needsNote = computed(() => form.cash_in > 0 || form.cash_out > 0 || (!!report.value && !report.value.id && report.value.difference !== 0))
const money = (n = 0) => Number(n).toLocaleString('vi-VN') + ' đ'
const date = (s?: string) => s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : ''
const methodLabel = (method: string) => ({ CASH: 'Tiền mặt', BANK_TRANSFER: 'Chuyển khoản', QR: 'QR', ONLINE_GATEWAY: 'Cổng thanh toán', OTHER: 'Khác' }[method] || method)
let overviewRequest = 0
async function loadOverview() {
    const request = ++overviewRequest
    overviewLoading.value = true; overviewError.value = ''
    try {
        const res = await apiClient.post<{ data: ShiftReport }>('/staff/cashier-reports/preview', { date: form.date, opening_cash: 0, cash_in: 0, cash_out: 0, actual_cash: 0 })
        if (request === overviewRequest) overview.value = res.data.data
    } catch (e: any) {
        if (request === overviewRequest) { overview.value = null; overviewError.value = e?.response?.data?.message || 'Không tải được giao dịch ngày này.' }
    } finally { if (request === overviewRequest) overviewLoading.value = false }
}
async function run(fn: () => Promise<void>) {
    if (busy.value) return
    busy.value = true; error.value = ''
    try { await fn() } catch (e: any) { error.value = Object.values(e?.response?.data?.errors || {}).flat()[0] as string || e?.response?.data?.message || 'Không thể xử lý báo cáo. Vui lòng thử lại.' }
    finally { busy.value = false }
}
async function preview() {
    if (typeof form.actual_cash !== 'number' || !Number.isFinite(form.actual_cash)) return
    await run(async () => {
        const key = JSON.stringify(form)
        report.value = (await apiClient.post<{ data: ShiftReport }>('/staff/cashier-reports/preview', { ...form })).data.data
        overview.value = report.value
        previewKey.value = key
        await nextTick()
        resultSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
}
async function closeShift() {
    if (changed.value || report.value?.id || !report.value || (needsNote.value && !form.note.trim())) return
    if (!await appDialog.confirm({ title: 'Chốt đối soát cuối ngày?', message: 'Báo cáo đã chốt sẽ được lưu và không thể chỉnh sửa.', confirmLabel: 'Chốt báo cáo', tone: 'warning' })) return
    await run(async () => {
        const latest = (await apiClient.post<{ data: ShiftReport }>('/staff/cashier-reports/preview', { ...form })).data.data
        if (JSON.stringify(latest.payments) !== JSON.stringify(report.value?.payments) || latest.difference !== report.value?.difference) {
            report.value = latest
            overview.value = latest
            previewKey.value = JSON.stringify(form)
            error.value = 'Có giao dịch mới. Hãy kiểm tra lại số liệu rồi bấm chốt lần nữa.'
            return
        }
        report.value = (await apiClient.post<{ data: ShiftReport }>('/staff/cashier-reports/close', { ...form })).data.data
        await loadHistory()
    })
}
async function loadHistory(target = 1) {
    try {
        const res = await apiClient.get<{ data: Partial<ShiftReport>[]; current_page: number; last_page: number }>('/staff/cashier-reports', { params: { page: target } })
        history.value = res.data.data; page.value = res.data.current_page; lastPage.value = res.data.last_page; historyError.value = ''
    } catch { historyError.value = 'Không tải được lịch sử chốt báo cáo.' }
}
async function openHistory(id: number) {
    await run(async () => { report.value = (await apiClient.get<{ data: ShiftReport }>('/staff/cashier-reports/' + id)).data.data; await nextTick(); resultSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }) })
}
function newReport() {
    report.value = null; error.value = ''; previewKey.value = ''
    const today = vnTime(new Date()).slice(0, 10)
    if (form.date === today) void loadOverview()
    form.date = today
    form.opening_cash = 0; form.cash_in = 0; form.cash_out = 0; form.actual_cash = null; form.note = ''
    window.scrollTo({ top: 0, behavior: 'smooth' })
}
async function printReport() {
    printing.value = true
    await nextTick()
    window.print()
    printing.value = false
}
watch(() => form.date, () => { report.value = null; previewKey.value = ''; void loadOverview() })
onMounted(() => { void loadHistory(); void loadOverview() })
</script>
<style scoped>
.shift-page { font-family: var(--font-ui); display: flex; flex-direction: column; gap: 20px; color: var(--color-text); }
.page-heading, .step-heading, .form-actions, .report-actions, .pagination { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.page-heading { justify-content: space-between; }.page-heading h2 { margin: 0; font-size: 24px; }.page-heading p, .step-heading p, .help, small { color: var(--color-text-muted); font-size: 13px; line-height: 1.5; }.page-heading p, .step-heading p { margin: 4px 0 0; }
.panel { padding: 24px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; }.step-heading { align-items: flex-start; margin-bottom: 18px; }.step-heading button { margin-left: auto; }.step-number { display: inline-grid; place-items: center; flex: 0 0 28px; height: 28px; border-radius: 50%; background: var(--color-brand-action); color: #fff; font-weight: 700; }.step-heading h3, .panel > h3 { margin: 0; font-size: 17px; }.panel > h3 { margin-bottom: 16px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; }.date-field { max-width: 220px; }.fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-bottom: 20px; }input, textarea { border: 1px solid var(--color-border-strong); padding: 10px; border-radius: 6px; font: inherit; min-width: 0; color: var(--color-text); background: #fff; }input:focus-visible, textarea:focus-visible, button:focus-visible, summary:focus-visible { outline: 2px solid var(--color-brand-dark); outline-offset: 2px; }
button { border: 1px solid var(--color-border-strong); border-radius: 6px; background: #fff; padding: 10px 14px; color: var(--color-brand-ink); cursor: pointer; font: inherit; font-size: 13px; font-weight: 600; }.primary { background: var(--color-brand-action); color: #fff; border-color: var(--color-brand-action); }button:disabled { opacity: .5; cursor: not-allowed; }.error { color: #991b1b; font-size: 13px; }
.summary-grid, .reconcile { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 18px 0; }.summary-grid > div, .reconcile > div { padding: 14px; border-radius: 8px; background: var(--color-background-alt); display: flex; flex-direction: column; gap: 6px; }.summary-grid span, .reconcile span { font-size: 12px; color: var(--color-text-muted); }.summary-grid strong, .reconcile strong { font-size: 19px; font-variant-numeric: tabular-nums; }.method-list { display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: 13px; margin: 16px 0; }.method-list strong, td.amount { font-variant-numeric: tabular-nums; }.transactions, .report-detail { margin-top: 16px; }summary { cursor: pointer; font-weight: 600; color: var(--color-brand-ink); }.table-scroll { overflow-x: auto; }table { width: 100%; border-collapse: collapse; font-size: 13px; }th, td { text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap; }th { background: var(--color-background-alt); }th.amount, td.amount { text-align: right; }.pagination { justify-content: flex-end; font-size: 13px; }.export-section > :deep(*) { margin-top: 16px; }
@media (max-width: 720px) { .summary-grid, .reconcile { grid-template-columns: 1fr; }.fields { grid-template-columns: 1fr; }.panel { padding: 16px; } }
</style>
<style>
.shift-print { display: none; }
@media print { body:has(> .shift-print) > * { display: none !important; } body > .shift-print { display: block !important; background: white; } }
</style>
