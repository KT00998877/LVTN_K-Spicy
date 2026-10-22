<template>
    <div class="sales-page">
        <header class="manager-heading">
            <div>
                <span class="eyebrow">ĐƠN HÀNG & HÓA ĐƠN</span>
                <h2>{{ invoiceMode ? 'Quản lý hóa đơn' : 'Quản lý đơn hàng' }}</h2>
                <p>Tra cứu tại bàn, mang đi và trực tuyến. Thời gian hiển thị theo giờ Việt Nam.</p>
            </div>
            <RouterLink :to="invoiceMode ? '/staff/manager/orders' : '/staff/manager/invoices'" class="manager-button">
                {{ invoiceMode ? 'Xem đơn hàng' : 'Xem hóa đơn' }}
            </RouterLink>
        </header>

        <form class="filters" @submit.prevent="load(1)">
            <label>Tìm kiếm
                <input v-model="filter.search" placeholder="Mã đơn, hóa đơn, bàn, khách, phục vụ…" maxlength="150" />
            </label>
            <label>Trạng thái
                <select v-model="filter.status">
                    <option value="">Tất cả trạng thái</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ label(s) }}</option>
                </select>
            </label>
            <label>Hình thức
                <select v-model="filter.order_type">
                    <option value="">Tất cả hình thức</option>
                    <option value="DINE_IN">Tại bàn</option>
                    <option value="TAKEAWAY">Mang đi</option>
                    <option value="ONLINE">Trực tuyến</option>
                </select>
            </label>
            <label>Từ ngày<input v-model="filter.from" type="date" /></label>
            <label>Đến ngày<input v-model="filter.to" type="date" /></label>
            <button class="manager-button primary" :disabled="loading">Tra cứu / Làm mới</button>
        </form>

        <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
        <p v-if="loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>
        <template v-else-if="!error">
            <div class="summary">
                <div><span>Kết quả theo bộ lọc</span><strong>{{ summary.count }}</strong></div>
                <div><span>{{ invoiceMode ? 'Tổng giá trị hóa đơn' : 'Tổng giá trị đơn' }}</span><strong>{{ money(summary.total) }}</strong></div>
                <div v-if="invoiceMode"><span>Đã thu trên các hóa đơn</span><strong>{{ money(summary.paid || 0) }}</strong></div>
            </div>
            <p class="help">Số liệu tổng hợp theo ngày tạo và bộ lọc đã áp dụng; bao gồm các trạng thái đang chọn.</p>

            <div v-if="rows.length" class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Mã / thời gian</th>
                            <th>Bàn / hình thức</th>
                            <th>Khách / phục vụ</th>
                            <th>Tổng tiền</th>
                            <th v-if="invoiceMode">Đã thu</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id">
                            <td>
                                <strong>{{ r.code }}</strong>
                                <small>{{ date(r.occurred_at) }}</small>
                                <small v-if="invoiceMode">{{ r.order_code }}</small>
                            </td>
                            <td>{{ r.table_code ? 'Bàn ' + r.table_code : kinds[r.order_type] }}</td>
                            <td>{{ r.customer_name || 'Khách lẻ' }}<small>{{ r.waiter_name || '—' }}</small></td>
                            <td>{{ money(r.total_amount) }}</td>
                            <td v-if="invoiceMode">{{ money(r.paid_amount || 0) }}</td>
                            <td>
                                <span class="tag">{{ label(r.status) }}</span>
                                <small v-if="invoiceMode">{{ r.invoice_exported_at ? 'Đã xuất' : 'Chưa xuất' }}</small>
                            </td>
                            <td>
                                <button class="manager-button" :disabled="busy" @click="view(r.id, invoiceMode)">Xem chi tiết</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="manager-notice">Không có dữ liệu phù hợp với bộ lọc.</p>

            <div v-if="lastPage > 1" class="pagination">
                <button :disabled="page <= 1" @click="load(page - 1)">Trước</button>
                <span>Trang {{ page }} / {{ lastPage }}</span>
                <button :disabled="page >= lastPage" @click="load(page + 1)">Sau</button>
            </div>

            <section v-if="!invoiceMode" class="export-section" style="margin-top: 20px;">
                <ExportPanel :default-type="'orders'" :show-order-filters="true" />
            </section>
        </template>

        <!-- Dialog chi tiết -->
        <dialog ref="dialog" @cancel="cancel">
            <header class="detail-controls">
                <strong>{{ detailInvoice ? 'Chi tiết hóa đơn' : 'Chi tiết đơn hàng' }}</strong>
                <span v-if="selected" class="detail-status">
                    Trạng thái hiện tại:
                    <b :class="['status-chip', 'status-' + (selected.status || '').toLowerCase()]">{{ label(selected.status) }}</b>
                </span>
                <button :disabled="busy" @click="dialog?.close()">Đóng</button>
                <button v-if="detailInvoice && selected?.status === 'PAID'" class="manager-button primary" :disabled="busy" @click="printInvoice">Xuất / In / Lưu PDF</button>
            </header>

            <p v-if="detailError" class="manager-notice error" role="alert">{{ detailError }}</p>

            <template v-if="selected">
                <div class="related">
                    <template v-if="!detailInvoice">
                        <span>Hóa đơn liên quan:</span>
                        <button v-for="i in selected.invoices" :key="i.id" class="manager-button" :disabled="busy" @click="view(i.id, true)">
                            {{ i.code }} · {{ label(i.status) }}
                        </button>
                        <span v-if="!selected.invoices?.length">Chưa lập hóa đơn</span>
                    </template>
                    <button v-else-if="selected.order_id" class="manager-button" :disabled="busy" @click="view(selected.order_id, false)">Xem đơn hàng gốc</button>
                </div>

                <!-- =============== CONTROL BAR — Cập nhật trạng thái + Giảm giá =============== -->
                <div class="action-bar">
                    <!-- Cập nhật trạng thái đơn hàng -->
                    <div v-if="!detailInvoice && orderNextStates.length" class="action-group">
                        <label class="action-label">
                            <span>Cập nhật trạng thái đơn</span>
                            <select v-model="statusForm.next" :disabled="busy">
                                <option value="">— Chọn trạng thái —</option>
                                <option v-for="s in orderNextStates" :key="s" :value="s">{{ label(s) }}</option>
                            </select>
                        </label>
                        <label class="action-label">
                            <span>Lý do</span>
                            <input v-model="statusForm.reason" type="text" placeholder="Lý do cập nhật…" maxlength="500" :disabled="busy || !statusForm.next" />
                        </label>
                        <button class="manager-button primary" :disabled="!statusForm.next || !statusForm.reason.trim() || busy" @click="submitOrderStatus">Áp dụng</button>
                    </div>

                    <!-- Cập nhật trạng thái hóa đơn -->
                    <div v-if="detailInvoice && invoiceNextStates.length" class="action-group">
                        <label class="action-label">
                            <span>Cập nhật trạng thái hóa đơn</span>
                            <select v-model="statusForm.next" :disabled="busy">
                                <option value="">— Chọn trạng thái —</option>
                                <option v-for="s in invoiceNextStates" :key="s" :value="s">{{ label(s) }}</option>
                            </select>
                        </label>
                        <label class="action-label">
                            <span>Lý do</span>
                            <input v-model="statusForm.reason" type="text" placeholder="Lý do cập nhật…" maxlength="500" :disabled="busy || !statusForm.next" />
                        </label>
                        <button class="manager-button primary" :disabled="!statusForm.next || !statusForm.reason.trim() || busy" @click="submitInvoiceStatus">Áp dụng</button>
                    </div>

                    <!-- Giảm giá thủ công cho hóa đơn (MANAGER) -->
                    <div v-if="detailInvoice && canManage && invoiceDiscountEditable" class="action-group action-group--discount">
                        <div class="action-group__title">Giảm giá thủ công</div>
                        <label class="action-label">
                            <span>Loại</span>
                            <select v-model="discountForm.type" :disabled="busy">
                                <option value="amount">Số tiền (đ)</option>
                                <option value="percent">Phần trăm (%)</option>
                            </select>
                        </label>
                        <label class="action-label">
                            <span>Giá trị</span>
                            <input
                                v-model.number="discountForm.value"
                                type="number"
                                min="0"
                                :step="discountForm.type === 'percent' ? 1 : 1000"
                                placeholder="0"
                                :disabled="busy"
                            />
                        </label>
                        <label class="action-label action-label--wide">
                            <span>Lý do</span>
                            <input v-model="discountForm.reason" type="text" placeholder="Lý do giảm giá…" maxlength="500" :disabled="busy || !discountForm.value" />
                        </label>
                        <div class="action-preview">
                            Tạm tính <b>{{ money(selected.subtotal) }}</b> · Giảm hiện tại <b>{{ money(selected.discount) }}</b>
                            <template v-if="discountForm.value && Number(discountForm.value) >= 0">
                                → Giảm mới <b>{{ money(estimatedDiscount) }}</b> · Tổng mới <b>{{ money(estimatedTotal) }}</b>
                            </template>
                        </div>
                        <button class="manager-button primary" :disabled="!canSubmitDiscount || busy" @click="submitDiscount">Áp dụng giảm giá</button>
                    </div>
                </div>

                <ManagerSalesSheet :data="selected" :invoice="detailInvoice" />

                <!-- Lịch sử điều chỉnh (chỉ hóa đơn) -->
                <section v-if="detailInvoice && selectedAdjustments.length" class="adjustments">
                    <h3>Lịch sử điều chỉnh</h3>
                    <ul>
                        <li v-for="a in selectedAdjustments" :key="a.id">
                            <span class="adj-field">{{ a.field === 'status' ? 'Trạng thái' : 'Giảm giá' }}</span>
                            <span class="adj-change">{{ a.old || '—' }} → {{ a.new }}</span>
                            <span class="adj-meta">{{ a.reason }} · {{ a.adjusted_by || 'Hệ thống' }} · {{ date(a.adjusted_at) }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </dialog>

        <Teleport to="body">
            <div v-if="printing && selected" class="manager-sales-print">
                <ManagerSalesSheet :data="selected" :invoice="true" />
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { beginRead } from '@/utils/realtime'
const liveReads = {} as Record<string, unknown>
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/api/client'
import ManagerSalesSheet, { type SalesDetail } from '@/components/staff/ManagerSalesSheet.vue'
import ExportPanel from '@/components/report/ExportPanel.vue'
import { useStaffStore } from '@/stores/staff'
import { useToast } from '@/composables/useStaffUtils'

interface Row {
    id: number
    code: string
    occurred_at: string
    order_code: string
    order_type: string
    table_code: string | null
    customer_name: string | null
    waiter_name: string | null
    status: string
    total_amount: number
    paid_amount?: number
    invoice_exported_at?: string
}

interface InvoiceAdjustment {
    id: number
    field: string
    old: string | null
    new: string | null
    reason: string
    adjusted_by: string | null
    adjusted_at: string | null
}

const route = useRoute()
const staff = useStaffStore()
const toast = useToast()

const invoiceMode = computed(() => route.path.endsWith('/invoices'))
const kinds: Record<string, string> = { DINE_IN: 'Tại bàn', TAKEAWAY: 'Mang đi', ONLINE: 'Trực tuyến' }

const STATUS_LABEL: Record<string, string> = {
    PENDING: 'Chờ xử lý',
    CONFIRMED: 'Đã xác nhận',
    PREPARING: 'Đang nấu',
    READY: 'Chờ bưng',
    SERVED: 'Đã phục vụ',
    COMPLETED: 'Hoàn tất',
    CANCELLED: 'Đã hủy',
    PAID: 'Đã thanh toán',
    UNPAID: 'Chưa thanh toán',
    PARTIAL: 'Thanh toán một phần',
    REFUNDED: 'Đã hoàn tiền',
    FAILED: 'Thất bại',
    SUCCESS: 'Thành công',
}
const label = (s: string) => STATUS_LABEL[s] || s

const statuses = computed(() =>
    invoiceMode.value
        ? ['UNPAID', 'PENDING', 'PARTIAL', 'PAID', 'FAILED', 'REFUNDED']
        : ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'SERVED', 'COMPLETED', 'CANCELLED']
)

const filter = reactive({ search: '', status: '', order_type: '', from: '', to: '' })
let applied = { ...filter }

const rows = ref<Row[]>([])
const summary = ref({ count: 0, total: 0, paid: 0 })
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const busy = ref(false)
const detailError = ref('')
const selected = ref<SalesDetail | null>(null)
const detailInvoice = ref(false)
const dialog = ref<HTMLDialogElement>()
const printing = ref(false)

// Forms cho action bar
const statusForm = reactive({ next: '', reason: '' })
const discountForm = reactive({ type: 'amount' as 'amount' | 'percent', value: 0, reason: '' })

const money = (n: number) => Number(n).toLocaleString('vi-VN') + ' đ'
const date = (s: string | null | undefined) =>
    s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'

const userRoles = computed(() => staff.session?.roles ?? [])
const canManage = computed(() => userRoles.value.includes('MANAGER'))

// ===== State machine cho phép chuyển =====
const ORDER_TRANSITIONS: Record<string, string[]> = {
    PENDING: ['CONFIRMED', 'CANCELLED'],
    CONFIRMED: ['PREPARING', 'CANCELLED'],
    PREPARING: ['READY', 'CANCELLED'],
    READY: ['SERVED', 'CANCELLED'],
    SERVED: ['COMPLETED'],
}
const ONLINE_TRANSITIONS: Record<string, string[]> = {
    CONFIRMED: ['PREPARING', 'CANCELLED'],
    PREPARING: ['READY', 'CANCELLED'],
}
const INVOICE_TRANSITIONS: Record<string, string[]> = {
    UNPAID: ['PENDING', 'PAID'],
    PENDING: ['UNPAID', 'PAID'],
    PAID: ['REFUNDED', 'FAILED'],
    FAILED: ['UNPAID'],
}

const orderNextStates = computed<string[]>(() => {
    if (!selected.value || detailInvoice.value) return []
    const status = selected.value.status
    const orderType = selected.value.order_type
    if (['COMPLETED', 'CANCELLED'].includes(status)) return []
    if (orderType === 'ONLINE' || orderType === 'TAKEAWAY') {
        return ONLINE_TRANSITIONS[status] ?? []
    }
    return ORDER_TRANSITIONS[status] ?? []
})

const invoiceNextStates = computed<string[]>(() => {
    if (!selected.value || !detailInvoice.value) return []
    return INVOICE_TRANSITIONS[selected.value.status] ?? []
})

const invoiceDiscountEditable = computed(() => {
    if (!selected.value || !detailInvoice.value) return false
    return ['UNPAID', 'PENDING', 'PARTIAL'].includes(selected.value.status)
})

const selectedAdjustments = computed<InvoiceAdjustment[]>(() => {
    if (!detailInvoice.value || !selected.value) return []
    const adj = (selected.value as SalesDetail & { adjustments?: InvoiceAdjustment[] }).adjustments
    return adj ?? []
})

const estimatedDiscount = computed(() => {
    if (!selected.value || !discountForm.value || Number(discountForm.value) < 0) return selected.value?.discount ?? 0
    if (discountForm.type === 'percent') {
        return Math.round(((selected.value.subtotal || 0) * Number(discountForm.value) / 100) * 100) / 100
    }
    return Math.round(Number(discountForm.value) * 100) / 100
})

const estimatedTotal = computed(() => {
    if (!selected.value) return 0
    return Math.max(0, (selected.value.subtotal || 0) - estimatedDiscount.value)
})

const canSubmitDiscount = computed(() => {
    if (!selected.value) return false
    if (!discountForm.value || Number(discountForm.value) < 0) return false
    if (!discountForm.reason.trim()) return false
    if (estimatedDiscount.value > (selected.value.subtotal || 0)) return false
    return true
})

let requestId = 0
let detailId = 0

async function load(p = 1, quiet = false) {
    if (p === 1 && !quiet) applied = { ...filter }
    const id = ++requestId
    if (!quiet) loading.value = true
    if (!quiet) error.value = ''
    const fresh = beginRead(liveReads, 'list', quiet)
    try {
        const r = await api.get('/staff/dashboard/' + (invoiceMode.value ? 'invoices' : 'orders'), {
            params: { ...applied, page: p },
        })
        if (!fresh() || id !== requestId) return
        error.value = ''
        rows.value = r.data.data
        summary.value = r.data.summary
        page.value = r.data.current_page
        lastPage.value = r.data.last_page
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        if (quiet) throw e
        if (id === requestId) error.value = err?.response?.data?.message || 'Không tải được dữ liệu. Vui lòng thử lại.'
    } finally {
        if (id === requestId) if (!quiet) loading.value = false
    }
}

async function view(id: number, invoice: boolean) {
    if (busy.value) return
    const token = ++detailId
    busy.value = true
    detailError.value = ''
    selected.value = null
    // reset forms
    statusForm.next = ''
    statusForm.reason = ''
    discountForm.type = 'amount'
    discountForm.value = 0
    discountForm.reason = ''
    if (!dialog.value?.open) dialog.value?.showModal()
    try {
        const r = await api.get<{ data: SalesDetail }>(
            (invoice ? '/staff/invoices/' : '/staff/dashboard/orders/') + id
        )
        if (token !== detailId) return
        selected.value = r.data.data
        detailInvoice.value = invoice
    } catch {
        if (token === detailId) detailError.value = 'Không tải được chi tiết. Đóng và thử lại.'
    } finally {
        if (token === detailId) busy.value = false
    }
}

async function refreshSelected() {
    if (!selected.value) return
    const id = selected.value.id
    const invoice = detailInvoice.value
    const revision = detailId
    try {
        const r = await api.get<{ data: SalesDetail }>(
            (invoice ? '/staff/invoices/' : '/staff/dashboard/orders/') + id
        )
        if (revision === detailId && selected.value?.id === id) {
            selected.value = r.data.data
        }
    } catch {
        /* ignore */
    }
}

async function submitOrderStatus() {
    if (!selected.value || !statusForm.next || !statusForm.reason.trim()) return
    busy.value = true
    detailError.value = ''
    try {
        const r = await api.patch<{ data: SalesDetail }>(
            `/staff/orders/${selected.value.id}/status`,
            { status: statusForm.next, reason: statusForm.reason.trim() }
        )
        selected.value = r.data.data
        statusForm.next = ''
        statusForm.reason = ''
        toast.success(`Đã cập nhật đơn sang "${label(r.data.data.status)}".`)
        void load(page.value)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
        detailError.value =
            err?.response?.data?.message ||
            (err?.response?.data?.errors && Object.values(err.response.data.errors).flat()[0]) ||
            'Không cập nhật được trạng thái đơn.'
    } finally {
        busy.value = false
    }
}

async function submitInvoiceStatus() {
    if (!selected.value || !statusForm.next || !statusForm.reason.trim()) return
    busy.value = true
    detailError.value = ''
    try {
        const r = await api.patch<{ data: SalesDetail }>(
            `/staff/invoices/${selected.value.id}/status`,
            { status: statusForm.next, reason: statusForm.reason.trim() }
        )
        selected.value = r.data.data
        statusForm.next = ''
        statusForm.reason = ''
        toast.success(`Đã cập nhật hóa đơn sang "${label(r.data.data.status)}".`)
        void load(page.value)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
        detailError.value =
            err?.response?.data?.message ||
            (err?.response?.data?.errors && Object.values(err.response.data.errors).flat()[0]) ||
            'Không cập nhật được trạng thái hóa đơn.'
    } finally {
        busy.value = false
    }
}

async function submitDiscount() {
    if (!selected.value || !canSubmitDiscount.value) return
    busy.value = true
    detailError.value = ''
    try {
        const r = await api.patch<{ data: SalesDetail }>(
            `/staff/invoices/${selected.value.id}/discount`,
            {
                type: discountForm.type,
                value: Number(discountForm.value),
                reason: discountForm.reason.trim(),
            }
        )
        selected.value = r.data.data
        discountForm.value = 0
        discountForm.reason = ''
        toast.success(`Đã cập nhật giảm giá cho hóa đơn ${r.data.data.code}.`)
        void load(page.value)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
        detailError.value =
            err?.response?.data?.message ||
            (err?.response?.data?.errors && Object.values(err.response.data.errors).flat()[0]) ||
            'Không cập nhật được giảm giá.'
    } finally {
        busy.value = false
    }
}

async function printInvoice() {
    if (!selected.value?.order_id || busy.value) return
    busy.value = true
    detailError.value = ''
    try {
        selected.value = (await api.post<{ data: SalesDetail }>('/staff/invoices/export-order/' + selected.value.order_id)).data.data
        dialog.value?.close()
        printing.value = true
        await nextTick()
        window.print()
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        detailError.value = err?.response?.data?.message || 'Không xuất được hóa đơn.'
    } finally {
        printing.value = false
        await nextTick()
        dialog.value?.showModal()
        busy.value = false
        void load(page.value)
    }
}

function cancel(e: Event) {
    if (busy.value) e.preventDefault()
}

watch(
    () => route.path,
    () => {
        detailId++
        busy.value = false
        dialog.value?.close()
        Object.assign(filter, { search: '', status: '', order_type: '', from: '', to: '' })
        void load(1)
    }
)

onMounted(() => load())
onUnmounted(() => {
    requestId++
    detailId++
})

useRealtimeRefresh(() => load(page.value, true), () => !loading.value && !busy.value && !printing.value)

useRealtimeRefresh(async () => {
    await refreshSelected()
}, () => !!dialog.value?.open && !!selected.value && !busy.value && !printing.value)
</script>

<style scoped>
.sales-page {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.filters {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    padding: 20px;
    background: white;
    border: 1px solid var(--color-border);
    border-radius: 12px;
}

.filters label {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
}

.filters label:first-child {
    flex: 1;
    min-width: 220px;
}

input,
select {
    padding: 10px;
    border: 1px solid var(--color-border-strong);
    border-radius: 7px;
    font: inherit;
}

.summary {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

.summary > div {
    flex: 1;
    min-width: 200px;
    padding: 22px;
    border: 1px solid var(--color-border);
    border-radius: 12px;
    background: white;
}

.summary span {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 12px;
}

.summary strong {
    font-size: 25px;
    color: #245440;
}

.table-wrap {
    overflow-x: auto;
    background: white;
    border: 1px solid var(--color-border);
    border-radius: 12px;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
    font-size: 13px;
}

th,
td {
    padding: 15px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}

th {
    color: #64748b;
    background: var(--color-brand-soft);
}

small {
    display: block;
    color: #64748b;
    margin-top: 5px;
}

.tag {
    font-size: 12px;
}

.help {
    color: #64748b;
    font-size: 12px;
}

.pagination {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    font-size: 13px;
}

.primary {
    background: var(--color-brand-action);
    color: white;
}

dialog {
    width: min(900px, calc(100% - 32px));
    max-height: 90vh;
    padding: 0;
    border: 0;
    border-radius: 14px;
    font: 14px Arial, sans-serif;
}

dialog::backdrop {
    background: color-mix(in srgb, var(--color-brand-dark) 75%, transparent);
}

.detail-controls,
.related {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
}

.detail-controls > strong {
    margin-right: auto;
}

.detail-status {
    font-size: 12px;
    color: #475569;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.status-chip {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    background: #f1f5f9;
    color: #1e293b;
}

.status-chip.status-pending { background: #fef3c7; color: #92400e; }
.status-chip.status-confirmed { background: #dbeafe; color: #1e40af; }
.status-chip.status-preparing { background: #fde68a; color: #92400e; }
.status-chip.status-ready { background: #e0e7ff; color: #3730a3; }
.status-chip.status-served { background: #d1fae5; color: #065f46; }
.status-chip.status-completed { background: #d1fae5; color: #065f46; }
.status-chip.status-cancelled { background: #fee2e2; color: #991b1b; }
.status-chip.status-unpaid { background: #fee2e2; color: #991b1b; }
.status-chip.status-paid { background: #d1fae5; color: #065f46; }
.status-chip.status-partial { background: #fde68a; color: #92400e; }
.status-chip.status-refunded { background: #ede9fe; color: #5b21b6; }
.status-chip.status-failed { background: #fee2e2; color: #991b1b; }

.related {
    font-size: 12px;
}

button {
    cursor: pointer;
}

button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== Action bar ===== */
.action-bar {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    background: var(--color-brand-soft);
}

.action-group {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    padding: 14px;
    background: white;
    border: 1px solid var(--color-border);
    border-radius: 10px;
}

.action-group--discount {
    border-color: #fecaca;
    background: #fff7f7;
}

.action-group__title {
    flex: 1 0 100%;
    font-weight: 700;
    font-size: 13px;
    color: #b91c1c;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.action-label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 11px;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    min-width: 140px;
}

.action-label--wide {
    flex: 1 1 240px;
}

.action-label > span {
    font-weight: 600;
    color: #1f2937;
}

.action-preview {
    flex: 1 0 100%;
    margin-top: 4px;
    font-size: 12px;
    color: #475569;
    background: var(--color-brand-soft);
    border: 1px dashed var(--color-border-strong);
    border-radius: 8px;
    padding: 8px 12px;
}

.action-preview b {
    color: #b91c1c;
    font-weight: 700;
}

/* ===== Adjustment history ===== */
.adjustments {
    padding: 16px 24px 24px;
    border-top: 1px solid #e2e8f0;
}

.adjustments h3 {
    margin: 0 0 12px;
    font-size: 14px;
    color: #1f2937;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.adjustments ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.adjustments li {
    display: grid;
    grid-template-columns: 110px 1fr;
    grid-template-rows: auto auto;
    gap: 4px 12px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    font-size: 13px;
}

.adj-field {
    grid-row: span 2;
    align-self: center;
    font-weight: 700;
    color: #245440;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.3px;
}

.adj-change {
    color: #1f2937;
    font-weight: 600;
}

.adj-meta {
    color: #64748b;
    font-size: 12px;
}

@media (max-width: 640px) {
    .adjustments li {
        grid-template-columns: 1fr;
    }
    .adj-field {
        grid-row: auto;
    }
}
</style>

<style>
.manager-sales-print {
    display: none;
}

@media print {
    body:has(> .manager-sales-print) > * {
        display: none !important;
    }
    body > .manager-sales-print {
        display: block !important;
    }
}
</style>
