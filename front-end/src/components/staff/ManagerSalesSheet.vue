<template>
    <article class="sales-sheet">
        <header><span>MÌ CAY HÀN QUỐC</span><h2>{{ invoice ? 'HÓA ĐƠN BÁN HÀNG' : 'CHI TIẾT ĐƠN HÀNG' }}</h2><strong>{{ data.code }}</strong><p>{{ label(data.status) }}</p></header>
        <div class="info"><p>Khách hàng: <b>{{ data.customer_name || 'Khách lẻ' }}</b></p><p>Bàn / hình thức: <b>{{ data.table_code || kinds[data.order_type] || data.order_type }}</b></p><p>Phục vụ: <b>{{ data.waiter_name || '—' }}</b></p><p>Ngày tạo: <b>{{ date(invoice ? data.issued_at : data.placed_at) }}</b></p><p v-if="invoice">Đơn hàng: <b>{{ data.order_code }}</b></p><p v-if="data.completed_at">Hoàn tất: {{ date(data.completed_at) }}</p></div>
        <div class="table-scroll"><table><thead><tr><th>Món ăn</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th><th v-if="!invoice">Trạng thái</th></tr></thead><tbody><tr v-for="item in data.items.filter(i => !invoice || i.status !== 'CANCELLED')" :key="item.id"><td>{{ item.menu_item_name }}<small v-for="t in item.toppings" :key="t.id">+ {{ t.name }} × {{ t.quantity }}</small><small v-if="item.spicy_level != null">Độ cay: {{ item.spicy_level }}</small><small v-if="item.note">{{ item.note }}</small><small v-if="item.cancellation_reason">Lý do hủy: {{ item.cancellation_reason }}</small></td><td>{{ item.quantity }}</td><td>{{ money(item.unit_price) }}</td><td>{{ money(item.subtotal) }}</td><td v-if="!invoice">{{ label(item.status) }}</td></tr></tbody></table></div>
        <div class="totals"><p>Tạm tính <b>{{ money(data.subtotal) }}</b></p><p>Giảm giá <b>{{ money(data.discount) }}</b></p><p v-if="invoice">Thuế <b>{{ money(data.tax || 0) }}</b></p><p class="grand">Tổng cộng <b>{{ money(invoice ? data.total || 0 : data.total_amount || 0) }}</b></p><template v-if="invoice"><p>Đã thanh toán <b>{{ money(data.paid || 0) }}</b></p><p>Còn lại <b>{{ money(data.due || 0) }}</b></p></template></div>
        <p v-if="data.note" class="note">Ghi chú: {{ data.note }}</p>
        <section v-if="invoice && data.payments?.length" class="payment-history"><h3>Lịch sử thanh toán</h3><div v-for="p in data.payments" :key="p.id" class="payment"><span>{{ methods[p.method] || p.method }} · {{ label(p.status) }}<small>{{ date(p.paid_at) }}<template v-if="p.transaction_ref"> · {{ p.transaction_ref }}</template></small></span><b>{{ money(p.amount) }}</b></div></section>
        <footer v-if="invoice">Cảm ơn quý khách. Hẹn gặp lại!</footer>
    </article>
</template>
<script setup lang="ts">
import type { OrderItem } from '@/api/staff'
export interface SalesDetail {
    id: number
    code: string
    status: string
    customer_name: string | null
    table_code: string | null
    order_type: string
    waiter_name: string | null
    issued_at?: string
    placed_at?: string
    completed_at?: string
    order_code?: string
    order_id?: number
    subtotal: number
    discount: number
    tax?: number
    total?: number
    total_amount?: number
    paid?: number
    due?: number
    note?: string
    items: OrderItem[]
    invoices?: { id: number; code: string; status: string }[]
    payments?: { id: number; method: string; status: string; amount: number; paid_at: string | null; transaction_ref?: string }[]
    adjustments?: {
        id: number
        field: string
        old: string | null
        new: string | null
        reason: string
        adjusted_by: string | null
        adjusted_at: string | null
    }[]
}
defineProps<{ data: SalesDetail; invoice: boolean }>()
const kinds: Record<string,string> = { DINE_IN: 'Tại bàn', TAKEAWAY: 'Mang đi', ONLINE: 'Trực tuyến' }
const methods: Record<string,string> = { CASH: 'Tiền mặt', TRANSFER: 'Chuyển khoản', QR: 'QR', OTHER: 'Khác', ONLINE_GATEWAY: 'Cổng trực tuyến' }
const label = (s: string) => ({ PENDING: 'Chờ xử lý', CONFIRMED: 'Đã xác nhận', PREPARING: 'Đang nấu', READY: 'Chờ bưng', SERVED: 'Đã phục vụ', COMPLETED: 'Hoàn tất', CANCELLED: 'Đã hủy', PAID: 'Đã thanh toán', UNPAID: 'Chưa thanh toán', PARTIAL: 'Thanh toán một phần', REFUNDED: 'Đã hoàn tiền', SUCCESS: 'Thành công', FAILED: 'Thất bại' } as Record<string,string>)[s] || s
const money = (n: number) => Number(n).toLocaleString('vi-VN') + ' đ'
const date = (s?: string | null) => s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
</script>
<style scoped>
.sales-sheet { background: white; color: #263b31; padding: 28px; font: 13px Arial,sans-serif; }header { border-bottom: 2px solid #245440; padding-bottom: 16px; }header span { letter-spacing: 2px; font-size: 11px; }h2 { font-size: 22px; margin: 12px 0; }.info { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; margin: 20px 0; }.table-scroll { overflow-x: auto; }table { border-collapse: collapse; width: 100%; }th,td { padding: 12px 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }th { background: #f4f7f5; }small { display: block; margin-top: 4px; color: #64748b; font-size: 11px; }.totals { max-width: 340px; margin: 24px 0 24px auto; }.totals p,.payment { display: flex; justify-content: space-between; gap: 20px; }.grand { border-top: 1px solid #cbd5d0; padding-top: 16px; font-size: 18px; }.payment { padding: 12px 0; border-bottom: 1px solid #e2e8f0; }.note { white-space: pre-wrap; }footer { text-align: center; padding-top: 24px; color: #64748b; }@media(max-width:550px) { .info { grid-template-columns: 1fr; }.sales-sheet { padding: 16px; } }@media print { .sales-sheet { padding: 0; }.info { grid-template-columns: 1fr 1fr; }.table-scroll { overflow: visible; }tr { break-inside: avoid; }.payment-history { display: none; } }
</style>
