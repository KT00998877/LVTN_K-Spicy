<script setup lang="ts">
import { onMounted, ref } from 'vue'
import apiClient from '@/api/client'

type Period = 'today' | 'week' | 'month'
interface Earning {
    order_id: number
    code: string
    order_type: 'ONLINE' | 'TAKEAWAY'
    actual_distance_km: string | null
    billable_distance_km: string
    rate_per_km: number
    amount: number
    earned_at: string
}
interface EarningsResponse {
    data: { data: Earning[]; current_page: number; last_page: number }
    summary: { order_count: number; total_amount: number }
}

const period = ref<Period>('month')
const periodOptions: { value: Period; label: string }[] = [
    { value: 'today', label: 'Hôm nay' },
    { value: 'week', label: 'Tuần này' },
    { value: 'month', label: 'Tháng này' },
]
const page = ref(1)
const rows = ref<Earning[]>([])
const lastPage = ref(1)
const summary = ref({ order_count: 0, total_amount: 0 })
const loading = ref(false)
const error = ref('')

const money = (amount: number) => `${new Intl.NumberFormat('vi-VN').format(amount)} đ`
const dateKey = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

function dates() {
    const now = new Date()
    const from = new Date(now)
    if (period.value === 'week') from.setDate(now.getDate() - ((now.getDay() + 6) % 7))
    if (period.value === 'month') from.setDate(1)
    return { from: dateKey(from), to: dateKey(now) }
}

async function load(nextPage = 1) {
    if (loading.value) return
    loading.value = true
    error.value = ''
    try {
        const response = await apiClient.get<EarningsResponse>('/staff/deliveries/earnings', {
            params: { ...dates(), page: nextPage },
        })
        rows.value = response.data.data.data
        page.value = response.data.data.current_page
        lastPage.value = response.data.data.last_page
        summary.value = response.data.summary
    } catch {
        error.value = 'Chưa tải được thu nhập. Vui lòng thử lại.'
    } finally {
        loading.value = false
    }
}

function changePeriod(value: Period) {
    period.value = value
    rows.value = []
    summary.value = { order_count: 0, total_amount: 0 }
    void load(1)
}

onMounted(() => { void load() })
</script>

<template>
    <section class="earnings-page">
        <header class="page-heading">
            <div>
                <span class="eyebrow">NHÂN VIÊN GIAO HÀNG</span>
                <h1>Thu nhập giao hàng</h1>
                
            </div>
            <button class="refresh" type="button" :disabled="loading" @click="load(page)">Làm mới</button>
        </header>

        <div class="periods" role="group" aria-label="Khoảng thời gian">
            <button v-for="option in periodOptions"
                :key="option.value" type="button" :disabled="loading" :class="{ active: period === option.value }"
                :aria-pressed="period === option.value" @click="changePeriod(option.value)">{{ option.label }}</button>
        </div>

        <p v-if="error" class="notice error" role="alert">{{ error }} <button type="button" @click="load(page)">Thử lại</button></p>
        <p v-if="loading" class="notice" role="status">Đang tải thu nhập…</p>

        <div class="summary">
            <article><span>Tiền công</span><strong>{{ money(summary.total_amount) }}</strong></article>
            <article><span>Đơn đã giao</span><strong>{{ summary.order_count }}</strong></article>
        </div>

        <div class="history">
            <h2>Chi tiết từng đơn</h2>
            <p v-if="!loading && !error && !rows.length" class="notice">Chưa có đơn giao xong trong khoảng thời gian này.</p>
            <div v-else class="table-scroll">
                <table>
                    <thead><tr><th>Thời gian</th><th>Đơn hàng</th><th>Quãng đường thực</th><th>Quãng đường tính công</th><th>Đơn giá</th><th>Tiền công</th></tr></thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.order_id">
                            <td>{{ new Date(row.earned_at).toLocaleString('vi-VN') }}</td>
                            <td><strong>{{ row.code }}</strong><small>{{ row.order_type === 'TAKEAWAY' ? 'Mang đi' : 'Giao tận nơi' }}</small></td>
                            <td>{{ row.actual_distance_km === null ? '—' : `${Number(row.actual_distance_km).toFixed(2)} km` }}</td>
                            <td>{{ Number(row.billable_distance_km).toFixed(2) }} km</td>
                            <td>{{ money(row.rate_per_km) }}/km</td>
                            <td class="amount">{{ money(row.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="lastPage > 1" class="pagination">
                <button type="button" :disabled="loading || page <= 1" @click="load(page - 1)">Trang trước</button>
                <span>Trang {{ page }}/{{ lastPage }}</span>
                <button type="button" :disabled="loading || page >= lastPage" @click="load(page + 1)">Trang sau</button>
            </div>
        </div>
    </section>
</template>

<style scoped>
.earnings-page { max-width: 1200px; margin: 0 auto; padding: 24px; color: #241516; }
.page-heading { display: flex; justify-content: space-between; align-items: start; gap: 16px; }
.eyebrow { color: #b91c1c; font-size: 12px; font-weight: 800; letter-spacing: .08em; }
h1 { margin: 6px 0; font-size: 28px; }
.page-heading p { margin: 0; color: #705957; }
button { font: inherit; cursor: pointer; }
button:disabled { cursor: default; opacity: .55; }
button:focus-visible { outline: 3px solid #d71920; outline-offset: 2px; }
.refresh, .periods button, .pagination button { min-height: 42px; border: 1px solid #e7c9c4; border-radius: 10px; padding: 8px 14px; background: #fff; color: #861923; font-weight: 700; }
.periods { display: flex; flex-wrap: wrap; gap: 8px; margin: 24px 0 16px; }
.periods .active { background: #d71920; border-color: #d71920; color: #fff; }
.notice { margin: 12px 0; color: #705957; }
.notice.error { color: #991b1b; }
.notice button { border: 0; background: none; color: inherit; font-weight: 700; text-decoration: underline; }
.summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.summary article, .history { background: #fff; border: 1px solid #efddd8; border-radius: 14px; padding: 20px; }
.summary article { display: flex; flex-direction: column; gap: 8px; }
.summary span { color: #705957; }
.summary strong { color: #861923; font-size: 26px; }
.history { margin-top: 16px; }
h2 { margin: 0 0 16px; font-size: 20px; }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; text-align: left; }
th, td { padding: 12px; border-bottom: 1px solid #f0e5e2; white-space: nowrap; }
th { color: #705957; font-size: 13px; }
td small { display: block; margin-top: 4px; color: #705957; }
.amount { color: #861923; font-weight: 800; }
.pagination { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 16px; }
@media (max-width: 640px) { .earnings-page { padding: 16px; } .page-heading { flex-wrap: wrap; } .summary strong { font-size: 22px; } .summary article, .history { padding: 14px; } }
</style>
