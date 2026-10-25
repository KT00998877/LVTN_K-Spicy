<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchMyPromotions, type ClaimedPromotion } from '@/api/promotions'

const items = ref<ClaimedPromotion[]>([])
const loading = ref(false)
const error = ref('')
const copyState = ref<Record<number, 'idle' | 'copied' | 'failed'>>({})

async function load(): Promise<void> {
    if (loading.value) return
    loading.value = true
    error.value = ''
    try {
        items.value = await fetchMyPromotions()
    } catch {
        error.value = 'Không thể tải danh sách ưu đãi. Vui lòng thử lại.'
    } finally {
        loading.value = false
    }
}

async function copyCode(item: ClaimedPromotion): Promise<void> {
    try {
        await navigator.clipboard.writeText(item.voucher_code)
        copyState.value = { ...copyState.value, [item.id]: 'copied' }
    } catch {
        copyState.value = { ...copyState.value, [item.id]: 'failed' }
    }
    window.setTimeout(() => {
        copyState.value = { ...copyState.value, [item.id]: 'idle' }
    }, 2200)
}

function formatDate(value: string | null): string {
    if (!value) return 'Không giới hạn'
    return new Date(value).toLocaleDateString('vi-VN')
}

onMounted(load)
</script>

<template>
    <div class="promotion-panel">
        <header class="promotion-head">
            <div>
                <h2>Ưu đãi của tôi</h2>
                <p>Các mã bạn đã nhận và có thể dùng khi thanh toán.</p>
            </div>
            <button type="button" class="promotion-action" :disabled="loading" @click="load">
                {{ loading ? 'Đang tải…' : 'Tải lại' }}
            </button>
        </header>

        <p v-if="error" class="promotion-alert" role="alert">{{ error }}</p>

        <div v-if="loading && items.length === 0" class="promotion-state" aria-live="polite">
            <span class="promotion-spinner" aria-hidden="true"></span>
            <p>Đang tải ưu đãi…</p>
        </div>

        <div v-else-if="items.length === 0" class="promotion-state">
            <h3>Chưa có mã ưu đãi</h3>
            <p>Nhận mã từ banner ưu đãi trên trang chủ để lưu vào tài khoản.</p>
            <router-link to="/" class="promotion-primary">Xem ưu đãi</router-link>
        </div>

        <div v-else class="promotion-list">
            <article v-for="item in items" :key="item.id" class="promotion-row">
                <div class="promotion-copy">
                    <div class="promotion-title-line">
                        <h3>{{ item.name }}</h3>
                        <span v-if="item.discount_label">{{ item.discount_label }}</span>
                    </div>
                    <p>{{ item.message }}</p>
                    <small>Đã nhận {{ formatDate(item.claimed_at) }} · Hết hạn {{ formatDate(item.ends_at) }}</small>
                </div>
                <div class="promotion-code-block">
                    <code>{{ item.voucher_code }}</code>
                    <div class="promotion-actions">
                        <button type="button" class="promotion-action" @click="copyCode(item)">
                            {{ copyState[item.id] === 'copied' ? 'Đã sao chép' : copyState[item.id] === 'failed' ? 'Thử lại' : 'Sao chép' }}
                        </button>
                        <router-link :to="{ path: '/checkout', query: { voucher: item.voucher_code } }" class="promotion-primary">Dùng mã</router-link>
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>

<style scoped>
.promotion-panel { display: flex; flex-direction: column; gap: 24px; }
.promotion-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }
.promotion-head h2 { margin: 0 0 6px; color: var(--color-charcoal-black); font-size: 24px; }
.promotion-head p { margin: 0; color: var(--color-gray-600); font-size: 14px; }
.promotion-list { display: flex; flex-direction: column; }
.promotion-row { display: grid; grid-template-columns: 1fr minmax(230px, auto); gap: 24px; padding: 24px 0; border-bottom: 1px solid var(--color-gray-200); }
.promotion-row:first-child { padding-top: 0; }
.promotion-row:last-child { padding-bottom: 0; border-bottom: 0; }
.promotion-title-line { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.promotion-title-line h3 { margin: 0; color: var(--color-charcoal-black); font-size: 18px; }
.promotion-title-line span { padding: 4px 10px; border-radius: var(--radius-full); background: #fee2e2; color: #991b1b; font-size: 13px; font-weight: 700; }
.promotion-copy p { margin: 8px 0; color: var(--color-gray-600); font-size: 15px; line-height: 1.5; }
.promotion-copy small { color: var(--color-gray-500); font-size: 13px; }
.promotion-code-block { display: flex; flex-direction: column; align-items: stretch; gap: 10px; }
.promotion-code-block code { padding: 12px 16px; border: 2px dashed var(--color-chili-red); border-radius: var(--radius-md); background: #fff7f7; color: #991b1b; font-size: 16px; font-weight: 800; text-align: center; overflow-wrap: anywhere; }
.promotion-actions { display: flex; gap: 8px; }
.promotion-action, .promotion-primary { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 10px 16px; border-radius: var(--radius-full); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.promotion-action { border: 1px solid var(--color-gray-300); background: var(--color-white); color: var(--color-charcoal-black); }
.promotion-primary { border: 1px solid var(--color-chili-red); background: var(--color-chili-red); color: var(--color-white); text-decoration: none; }
.promotion-action:hover:not(:disabled) { background: var(--color-gray-50); }
.promotion-primary:hover { box-shadow: var(--shadow-sm); }
.promotion-action:focus-visible, .promotion-primary:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 3px; }
.promotion-action:disabled { cursor: not-allowed; opacity: .6; }
.promotion-state { padding: 56px 20px; border-radius: var(--radius-lg); background: var(--color-gray-50); color: var(--color-gray-600); text-align: center; }
.promotion-state h3 { margin: 0 0 8px; color: var(--color-charcoal-black); font-size: 20px; }
.promotion-state p { margin: 0 0 20px; font-size: 15px; }
.promotion-spinner { display: block; width: 32px; height: 32px; margin: 0 auto 12px; border: 3px solid var(--color-gray-200); border-top-color: var(--color-chili-red); border-radius: 50%; animation: promotion-spin .8s linear infinite; }
.promotion-alert { margin: 0; padding: 14px 16px; border-radius: var(--radius-md); background: #fef2f2; color: #991b1b; font-size: 14px; font-weight: 600; }
@keyframes promotion-spin { to { transform: rotate(360deg); } }
@media (max-width: 700px) {
    .promotion-head, .promotion-row { grid-template-columns: 1fr; }
    .promotion-head { flex-direction: column; }
    .promotion-actions > * { flex: 1; }
}
@media (prefers-reduced-motion: reduce) { .promotion-spinner { animation-duration: 0s; } }
</style>
