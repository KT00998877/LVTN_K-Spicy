<template>
    <transition name="drawer">
        <aside v-if="open" class="drawer" role="dialog" aria-modal="true" :aria-label="`Lịch sử sử dụng ${voucher?.code}`">
            <header class="drawer-head">
                <div>
                    <span class="eyebrow">LỊCH SỬ SỬ DỤNG</span>
                    <h3>{{ voucher?.name || 'Voucher' }}</h3>
                    <small class="meta">
                        <span class="code">{{ voucher?.code }}</span>
                        <span v-if="voucher">· Đã dùng {{ voucher.used_count }}/{{ voucher.usage_limit || '∞' }} lượt</span>
                    </small>
                </div>
                <button type="button" class="icon-btn" aria-label="Đóng" @click="$emit('close')">×</button>
            </header>

            <div class="drawer-body">
                <p v-if="loading" class="state">Đang tải lịch sử…</p>
                <p v-else-if="error" class="state error" role="alert">{{ error }}</p>
                <p v-else-if="!usages.length" class="state empty">
                    <strong>Chưa có lượt sử dụng nào.</strong>
                    <small>Lượt dùng sẽ xuất hiện khi khách thanh toán thành công với mã này.</small>
                </p>
                <ul v-else class="usage-list">
                    <li v-for="u in usages" :key="u.id" class="usage-item">
                        <div class="usage-main">
                            <strong>{{ u.user_name || 'Khách vãng lai' }}</strong>
                            <small v-if="u.order_code">Đơn {{ u.order_code }}</small>
                            <small v-else>Mã lượt #{{ u.id }}</small>
                        </div>
                        <div class="usage-side">
                            <span class="amount">−{{ formatMoney(u.discount_amount) }}</span>
                            <small>{{ formatDate(u.created_at) }}</small>
                        </div>
                    </li>
                </ul>

                <div v-if="meta && meta.last_page > 1" class="pagination">
                    <button class="btn ghost" :disabled="page <= 1 || loading" @click="$emit('change-page', page - 1)">Trước</button>
                    <span>{{ page }} / {{ meta.last_page }}</span>
                    <button class="btn ghost" :disabled="page >= meta.last_page || loading" @click="$emit('change-page', page + 1)">Sau</button>
                </div>
            </div>
        </aside>
    </transition>
    <transition name="fade">
        <div v-if="open" class="backdrop" @click="$emit('close')" />
    </transition>
</template>

<script setup lang="ts">
import type { ManagerVoucher, ManagerVoucherUsage } from '@/types/voucher'

interface Props {
    open: boolean
    voucher: ManagerVoucher | null
    usages: ManagerVoucherUsage[]
    meta?: { total: number; per_page: number; current_page: number; last_page: number } | null
    loading: boolean
    error: string
    page: number
}

defineProps<Props>()
defineEmits<{
    (e: 'close'): void
    (e: 'change-page', page: number): void
}>()

function formatMoney(n: number) {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
function formatDate(iso: string | null) {
    if (!iso) return ''
    return new Date(iso).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<style scoped>
.drawer {
    position: fixed; top: 0; right: 0; bottom: 0;
    width: min(420px, 100%);
    background: var(--cream, #FAF6F1);
    box-shadow: -16px 0 48px rgba(20, 12, 6, 0.18);
    z-index: 60;
    display: flex; flex-direction: column;
    color: var(--charcoal, #1A1A1A);
    font: 14px/1.5 'Inter', sans-serif;
}
.backdrop {
    position: fixed; inset: 0;
    background: rgba(26, 14, 6, 0.45);
    z-index: 55;
}
.drawer-head {
    padding: 22px 24px 18px;
    border-bottom: 1px solid rgba(26, 14, 6, 0.08);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;
}
.drawer-head h3 { margin: 4px 0 6px; font-size: 18px; font-weight: 700; }
.drawer-head .meta { color: #7a6e63; font-size: 12px; display: flex; gap: 8px; align-items: center; }
.drawer-head .code { font-family: 'JetBrains Mono', ui-monospace, monospace; background: rgba(215, 25, 32, 0.1); color: var(--chili, #D71920); padding: 2px 6px; border-radius: 4px; font-weight: 600; }

.drawer-body { padding: 16px 24px 24px; overflow: auto; flex: 1; display: flex; flex-direction: column; gap: 12px; }

.eyebrow { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 1.4px; color: var(--chili, #D71920); }

.state {
    background: rgba(255, 255, 255, 0.6);
    border: 1px dashed rgba(26, 14, 6, 0.14);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    color: #7a6e63;
    font-size: 13px;
}
.state.error { background: rgba(215, 25, 32, 0.06); border-color: rgba(215, 25, 32, 0.2); color: var(--chili, #D71920); }
.state.empty strong { display: block; font-size: 14px; color: #2a221c; margin-bottom: 4px; }
.state.empty small { font-size: 12px; }

.usage-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.usage-item {
    background: #fff;
    border-radius: 10px;
    padding: 12px 14px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    border: 1px solid rgba(26, 14, 6, 0.06);
}
.usage-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.usage-main strong { font-size: 14px; }
.usage-main small { font-size: 12px; color: #7a6e63; }
.usage-side { text-align: right; display: flex; flex-direction: column; gap: 2px; }
.usage-side .amount { font-weight: 700; color: var(--chili, #D71920); font-size: 14px; }
.usage-side small { font-size: 11px; color: #7a6e63; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding-top: 6px; font-size: 12px; }
.btn {
    cursor: pointer; padding: 7px 14px; border-radius: 8px; font: inherit;
    font-weight: 600; border: 1px solid transparent;
}
.btn.ghost { background: transparent; border-color: rgba(26, 14, 6, 0.12); color: #2a221c; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.icon-btn { background: transparent; border: 0; cursor: pointer; font-size: 24px; line-height: 1; padding: 4px 8px; color: #7a6e63; border-radius: 8px; }
.icon-btn:hover { background: rgba(26, 14, 6, 0.06); }

.drawer-enter-active, .drawer-leave-active { transition: transform 0.22s ease; }
.drawer-enter-from, .drawer-leave-to { transform: translateX(100%); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.18s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>