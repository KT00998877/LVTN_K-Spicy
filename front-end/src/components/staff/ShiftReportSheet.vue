<template>
    <article class="shift-sheet">
        <header><div><span>MÌ CAY HÀN QUỐC</span><h2>{{ report.report_date ? 'Báo cáo doanh thu ngày' : 'Báo cáo cuối ca (cũ)' }}</h2></div><strong>{{ report.code || 'BẢN XEM TRƯỚC' }}</strong></header>
        <p v-if="report.report_date">Ngày báo cáo: <b>{{ report.report_date.split('-').reverse().join('/') }}</b></p><p>Người lập: <b>{{ report.cashier_name }}</b></p>
        <p>{{ date(report.from) }} → {{ date(report.to) }} · Giờ Việt Nam</p>
        <p v-if="report.closed_at">Chốt lúc: {{ date(report.closed_at) }}</p>
        <div class="metrics"><div><span>Tổng thu trong kỳ</span><b>{{ money(report.total_collected) }}</b></div><div><span>Hóa đơn có phát sinh thu</span><b>{{ report.invoice_count }}</b></div><div><span>Lượt thu tiền</span><b>{{ report.payment_count }}</b></div></div>
        <p class="hint">Tổng thu gồm các giao dịch thành công của toàn nhà hàng, tính theo thời điểm thu tiền, từ đầu ngày đến thời điểm ghi trên báo cáo. Không cộng lại toàn bộ giá trị hóa đơn khi thu nhiều lần.</p>
        <div class="columns"><section><h3>Thu theo phương thức</h3><p v-for="(amount, method) in report.by_method" :key="method" class="line"><span>{{ methodName(String(method)) }}</span><b>{{ money(amount) }}</b></p></section>
        <section><h3>Đối soát két của thu ngân</h3><p class="line">Tiền đầu kỳ <b>{{ money(report.opening_cash) }}</b></p><p class="line">Tiền mặt trực tiếp thu <b>{{ money(report.cashier_cash) }}</b></p><p class="line">Nhận thêm / phục vụ bàn giao <b>{{ money(report.cash_in) }}</b></p><p class="line">Chi / nộp ra <b>{{ money(report.cash_out) }}</b></p><p class="line total">Tiền mặt dự kiến <b>{{ money(report.expected_cash) }}</b></p><p class="line">Kiểm đếm thực tế <b>{{ money(report.actual_cash) }}</b></p><p class="line difference" :class="{ mismatch: report.difference !== 0 }">{{ report.difference > 0 ? 'Thừa' : report.difference < 0 ? 'Thiếu' : 'Khớp quỹ' }} <b>{{ money(Math.abs(report.difference)) }}</b></p></section></div>
        <p class="hint">Tiền do phục vụ thu không tự cộng vào két thu ngân. Ghi số thực nhận tại “Nhận thêm / phục vụ bàn giao”. Phương thức “Khác” được thống kê riêng.</p>
        <h3>Ghi chú bàn giao</h3><p class="note">{{ report.note || 'Không có ghi chú.' }}</p>
        <h3>Chi tiết giao dịch</h3><div class="table-scroll"><table><thead><tr><th>Thời điểm</th><th>Hóa đơn</th><th>Người thu</th><th>Phương thức</th><th>Số tiền</th></tr></thead><tbody><tr v-for="p in report.payments" :key="p.id"><td>{{ date(p.processed_at) }}</td><td>{{ p.invoice_code }}</td><td>{{ p.collector || '—' }}</td><td>{{ methodName(p.method) }}</td><td>{{ money(p.amount) }}</td></tr></tbody></table></div><p v-if="!report.payments.length" class="hint">Không phát sinh giao dịch thành công trong kỳ.</p>
        <footer><div>Người bàn giao<br /><small>Ký, ghi rõ họ tên</small><p>{{ report.cashier_name }}</p></div><div>Người nhận bàn giao<br /><small>Ký, ghi rõ họ tên</small></div></footer>
    </article>
</template>
<script setup lang="ts">
import type { ShiftReport } from '@/types/shiftReport'
defineProps<{ report: ShiftReport }>()
const money = (n: number) => n.toLocaleString('vi-VN') + ' đ'
const date = (s: string) => new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })
const methodName = (s: string) => ({ CASH: 'Tiền mặt', BANK_TRANSFER: 'Chuyển khoản', QR: 'QR', ONLINE_GATEWAY: 'Cổng trực tuyến', OTHER: 'Khác' } as Record<string, string>)[s] || s
</script>
<style scoped>
.shift-sheet { padding: 28px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; color: #1e293b; font-family: Arial, sans-serif; font-size: 13px; }
header { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #166534; padding-bottom: 15px; } header span { font-size: 11px; letter-spacing: 1px; } h2 { margin: 8px 0; } header > strong { font-size: 12px; }
.metrics, .columns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 24px 0; }.metrics > div { display: flex; flex-direction: column; gap: 10px; padding: 16px; background: #f0fdf4; border-radius: 8px; }.metrics b { font-size: 20px; color: #166534; }.columns { grid-template-columns: 1fr 1fr; }.line { display: flex; justify-content: space-between; gap: 16px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }.line b { white-space: nowrap; }.hint { color: #64748b; font-size: 12px; line-height: 1.7; }.total { font-weight: 600; }.difference { color: #166534; }.mismatch { color: #b45309; }.note { white-space: pre-wrap; line-height: 1.7; }.table-scroll { overflow-x: auto; }table { width: 100%; border-collapse: collapse; font-size: 12px; }th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }th { background: #f8fafc; }td:last-child, th:last-child { text-align: right; white-space: nowrap; }footer { display: flex; justify-content: space-around; text-align: center; margin-top: 35px; min-height: 110px; }footer small { color: #64748b; }footer p { margin-top: 65px; }
@media (max-width: 700px) { .columns, .metrics { grid-template-columns: 1fr; }.shift-sheet { padding: 16px; } }
@media print { .shift-sheet { border: 0; padding: 0; font-size: 11px; }.metrics { grid-template-columns: repeat(3, 1fr); }.columns { grid-template-columns: 1fr 1fr; }.table-scroll { overflow: visible; }tr, footer { break-inside: avoid; } }
</style>
