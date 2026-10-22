<template>
    <aside v-if="open" class="drawer" role="dialog" aria-label="Lịch sử sử dụng">
        <header class="drawer-head">
            <div>
                <span class="eyebrow">LỊCH SỬ SỬ DỤNG</span>
                <h3>{{ promotion?.name ?? '—' }}</h3>
                <small v-if="promotion" class="muted">
                    {{ promotion.kind_label }} · {{ promotion.badge }}
                </small>
            </div>
            <button type="button" class="icon-btn" aria-label="Đóng" @click="$emit('close')">×</button>
        </header>

        <p v-if="loading" class="state">Đang tải lịch sử…</p>
        <p v-else-if="error" class="alert error" role="alert">{{ error }}</p>
        <p v-else-if="usages.length === 0" class="state empty">
            <strong>Chưa có lượt dùng nào.</strong>
            <small>Khuyến mãi sẽ ghi log khi khách đặt đơn thoả điều kiện.</small>
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th>Đơn</th>
                    <th>Khách</th>
                    <th>Giảm</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="u in usages" :key="u.id">
                    <td>
                        <strong>#{{ u.order_code ?? u.order_id }}</strong>
                        <small v-if="u.order_total !== null" class="muted">
                            Tổng: {{ formatMoney(u.order_total) }}
                        </small>
                    </td>
                    <td>{{ u.user_name ?? '—' }}</td>
                    <td class="amount">-{{ formatMoney(u.discount_amount) }}</td>
                    <td class="muted">{{ u.created_at ? formatDate(u.created_at) : '—' }}</td>
                </tr>
            </tbody>
        </table>

        <footer v-if="meta && meta.last_page > 1" class="drawer-foot">
            <button class="btn ghost" :disabled="page <= 1" @click="$emit('change-page', page - 1)">
                Trước
            </button>
            <span>Trang {{ page }} / {{ meta.last_page }}</span>
            <button
                class="btn ghost"
                :disabled="page >= meta.last_page"
                @click="$emit('change-page', page + 1)"
            >
                Sau
            </button>
        </footer>
    </aside>
</template>

<script setup lang="ts">
import type { ManagerPromotion, ManagerPromotionUsage } from '@/types/promotion'

interface Meta {
    total: number
    per_page: number
    current_page: number
    last_page: number
}

interface Props {
    open: boolean
    promotion: ManagerPromotion | null
    usages: ManagerPromotionUsage[]
    meta: Meta | null
    loading?: boolean
    error?: string
    page: number
}
withDefaults(defineProps<Props>(), { loading: false, error: '' })
defineEmits<{ close: []; 'change-page': [number] }>()

function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('vi-VN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<style scoped>
.drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: min(420px, calc(100vw - 32px));
    background: #fff;
    border-left: 1px solid rgba(26, 14, 6, 0.08);
    box-shadow: -8px 0 30px rgba(26, 14, 6, 0.08);
    z-index: 80;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
    color: #1a1a1a;
}
.drawer-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
    padding-bottom: 10px;
}
.eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
    margin-bottom: 4px;
}
.drawer-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}
.icon-btn {
    background: transparent;
    border: 0;
    font-size: 22px;
    line-height: 1;
    color: #6b5f54;
    cursor: pointer;
    width: 30px;
    height: 30px;
    border-radius: 8px;
}
.icon-btn:hover {
    background: rgba(26, 14, 6, 0.06);
}
.state {
    background: #fff;
    border: 1px dashed rgba(26, 14, 6, 0.14);
    border-radius: 12px;
    padding: 14px;
    text-align: center;
    color: #6b5f54;
    font-size: 13px;
}
.state.empty {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--chili, #d71920);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
th,
td {
    padding: 8px 4px;
    text-align: left;
    border-bottom: 1px solid rgba(26, 14, 6, 0.05);
}
th {
    font-size: 11px;
    text-transform: uppercase;
    color: #6b5f54;
    font-weight: 700;
    letter-spacing: 0.6px;
}
td.amount {
    color: var(--chili, #d71920);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.muted {
    color: #6b5f54;
    font-size: 11px;
    display: block;
}
.drawer-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px solid rgba(26, 14, 6, 0.05);
}
.btn {
    cursor: pointer;
    padding: 8px 12px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
