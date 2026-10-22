<template>
    <div class="shift-page">
        <header class="page-heading"><div><h2>Báo cáo doanh thu ngày</h2><p>Đối soát tiền thu, kiểm đếm két và lưu báo cáo bàn giao cuối ngày.</p></div><button @click="newReport" :disabled="busy">Lập báo cáo mới</button></header>
        <form class="shift-form" @submit.prevent="preview">
            <h3>Thông tin báo cáo · Giờ Việt Nam</h3>
            <div class="fields">
                <label>Ngày báo cáo<input v-model="form.date" type="date" required :max="vnTime(new Date()).slice(0, 10)" :disabled="busy" /></label>
                <label>Tiền mặt đầu ngày<input v-model.number="form.opening_cash" type="number" min="0" step="1000" required :disabled="busy" /></label>
                <label>Nhận thêm / phục vụ bàn giao<input v-model.number="form.cash_in" type="number" min="0" step="1000" required :disabled="busy" /></label>
                <label>Chi / nộp ra khỏi két<input v-model.number="form.cash_out" type="number" min="0" step="1000" required :disabled="busy" /></label>
                <label>Tiền mặt kiểm đếm cuối ngày<input v-model.number="form.actual_cash" type="number" min="0" step="1000" required :disabled="busy" /></label>
            </div>
            <label>Ghi chú bàn giao<textarea v-model="form.note" rows="3" maxlength="2000" :disabled="busy" placeholder="Người bàn giao tiền, khoản chi, lý do thừa/thiếu…" /></label>
            <div class="form-actions"><button type="submit" class="primary" :disabled="busy">{{ busy ? 'Đang xử lý…' : 'Xem báo cáo' }}</button><small>Tính từ 00:00 đến 24:00 theo giờ Việt Nam. Ngày hôm nay tính đến thời điểm xem hoặc chốt.</small></div>
        </form>
        <p v-if="error" role="alert" class="error">{{ error }}</p>
        <template v-if="report">
            <div class="report-actions">
                <strong>{{ report.id ? 'Báo cáo đã chốt · ' + report.code : 'Báo cáo xem trước' }}</strong>
                <button v-if="!report.id" :disabled="busy || changed" class="primary" @click="closeShift">Chốt báo cáo ngày & lưu báo cáo</button>
                <button :disabled="busy || (!report.id && changed)" @click="printReport">In / Lưu PDF</button>
            </div>
            <p v-if="!report.id && changed" class="error">Thông tin báo cáo đã thay đổi. Bấm “Xem báo cáo” để cập nhật trước khi chốt.</p>
            <p v-if="report.has_new_data" class="error" role="alert">Có giao dịch mới hoặc thay đổi sau khi chốt. Số liệu đã lưu được giữ nguyên; vui lòng báo quản lý để đối soát bổ sung.</p><ShiftReportSheet :report="report" />
        </template>
        <section class="history"><h3>Lịch sử chốt báo cáo của bạn</h3><p v-if="historyError" role="alert">{{ historyError }} <button @click="loadHistory()">Thử lại</button></p>
            <div class="history-table"><table><thead><tr><th>Mã báo cáo</th><th>Khoảng giờ</th><th>Tổng thu toàn nhà hàng</th><th>Chênh lệch két</th><th></th></tr></thead><tbody><tr v-for="r in history" :key="r.id"><td>{{ r.code }}</td><td>{{ date(r.from) }} → {{ date(r.to) }}</td><td>{{ money(r.total_collected) }}</td><td>{{ money(r.difference) }}</td><td><button :disabled="busy" @click="openHistory(r.id!)">Xem / In</button></td></tr></tbody></table></div>
            <p v-if="!history.length && !historyError">Chưa có báo cáo đã chốt.</p>
            <div class="pagination"><button :disabled="page <= 1 || busy" @click="loadHistory(page - 1)">Trước</button><span>Trang {{ page }} / {{ lastPage }}</span><button :disabled="page >= lastPage || busy" @click="loadHistory(page + 1)">Sau</button></div>
        </section>

        <!-- EXPORT — Xuất báo cáo Excel/PDF (CASHIER: revenue, orders, shift) -->
        <section class="export-section" style="margin-top: 24px;">
            <ExportPanel :default-type="'shift'" :allowed-types="cashierAllowed" />
        </section>

        <Teleport to="body"><div v-if="printing && report" class="shift-print"><ShiftReportSheet :report="report" /></div></Teleport>
    </div>
</template>
<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import apiClient from '@/api/client'
import ShiftReportSheet from '@/components/staff/ShiftReportSheet.vue'
import ExportPanel from '@/components/report/ExportPanel.vue'
import type { ReportTypeOption } from '@/types/report'
import type { ShiftReport } from '@/types/shiftReport'

const cashierAllowed: ReportTypeOption[] = [
    {
        code: 'shift',
        label: 'Ca làm việc của tôi',
        description: 'Lịch sử các ca bán hàng đã chốt',
        allowedRoles: ['CASHIER', 'MANAGER'],
        needsDateRange: true,
    },
    {
        code: 'revenue',
        label: 'Doanh thu',
        description: 'Doanh thu theo ngày, phương thức thanh toán và loại đơn',
        allowedRoles: ['CASHIER', 'MANAGER'],
        needsDateRange: true,
    },
    {
        code: 'orders',
        label: 'Đơn hàng',
        description: 'Danh sách đơn hàng trong khoảng thời gian',
        allowedRoles: ['CASHIER', 'MANAGER'],
        needsDateRange: true,
    },
]
function vnTime(d: Date) {
    const parts = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d)
    return parts.replace(' ', 'T')
}
const form = reactive({ date: vnTime(new Date()).slice(0, 10), opening_cash: 0, cash_in: 0, cash_out: 0, actual_cash: 0, note: '' })
const report = ref<ShiftReport | null>(null)
const history = ref<Partial<ShiftReport>[]>([])
const page = ref(1), lastPage = ref(1)
const busy = ref(false), printing = ref(false)
const error = ref(''), historyError = ref(''), previewKey = ref('')
const changed = computed(() => previewKey.value !== JSON.stringify(form))
const money = (n = 0) => n.toLocaleString('vi-VN') + ' đ'
const date = (s?: string) => s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : ''
async function run(fn: () => Promise<void>) {
    if (busy.value) return
    busy.value = true; error.value = ''
    try { await fn() } catch (e: any) { error.value = e?.response?.data?.message || 'Không thể xử lý báo cáo. Vui lòng thử lại.' }
    finally { busy.value = false }
}
async function preview() {
    await run(async () => {
        const key = JSON.stringify(form)
        report.value = (await apiClient.post<{ data: ShiftReport }>('/staff/cashier-reports/preview', { ...form })).data.data
        previewKey.value = key
    })
}
async function closeShift() {
    if (changed.value || report.value?.id) return
    if (!window.confirm('Chốt báo cáo ngày và lưu báo cáo? Báo cáo đã chốt sẽ không chỉnh sửa được.')) return
    await run(async () => {
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
    await run(async () => { report.value = (await apiClient.get<{ data: ShiftReport }>('/staff/cashier-reports/' + id)).data.data })
}
function newReport() { report.value = null; error.value = ''; form.date = vnTime(new Date()).slice(0, 10); previewKey.value = '' }
async function printReport() {
    printing.value = true
    await nextTick()
    window.print()
    printing.value = false
}
onMounted(() => { void loadHistory() })
useRealtimeRefresh(() => loadHistory(page.value), () => !busy.value && !printing.value)
</script>
<style scoped>
.shift-page { font-family: var(--font-ui); display: flex; flex-direction: column; gap: 20px; color: #1e293b; }.page-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; }.page-heading h2 { margin: 0; font-size: 22px; }.page-heading p { color: #64748b; font-size: 13px; }.shift-form, .history { padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; }h3 { margin: 0 0 18px; font-size: 16px; }.fields { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 18px; }label { display: flex; flex-direction: column; gap: 8px; font-size: 13px; color: #475569; }input, textarea { border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; font: inherit; min-width: 0; }button { border: 1px solid #cbd5e1; border-radius: 6px; background: white; padding: 10px 14px; color: #334155; cursor: pointer; font: inherit; font-size: 12px; }.primary { background: var(--color-brand-action); color: white; border-color: var(--color-brand-action); }button:disabled { opacity: .5; cursor: not-allowed; }.form-actions, .report-actions, .pagination { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 15px; }.form-actions small { color: #64748b; }.report-actions strong { margin-right: auto; }.error { color: #b91c1c; font-size: 13px; }.history-table { overflow-x: auto; }table { width: 100%; border-collapse: collapse; font-size: 12px; }th, td { text-align: left; padding: 12px; border-bottom: 1px solid #e2e8f0; }th { background: #f8fafc; }.pagination { justify-content: flex-end; font-size: 12px; }@media (max-width: 850px) { .fields { grid-template-columns: 1fr 1fr; } }@media (max-width: 550px) { .fields { grid-template-columns: 1fr; }.page-heading { flex-wrap: wrap; }.shift-form { padding: 16px; } }
</style>
<style>
.shift-print { display: none; }
@media print {
    body:has(> .shift-print) > * { display: none !important; }
    body > .shift-print { display: block !important; background: white; }
}
</style>
