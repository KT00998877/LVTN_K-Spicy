<template>
    <div class="cashier-page">
        <header class="cashier-heading"><div><h2>Hóa đơn & yêu cầu xuất</h2><p>Theo dõi thanh toán và xuất hóa đơn cho khách.</p></div><button :disabled="loading" @click="refresh()">{{ loading ? 'Đang cập nhật…' : 'Làm mới' }}</button></header>
        <div class="summary-grid">
            <div><span>Yêu cầu chưa xuất</span><strong>{{ requests.length }}</strong></div>
            <div><span>Hóa đơn đã thanh toán</span><strong>{{ invoices.filter(i => i.status === 'PAID').length }}</strong></div>
            <div><span>Đã thu hôm nay</span><strong>{{ formatVND(todayRevenue) }}</strong></div>
        </div>
        <div class="toolbar">
            <nav><button :class="{ active: tab === 'requests' }" @click="tab = 'requests'">Yêu cầu xuất ({{ requests.length }})</button><button :class="{ active: tab === 'invoices' }" @click="tab = 'invoices'">Hóa đơn</button></nav>
            <input v-model="search" placeholder="Tìm mã đơn, hóa đơn, bàn…" aria-label="Tìm hóa đơn" />
            <select v-if="tab === 'invoices'" v-model="status" aria-label="Trạng thái hóa đơn"><option value="">Tất cả trạng thái</option><option value="PAID">Đã thanh toán</option><option value="UNPAID">Chưa thanh toán</option><option value="PARTIAL">Thanh toán một phần</option></select>
        </div>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="loading && !invoices.length && !orders.length" class="empty">Đang tải dữ liệu…</p>
        <div v-else class="table-wrap">
            <table v-if="tab === 'requests'">
                <thead><tr><th>Đơn hàng</th><th>Bàn / loại đơn</th><th>Phục vụ</th><th>Yêu cầu lúc</th><th>Tổng tiền</th><th>Thao tác</th></tr></thead>
                <tbody><tr v-for="o in visibleRequests" :key="o.id"><td><strong>{{ o.code }}</strong></td><td>{{ o.table_code ? 'Bàn ' + o.table_code : 'Mang đi / trực tuyến' }}</td><td>{{ o.waiter_name || '—' }}</td><td>{{ formatDateTime(o.invoice_requested_at) }}</td><td>{{ formatVND(o.total_amount) }}</td><td><button :disabled="busy || o.status !== 'COMPLETED'" @click="exportOrder(o.id)">Xuất hóa đơn</button><small v-if="o.status !== 'COMPLETED'">Chờ phục vụ thanh toán</small></td></tr></tbody>
            </table>
            <table v-else>
                <thead><tr><th>Hóa đơn / đơn hàng</th><th>Bàn / khách</th><th>Ngày tạo</th><th>Tổng tiền</th><th>Đã thu</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                <tbody><tr v-for="i in visibleInvoices" :key="i.id"><td><strong>{{ i.code }}</strong><small>{{ i.order_code }}</small></td><td>{{ i.table_code ? 'Bàn ' + i.table_code : i.customer_name || 'Khách lẻ' }}</td><td>{{ formatDateTime(i.issued_at) }}</td><td>{{ formatVND(i.total) }}</td><td>{{ formatVND(i.paid) }}</td><td><span class="badge" :class="{ paid: i.status === 'PAID' }">{{ statusLabel(i.status) }}</span><small>{{ i.invoice_exported_at ? 'Đã xuất' : 'Chưa xuất' }}</small></td><td class="actions"><button :disabled="busy" @click="view(i.id)">Xem</button><button v-if="i.status === 'PAID'" :disabled="busy" @click="exportOrder(i.order_id)">{{ i.invoice_exported_at ? 'In lại' : 'Xuất / In' }}</button><button v-else-if="['UNPAID', 'PARTIAL', 'PENDING'].includes(i.status)" :disabled="busy" @click="openPaymentModal(i)">Thu tiền</button></td></tr></tbody>
            </table>
            <p v-if="!(tab === 'requests' ? visibleRequests.length : visibleInvoices.length)" class="empty">{{ error ? 'Chưa tải được dữ liệu.' : 'Không có dữ liệu phù hợp.' }}</p>
        </div>
        <Teleport to="body">
            <!-- Receipt detail view -->
            <div v-if="selected && !payment" class="payment-overlay" @click.self="selected = null">
                <section class="payment-dialog payment-dialog--receipt">
                    <header class="pay-header">
                        <div class="pay-header-left">
                            <span class="pay-eyebrow">Chi tiết hóa đơn</span>
                            <h2 class="pay-title">{{ selected.code }}</h2>
                        </div>
                        <div class="pay-header-right">
                            <span class="pay-status" :class="`pay-status--${selected.status.toLowerCase()}`">
                                {{ statusLabel(selected.status) }}
                            </span>
                            <button class="pay-close" @click="selected = null" aria-label="Đóng">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </header>

                    <div class="pay-body">
                        <!-- Order identity -->
                        <div class="pay-identity">
                            <div class="pay-identity-item">
                                <span class="pay-identity-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>
                                    Bàn / Hình thức
                                </span>
                                <strong>{{ selected.table_code ? `Bàn ${selected.table_code}` : selected.order_type === 'TAKEAWAY' ? 'Mang đi' : selected.order_type === 'ONLINE' ? 'Trực tuyến' : 'Khách lẻ' }}</strong>
                            </div>
                            <div class="pay-identity-item">
                                <span class="pay-identity-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Thời gian
                                </span>
                                <strong>{{ formatDateTime(selected.issued_at) }}</strong>
                            </div>
                            <div class="pay-identity-item">
                                <span class="pay-identity-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Khách hàng
                                </span>
                                <strong>{{ selected.customer_name || '—' }}</strong>
                            </div>
                            <div class="pay-identity-item">
                                <span class="pay-identity-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    Phục vụ
                                </span>
                                <strong>{{ selected.waiter_name || '—' }}</strong>
                            </div>
                            <div class="pay-identity-item">
                                <span class="pay-identity-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
                                    Mã đơn
                                </span>
                                <strong class="mono">{{ selected.order_code }}</strong>
                            </div>
                        </div>

                        <!-- Items -->
                        <div class="pay-items">
                            <div class="pay-items-header">
                                <span>Món đã gọi</span>
                                <span class="pay-items-count">{{ selected.items.filter(i => i.status !== 'CANCELLED').length }} món</span>
                            </div>
                            <table class="pay-items-table">
                                <thead>
                                    <tr>
                                        <th class="col-name">Tên món</th>
                                        <th class="col-qty">SL</th>
                                        <th class="col-price">Đơn giá</th>
                                        <th class="col-total">Thành tiền</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in selected.items.filter(i => i.status !== 'CANCELLED')" :key="item.id" :class="{ 'item-cancelled': item.status === 'CANCELLED' }">
                                        <td class="col-name">
                                            <span class="item-name">{{ item.menu_item_name }}</span>
                                            <span v-if="item.toppings.length" class="item-toppings">
                                                <span v-for="t in item.toppings" :key="t.id" class="item-topping">+ {{ t.name }}</span>
                                            </span>
                                            <span v-if="item.spicy_level != null" class="item-spicy">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm0 18c-4.4 0-8-3.6-8-8s3.6-8 8-8 8 3.6 8 8-3.6 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                                                Cấp {{ item.spicy_level + 1 }}
                                            </span>
                                        </td>
                                        <td class="col-qty">
                                            <span class="item-qty">{{ item.quantity }}</span>
                                        </td>
                                        <td class="col-price">{{ formatVND(item.unit_price) }}</td>
                                        <td class="col-total">{{ formatVND(item.subtotal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals breakdown -->
                        <div class="pay-totals">
                            <div class="pay-totals-row">
                                <span>Tạm tính</span>
                                <span class="pay-totals-val">{{ formatVND(selected.subtotal) }}</span>
                            </div>
                            <div class="pay-totals-row pay-totals-row--discount" v-if="selected.discount > 0">
                                <span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                    Giảm giá
                                </span>
                                <span class="pay-totals-val pay-totals-val--discount">−{{ formatVND(selected.discount) }}</span>
                            </div>
                            <div class="pay-totals-row">
                                <span>Thuế</span>
                                <span class="pay-totals-val">{{ formatVND(selected.tax) }}</span>
                            </div>
                            <div class="pay-totals-divider"></div>
                            <div class="pay-totals-row pay-totals-row--grand">
                                <span>Tổng cộng</span>
                                <span class="pay-totals-val pay-totals-val--grand">{{ formatVND(selected.total) }}</span>
                            </div>
                            <div class="pay-totals-row" v-if="selected.paid > 0">
                                <span>Đã thanh toán</span>
                                <span class="pay-totals-val pay-totals-val--paid">{{ formatVND(selected.paid) }}</span>
                            </div>
                            <div class="pay-totals-row pay-totals-row--due" v-if="selected.due > 0">
                                <span>Còn lại</span>
                                <span class="pay-totals-val pay-totals-val--due">{{ formatVND(selected.due) }}</span>
                            </div>
                        </div>

                        <!-- Payment history -->
                        <div class="pay-history" v-if="selected.payments && selected.payments.length">
                            <div class="pay-history-title">Lịch sử thanh toán</div>
                            <div class="pay-history-list">
                                <div class="pay-history-item" v-for="p in selected.payments" :key="p.id">
                                    <div class="pay-history-left">
                                        <span class="pay-history-method">{{ methodName(p.method) }}</span>
                                        <span class="pay-history-time">{{ formatDateTime(p.paid_at) }}</span>
                                    </div>
                                    <div class="pay-history-right">
                                        <span class="pay-history-amount">{{ formatVND(p.amount) }}</span>
                                        <span class="pay-history-status" :class="`pay-history-status--${p.status.toLowerCase()}`">{{ p.status === 'SUCCESS' ? '✓ Thành công' : p.status === 'PENDING' ? '⏳ Chờ' : '✗ Thất bại' }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="pay-footer">
                        <button class="pay-btn-pay" v-if="selected.status !== 'PAID'" @click="openPaymentModal(selected)">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            Thu tiền ngay
                        </button>
                        <button class="pay-btn-print" :disabled="busy" @click="printReceipt">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            In / Lưu PDF
                        </button>
                    </footer>
                </section>
            </div>

            <!-- Payment collection modal -->
            <div v-if="payment" class="payment-overlay" @click.self="!busy && (payment = null)">
                <section class="payment-dialog payment-dialog--collect">
                    <header class="pay-header">
                        <div class="pay-header-left">
                            <span class="pay-eyebrow">Thu tiền</span>
                            <h2 class="pay-title">{{ payment.code }}</h2>
                        </div>
                        <div class="pay-header-right">
                            <div class="pay-due-badge">
                                <span class="pay-due-label">Còn lại</span>
                                <strong class="pay-due-amount">{{ formatVND(payment.due) }}</strong>
                            </div>
                            <button class="pay-close" @click="payment = null" aria-label="Đóng" :disabled="busy">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </header>

                    <!-- Quick order summary inside payment -->
                    <div class="pay-collect-summary" v-if="payment.items && payment.items.length">
                        <div class="pay-collect-items">
                            <div class="pay-collect-items-row" v-for="item in payment.items.filter(i => i.status !== 'CANCELLED').slice(0, 4)" :key="item.id">
                                <span class="pay-collect-item-name">{{ item.menu_item_name }}</span>
                                <span class="pay-collect-item-qty">×{{ item.quantity }}</span>
                                <span class="pay-collect-item-price">{{ formatVND(item.subtotal) }}</span>
                            </div>
                            <div class="pay-collect-items-more" v-if="payment.items.filter(i => i.status !== 'CANCELLED').length > 4">
                                +{{ payment.items.filter(i => i.status !== 'CANCELLED').length - 4 }} món khác
                            </div>
                        </div>
                        <div class="pay-collect-totals">
                            <div class="pay-collect-total-row">
                                <span>Tổng cộng</span>
                                <strong>{{ formatVND(payment.total) }}</strong>
                            </div>
                            <div class="pay-collect-total-row" v-if="payment.discount > 0">
                                <span>Giảm giá</span>
                                <strong class="discount-text">−{{ formatVND(payment.discount) }}</strong>
                            </div>
                            <div class="pay-collect-total-row" v-if="payment.paid > 0">
                                <span>Đã thu</span>
                                <strong class="paid-text">−{{ formatVND(payment.paid) }}</strong>
                            </div>
                            <div class="pay-collect-total-row pay-collect-total-row--due">
                                <span>Còn phải thu</span>
                                <strong class="due-text">{{ formatVND(payment.due) }}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Payment method selector -->
                    <div class="pay-method-section">
                        <h3 class="pay-method-title">Phương thức thanh toán</h3>
                        <div class="pay-method-grid">
                            <button
                                v-for="m in paymentMethods"
                                :key="m.value"
                                class="pay-method-btn"
                                :class="{ 'pay-method-btn--active': method === m.value }"
                                @click="method = m.value"
                                :disabled="busy"
                                type="button"
                            >
                                <span class="pay-method-icon" v-html="m.icon"></span>
                                <span class="pay-method-label">{{ m.label }}</span>
                                <span class="pay-method-check" v-if="method === m.value">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Confirmation warning -->
                    <div class="pay-confirm-warning">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span>Chỉ xác nhận khi đã nhận đủ tiền từ khách hàng.</span>
                    </div>

                    <!-- Actions -->
                    <footer class="pay-footer">
                        <button class="pay-btn-cancel" type="button" :disabled="busy" @click="payment = null">
                            Hủy
                        </button>
                        <button class="pay-btn-confirm" type="button" :disabled="busy" @click="collectPayment">
                            <span v-if="busy" class="pay-spinner"></span>
                            <template v-else>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                Xác nhận đã thu đủ
                            </template>
                        </button>
                    </footer>
                </section>
            </div>

            <!-- Success overlay -->
            <Transition name="pay-success-fade">
                <div v-if="showSuccess" class="pay-success-overlay">
                    <div class="pay-success-card">
                        <div class="pay-success-icon">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h3>Thanh toán thành công!</h3>
                        <p>Đã ghi nhận thanh toán cho hóa đơn <strong>{{ successCode }}</strong></p>
                        <button class="pay-btn-continue" @click="dismissSuccess">Tiếp tục</button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { beginRead } from '@/utils/realtime'
const liveReads = {}
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import apiClient from '@/api/client'
import { staffOrders, type Order, type OrderItem } from '@/api/staff'
import { formatVND, formatDateTime, useToast } from '@/composables/useStaffUtils'
interface RequestedOrder extends Order { invoice_requested_at: string | null; invoice_exported_at: string | null }
interface Invoice {
    id: number; code: string; order_id: number; order_code: string; order_type: string; table_code: string | null;
    customer_name: string | null; waiter_name: string | null; issued_at: string; status: string;
    total: number; paid: number; due: number; subtotal: number; discount: number; tax: number;
    invoice_exported_at: string | null; items: OrderItem[];
    payments: { id: number; method: string; amount: number; paid_at: string | null; status: string }[];
}
const invoices = ref<Invoice[]>([])
const orders = ref<RequestedOrder[]>([])
const selected = ref<Invoice | null>(null)
const payment = ref<Invoice | null>(null)
const method = ref('CASH')
const showSuccess = ref(false)
const successCode = ref('')
const search = ref('')
const status = ref('')
const tab = ref('requests')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const toast = useToast()

const paymentMethods = [
    {
        value: 'CASH',
        label: 'Tiền mặt',
        icon: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M2 10h2M20 10h2M2 14h2M20 14h2"/></svg>`,
    },
    {
        value: 'TRANSFER',
        label: 'Chuyển khoản',
        icon: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>`,
    },
    {
        value: 'QR',
        label: 'Quét QR',
        icon: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="3" height="3" rx=".5"/><line x1="21" y1="14" x2="21" y2="14.01"/><line x1="21" y1="21" x2="21" y2="21.01"/><line x1="14" y1="21" x2="14" y2="21.01"/><line x1="21" y1="17" x2="21" y2="17.01"/><line x1="17" y1="21" x2="17" y2="21.01"/></svg>`,
    },
]

function methodName(m: string): string {
    return ({ CASH: 'Tiền mặt', TRANSFER: 'Chuyển khoản', QR: 'Quét QR' } as Record<string, string>)[m] || m
}

let stopped = false
const requests = computed(() => orders.value.filter(o => o.invoice_requested_at && !o.invoice_exported_at && o.status !== 'CANCELLED').sort((a, b) => Date.parse(a.invoice_requested_at!) - Date.parse(b.invoice_requested_at!)))
function matches(...values: (string | null | undefined)[]) { return values.join(' ').toLocaleLowerCase('vi').includes(search.value.trim().toLocaleLowerCase('vi')) }
const visibleRequests = computed(() => requests.value.filter(o => matches(o.code, o.table_code, o.waiter_name)))
const visibleInvoices = computed(() => invoices.value.filter(i => (!status.value || i.status === status.value) && matches(i.code, i.order_code, i.table_code, i.customer_name)))
const todayRevenue = computed(() => invoices.value.reduce((sum, i) => sum + i.payments.filter(p => p.status === 'SUCCESS' && p.paid_at && new Date(p.paid_at).toDateString() === new Date().toDateString()).reduce((n, p) => n + p.amount, 0), 0))
function statusLabel(value: string) { return ({ PAID: 'Đã thanh toán', UNPAID: 'Chưa thanh toán', PARTIAL: 'Thanh toán một phần', PENDING: 'Chờ thanh toán', FAILED: 'Thất bại', REFUNDED: 'Đã hoàn tiền' } as Record<string, string>)[value] || value }
async function refresh(quiet = false) {
    if (loading.value || stopped) return
    if (!quiet) loading.value = true
    const fresh = beginRead(liveReads, 'list', quiet); try {
        const [list, orderList] = await Promise.all([apiClient.get<{ data: Invoice[] }>('/staff/invoices'), staffOrders.list()])
        if (!fresh()) return
        if (!stopped) {
            invoices.value = list.data.data
            orders.value = orderList as RequestedOrder[]
            if (selected.value && !payment.value && !busy.value) {
                const summary = invoices.value.find(i => i.id === selected.value?.id)
                if (summary) selected.value = { ...summary, items: selected.value.items }
            }
            error.value = ''
        }
    } catch (refreshError) { if (quiet) throw refreshError; if (!stopped) error.value = 'Không tải được dữ liệu hóa đơn. Vui lòng thử lại.' }
    finally { if (!quiet) loading.value = false }
}
async function action(fn: () => Promise<void>) {
    if (busy.value) return
    busy.value = true
    try { await fn() } catch (e: any) { toast.error(e?.response?.data?.message || 'Không thể xử lý hóa đơn.') }
    finally { busy.value = false }
}
async function view(id: number) { await action(async () => { selected.value = (await apiClient.get<{ data: Invoice }>('/staff/invoices/' + id)).data.data }) }
function openPaymentModal(invoice: Invoice) {
    payment.value = invoice
}
async function exportOrder(id: number) {
    await action(async () => {
        selected.value = (await apiClient.post<{ data: Invoice }>('/staff/invoices/export-order/' + id)).data.data
        toast.success('Hóa đơn đã sẵn sàng để in hoặc lưu PDF.')
        await refresh()
    })
}
async function printReceipt() {
    if (!selected.value) return
    let ready = false
    await action(async () => {
        selected.value = (await apiClient.post<{ data: Invoice }>('/staff/invoices/export-order/' + selected.value!.order_id)).data.data
        ready = true
    })
    if (ready) { window.print(); void refresh() }
}
async function collectPayment() {
    if (!payment.value) return
    await action(async () => {
        const updated = (await apiClient.patch<{ data: Invoice }>('/staff/invoices/' + payment.value!.id + '/pay', { method: method.value, amount: payment.value!.due })).data.data
        successCode.value = updated.code
        payment.value = null
        selected.value = updated
        showSuccess.value = true
        await refresh()
    })
}
function dismissSuccess() {
    showSuccess.value = false
    successCode.value = ''
}
onMounted(() => { void refresh() })
onUnmounted(() => { stopped = true })
useRealtimeRefresh(() => refresh(true), () => !busy.value && !loading.value)
</script>

<style scoped>
/* ============================================
   PAGE LAYOUT
   ============================================ */
.cashier-page { display: flex; flex-direction: column; gap: var(--space-5); font-family: var(--font-family-base); color: var(--color-charcoal-black); background: var(--color-cream); min-height: 100vh; padding: var(--space-6); }

.cashier-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-4); }
.cashier-heading h2 { margin: 0; font-size: 22px; font-weight: 700; color: var(--color-charcoal-black); }
.cashier-heading p { color: var(--color-gray-500); font-size: 13px; margin: 6px 0 0; }

/* ============================================
   SUMMARY GRID
   ============================================ */
.summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
.summary-grid > div { display: flex; flex-direction: column; gap: 12px; background: var(--color-white); border: 1px solid var(--color-gray-200); padding: 20px 24px; border-radius: var(--radius-lg); border-top-width: 3px; }
.summary-grid > div:first-child { border-top-color: var(--color-warning); }
.summary-grid > div:nth-child(2) { border-top-color: var(--color-info); }
.summary-grid > div:last-child { border-top-color: var(--color-success); background: var(--color-success-bg); border-color: var(--color-success); }
.summary-grid span { color: var(--color-gray-500); font-size: 13px; }
.summary-grid strong { font-size: 26px; color: var(--color-charcoal-black); font-variant-numeric: tabular-nums; }
.summary-grid > div:last-child strong { color: #166534; }

/* ============================================
   TOOLBAR
   ============================================ */
.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding: 12px 16px; background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); }
.toolbar nav { display: flex; gap: 4px; background: var(--color-gray-100); padding: 4px; border-radius: var(--radius-md); }
.toolbar nav button { border-color: transparent; background: transparent; padding: 8px 16px; font-weight: 600; font-size: 13px; color: var(--color-gray-700); border-radius: 6px; transition: background var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out); }
.toolbar nav button.active { background: var(--color-chili-red); color: var(--color-white); }
.toolbar input, .toolbar select { padding: 9px 12px; border: 1.5px solid var(--color-gray-300); border-radius: var(--radius-md); font-size: 13px; font-family: inherit; background: var(--color-white); color: var(--color-charcoal-black); transition: border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out); }
.toolbar input { flex: 1; min-width: 180px; }
.toolbar input:focus, .toolbar select:focus { outline: none; border-color: var(--color-chili-red); box-shadow: 0 0 0 3px rgba(215,25,32,0.1); }

/* ============================================
   BUTTONS
   ============================================ */
button { padding: 9px 14px; border: 1.5px solid var(--color-gray-300); border-radius: var(--radius-md); background: var(--color-white); color: var(--color-gray-700); cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 500; transition: background var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out); }
button:hover:not(:disabled) { border-color: var(--color-chili-red); box-shadow: var(--shadow-sm); }
button:active:not(:disabled) { transform: scale(0.98); }
button:disabled { opacity: .5; cursor: not-allowed; }
button:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 2px; }
.toolbar > button:not(:last-child) { background: var(--color-chili-red); color: white; border-color: var(--color-chili-red); }
.toolbar > button:not(:last-child):hover:not(:disabled) { background: var(--color-dark-red); border-color: var(--color-dark-red); }
.actions button { margin: 2px; }
.actions button:last-of-type { background: var(--color-chili-red); color: white; border-color: var(--color-chili-red); }

/* ============================================
   DATA TABLE
   ============================================ */
.table-wrap { background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
th { background: var(--color-gray-50); color: var(--color-gray-500); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
th, td { padding: 14px 16px; border-bottom: 1px solid var(--color-gray-100); vertical-align: middle; }
.table-wrap tbody tr:hover { background: var(--color-gray-50); }
.table-wrap td:first-child strong { color: var(--color-charcoal-black); font-size: 13px; }
.table-wrap small { display: block; color: var(--color-gray-400); font-size: 11px; margin-top: 3px; }
td { white-space: nowrap; }

/* ============================================
   BADGES
   ============================================ */
.badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; background: var(--color-warning-bg); color: #92400E; }
.badge.paid { background: var(--color-success-bg); color: #166534; }

/* ============================================
   EMPTY / ERROR
   ============================================ */
.empty { padding: 40px; text-align: center; color: var(--color-gray-400); }
.error { color: var(--color-error); font-size: 13px; padding: 8px 0; }

/* ============================================
   PAYMENT OVERLAY
   ============================================ */
.payment-overlay { position: fixed; inset: 0; background: rgba(17, 17, 17, 0.55); backdrop-filter: blur(3px); z-index: var(--z-modal); display: flex; justify-content: center; align-items: center; padding: 20px; font-family: var(--font-family-base); }
.payment-dialog { background: var(--color-white); border-radius: var(--radius-xl); box-shadow: var(--shadow-xl); display: flex; flex-direction: column; overflow: hidden; max-height: 92vh; }
.payment-dialog--receipt { width: 680px; max-width: 100%; }
.payment-dialog--collect { width: 520px; max-width: 100%; }

/* ============================================
   DIALOG HEADER
   ============================================ */
.pay-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; background: var(--color-gray-50); border-bottom: 1px solid var(--color-gray-200); gap: 16px; flex-shrink: 0; }
.pay-header-left { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.pay-eyebrow { font-size: 11px; font-weight: 600; color: var(--color-gray-400); text-transform: uppercase; letter-spacing: 0.5px; }
.pay-title { font-size: 20px; font-weight: 800; color: var(--color-charcoal-black); margin: 0; overflow-wrap: break-word; }
.pay-header-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.pay-close { background: var(--color-gray-100); border: none; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; padding: 0; color: var(--color-gray-500); cursor: pointer; transition: background var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out); }
.pay-close:hover:not(:disabled) { background: var(--color-gray-200); color: var(--color-charcoal-black); }
.pay-close:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 2px; }
.pay-status { font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: var(--radius-sm); white-space: nowrap; }
.pay-status--paid { background: var(--color-success-bg); color: #166534; }
.pay-status--unpaid { background: var(--color-error-bg); color: #DC2626; }
.pay-status--partial { background: var(--color-warning-bg); color: #92400E; }
.pay-status--pending { background: var(--color-info-bg); color: #1D4ED8; }

/* ============================================
   DIALOG BODY
   ============================================ */
.pay-body { overflow-y: auto; flex: 1; }

/* Order identity */
.pay-identity { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0; padding: 0; }
.pay-identity-item { display: flex; flex-direction: column; gap: 4px; padding: 14px 20px; border-right: 1px solid var(--color-gray-100); border-bottom: 1px solid var(--color-gray-100); min-width: 0; }
.pay-identity-item:nth-child(odd) { padding-left: 20px; }
.pay-identity-item:nth-child(even) { padding-right: 20px; border-right: none; }
.pay-identity-label { font-size: 11px; color: var(--color-gray-400); font-weight: 600; display: flex; align-items: center; gap: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
.pay-identity-item strong { font-size: 13px; font-weight: 700; color: var(--color-charcoal-black); }
.mono { font-family: 'Courier New', monospace; font-size: 12px !important; }

/* Items */
.pay-items { padding: 0 0 8px; }
.pay-items-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; font-size: 12px; font-weight: 600; color: var(--color-gray-500); text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--color-gray-100); }
.pay-items-count { font-size: 11px; font-weight: 700; color: var(--color-chili-red); background: var(--color-error-bg); padding: 2px 8px; border-radius: var(--radius-full); }
.pay-items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pay-items-table th { background: transparent; color: var(--color-gray-400); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; padding: 10px 16px 8px; border-bottom: 1px solid var(--color-gray-100); }
.pay-items-table td { padding: 10px 16px; border-bottom: 1px solid var(--color-gray-50); vertical-align: top; }
.pay-items-table tbody tr:hover { background: var(--color-gray-50); }
.pay-items-table tbody tr:last-child td { border-bottom: none; }
.col-name { width: 45%; }
.col-qty, .col-price, .col-total { text-align: right; white-space: nowrap; }
.col-qty { width: 50px; }
.col-price { width: 100px; }
.col-total { width: 120px; }
.item-name { font-weight: 600; color: var(--color-charcoal-black); display: block; }
.item-toppings { display: flex; flex-direction: column; gap: 2px; margin-top: 3px; }
.item-topping { font-size: 11px; color: var(--color-gray-400); }
.item-spicy { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; color: var(--color-chili-red); font-weight: 600; margin-top: 3px; background: var(--color-error-bg); padding: 1px 6px; border-radius: 4px; }
.item-qty { font-weight: 700; font-size: 15px; color: var(--color-charcoal-black); background: var(--color-gray-100); border-radius: 6px; padding: 2px 8px; display: inline-block; min-width: 28px; text-align: center; }
.pay-items-table td:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; color: var(--color-gray-700); }

/* Totals */
.pay-totals { padding: 16px 20px; background: var(--color-gray-50); border-top: 1px solid var(--color-gray-100); }
.pay-totals-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; font-size: 13px; color: var(--color-gray-700); }
.pay-totals-row--discount { color: var(--color-success); }
.pay-totals-row--grand { font-weight: 700; font-size: 15px; color: var(--color-charcoal-black); padding-top: 10px; }
.pay-totals-row--due { font-weight: 700; font-size: 15px; color: var(--color-chili-red); padding-bottom: 10px; }
.pay-totals-divider { height: 1px; background: var(--color-gray-200); margin: 6px 0; }
.pay-totals-val { font-variant-numeric: tabular-nums; }
.pay-totals-val--discount { color: var(--color-success); font-weight: 600; }
.pay-totals-val--paid { color: var(--color-gray-500); text-decoration: line-through; }
.pay-totals-val--grand { color: var(--color-chili-red); font-size: 20px; font-weight: 800; }
.pay-totals-val--due { color: var(--color-chili-red); font-size: 20px; font-weight: 800; }

/* Payment history */
.pay-history { padding: 16px 20px; border-top: 1px solid var(--color-gray-100); }
.pay-history-title { font-size: 11px; font-weight: 700; color: var(--color-gray-400); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
.pay-history-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--color-gray-50); border-radius: var(--radius-md); margin-bottom: 6px; }
.pay-history-left { display: flex; flex-direction: column; gap: 3px; }
.pay-history-method { font-size: 13px; font-weight: 600; color: var(--color-charcoal-black); }
.pay-history-time { font-size: 11px; color: var(--color-gray-400); }
.pay-history-right { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.pay-history-amount { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--color-charcoal-black); }
.pay-history-status { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
.pay-history-status--success { background: var(--color-success-bg); color: #166534; }
.pay-history-status--pending { background: var(--color-warning-bg); color: #92400E; }
.pay-history-status--failed { background: var(--color-error-bg); color: #DC2626; }

/* Dialog footer */
.pay-footer { display: flex; gap: 12px; padding: 16px 24px; background: var(--color-gray-50); border-top: 1px solid var(--color-gray-200); flex-shrink: 0; }
.pay-btn-pay { flex: 1; background: var(--color-chili-red); color: white; border-color: var(--color-chili-red); font-size: 15px; font-weight: 700; padding: 13px 20px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; gap: 8px; }
.pay-btn-pay:hover:not(:disabled) { background: var(--color-dark-red); border-color: var(--color-dark-red); box-shadow: var(--shadow-red); }
.pay-btn-print { background: var(--color-gray-100); border-color: var(--color-gray-300); color: var(--color-gray-700); font-size: 13px; font-weight: 600; padding: 11px 18px; border-radius: var(--radius-lg); display: flex; align-items: center; gap: 6px; }
.pay-btn-print:hover:not(:disabled) { background: var(--color-gray-200); }

/* ============================================
   PAYMENT COLLECTION MODAL
   ============================================ */

/* Due badge */
.pay-due-badge { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.pay-due-label { font-size: 10px; font-weight: 600; color: var(--color-gray-400); text-transform: uppercase; letter-spacing: 0.3px; }
.pay-due-amount { font-size: 18px; font-weight: 800; color: var(--color-chili-red); font-variant-numeric: tabular-nums; }

/* Quick order summary */
.pay-collect-summary { border-bottom: 1px solid var(--color-gray-100); }
.pay-collect-items { padding: 14px 20px; }
.pay-collect-items-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13px; }
.pay-collect-item-name { flex: 1; color: var(--color-gray-700); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pay-collect-item-qty { font-weight: 600; color: var(--color-gray-400); font-size: 12px; white-space: nowrap; }
.pay-collect-item-price { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--color-charcoal-black); white-space: nowrap; }
.pay-collect-items-more { font-size: 12px; color: var(--color-gray-400); padding: 4px 0 0; }
.pay-collect-totals { padding: 12px 20px; background: var(--color-gray-50); border-top: 1px solid var(--color-gray-100); }
.pay-collect-total-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 13px; color: var(--color-gray-500); }
.pay-collect-total-row strong { color: var(--color-charcoal-black); font-variant-numeric: tabular-nums; }
.pay-collect-total-row--due { padding-top: 8px; margin-top: 4px; border-top: 1px solid var(--color-gray-200); font-weight: 700; font-size: 14px; color: var(--color-charcoal-black); }
.discount-text { color: var(--color-success) !important; }
.paid-text { color: var(--color-gray-400) !important; text-decoration: line-through; }
.due-text { color: var(--color-chili-red) !important; }

/* Payment method selector */
.pay-method-section { padding: 20px 24px; border-bottom: 1px solid var(--color-gray-100); }
.pay-method-title { font-size: 13px; font-weight: 700; color: var(--color-gray-700); margin: 0 0 12px; text-transform: uppercase; letter-spacing: 0.3px; }
.pay-method-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.pay-method-btn { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 8px; border-radius: var(--radius-lg); border: 2px solid var(--color-gray-200); background: var(--color-white); color: var(--color-gray-700); cursor: pointer; transition: border-color var(--dur-fast) var(--ease-out), background var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out); position: relative; font-family: inherit; font-size: 12px; font-weight: 600; }
.pay-method-btn:hover:not(:disabled) { border-color: var(--color-chili-red); background: var(--color-cream-deep); }
.pay-method-btn--active { border-color: var(--color-chili-red); background: var(--color-cream-deep); color: var(--color-chili-red); box-shadow: 0 0 0 3px rgba(215,25,32,0.15); }
.pay-method-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pay-method-icon { color: var(--color-gray-400); transition: color var(--dur-fast) var(--ease-out); }
.pay-method-btn--active .pay-method-icon { color: var(--color-chili-red); }
.pay-method-label { font-size: 12px; font-weight: 600; }
.pay-method-check { position: absolute; top: 6px; right: 6px; background: var(--color-chili-red); color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; }
.pay-method-check svg { width: 12px; height: 12px; }

/* Warning */
.pay-confirm-warning { display: flex; align-items: center; gap: 10px; padding: 12px 24px; background: var(--color-warning-bg); border-bottom: 1px solid #FDE68A; font-size: 13px; color: #92400E; }
.pay-confirm-warning svg { flex-shrink: 0; color: #D97706; }

/* Collection modal footer */
.pay-btn-cancel { background: var(--color-gray-100); border-color: var(--color-gray-300); color: var(--color-gray-700); font-size: 14px; font-weight: 600; padding: 12px 20px; border-radius: var(--radius-lg); }
.pay-btn-cancel:hover:not(:disabled) { background: var(--color-gray-200); }
.pay-btn-confirm { flex: 1; background: var(--color-success); color: white; border-color: var(--color-success); font-size: 14px; font-weight: 700; padding: 13px 20px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; gap: 8px; }
.pay-btn-confirm:hover:not(:disabled) { background: #16A34A; border-color: #16A34A; box-shadow: 0 8px 24px rgba(34,197,94,0.35); }
.pay-spinner { width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,0.4); border-top-color: white; border-radius: 50%; animation: spin 600ms linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ============================================
   SUCCESS OVERLAY
   ============================================ */
.pay-success-overlay { position: fixed; inset: 0; background: rgba(17,17,17,0.7); backdrop-filter: blur(6px); z-index: var(--z-toast); display: flex; align-items: center; justify-content: center; padding: 20px; }
.pay-success-card { background: var(--color-white); border-radius: var(--radius-xl); padding: 40px 36px; text-align: center; max-width: 400px; width: 100%; box-shadow: var(--shadow-xl); }
.pay-success-icon { width: 72px; height: 72px; background: var(--color-success-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: var(--color-success); }
.pay-success-card h3 { font-size: 22px; font-weight: 800; color: var(--color-charcoal-black); margin: 0 0 8px; }
.pay-success-card p { font-size: 14px; color: var(--color-gray-500); margin: 0 0 24px; }
.pay-success-card strong { color: var(--color-chili-red); }
.pay-btn-continue { background: var(--color-chili-red); color: white; border: none; border-radius: var(--radius-md); padding: 14px 32px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; transition: background var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out); }
.pay-btn-continue:hover { background: var(--color-dark-red); box-shadow: var(--shadow-red); }
.pay-success-fade-enter-active { transition: opacity 200ms ease; }
.pay-success-fade-leave-active { transition: opacity 150ms ease; }
.pay-success-fade-enter-from, .pay-success-fade-leave-to { opacity: 0; }

/* ============================================
   PRINT STYLES
   ============================================ */
@media print {
    body:has(> .payment-overlay) > * { display: none !important; }
    body > .payment-overlay { display: block !important; position: static !important; background: white !important; padding: 0 !important; }
    .payment-overlay .pay-header { display: none !important; }
    .payment-overlay .payment-dialog { max-height: none !important; overflow: visible !important; width: 100% !important; border-radius: 0 !important; }
    .payment-overlay .pay-body { overflow: visible !important; }
    .payment-overlay .pay-footer { display: none !important; }
}

/* ============================================
   RESPONSIVE
   ============================================ */
@media (max-width: 700px) {
    .summary-grid { grid-template-columns: 1fr; }
    .cashier-heading { flex-wrap: wrap; }
    .pay-identity { grid-template-columns: 1fr 1fr; }
    .pay-method-grid { grid-template-columns: 1fr; }
    .pay-footer { flex-wrap: wrap; }
    .pay-btn-pay { flex: none; width: 100%; }
    .payment-overlay { padding: 8px; align-items: flex-end; }
    .payment-dialog { border-radius: var(--radius-xl) var(--radius-xl) 0 0; max-height: 95vh; }
}
@media (max-width: 500px) {
    .cashier-page { padding: 12px; gap: 12px; }
    .pay-header { padding: 14px 16px; }
    .pay-title { font-size: 17px; }
    .pay-identity { grid-template-columns: 1fr; }
    .pay-identity-item { border-right: none !important; padding: 10px 16px !important; }
    .pay-method-section { padding: 14px 16px; }
    .pay-footer { padding: 12px 16px; }
}
@media (prefers-reduced-motion: reduce) {
    button, .pay-method-btn, .pay-btn-pay, .pay-close { transition: none !important; }
    .pay-spinner { animation: none !important; }
}
</style>
<style>
@media print {
    body:has(> .payment-overlay) > * { display: none !important; }
    body > .payment-overlay { display: block !important; position: static !important; background: white !important; padding: 0 !important; }
    .payment-overlay .pay-header { display: none !important; }
    .payment-overlay .payment-dialog { max-height: none !important; overflow: visible !important; width: 100% !important; border-radius: 0 !important; }
    .payment-overlay .pay-footer { display: none !important; }
    .payment-overlay tr { break-inside: avoid; }
}
</style>
