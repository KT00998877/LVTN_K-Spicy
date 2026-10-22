<template>
    <section class="preview-panel">
        <header>
            <span class="eyebrow">KIỂM TRA NHANH</span>
            <h3>Xem trước mức giảm</h3>
            <small>Thử mã trên một tạm tính bất kỳ — không cần đăng nhập khách.</small>
        </header>

        <form class="form" @submit.prevent="onSubmit">
            <label>
                Mã voucher
                <input v-model="code" placeholder="VD: CHAOGIO10" maxlength="50" />
            </label>
            <label>
                Tạm tính đơn (đ)
                <input v-model.number="subtotal" type="number" min="0" step="1000" />
            </label>
            <button type="submit" class="btn primary" :disabled="loading || !code.trim() || !subtotal">
                {{ loading ? 'Đang tính…' : 'Tính ngay' }}
            </button>
        </form>

        <p v-if="error" class="alert error" role="alert">{{ error }}</p>

        <div v-if="result" class="result">
            <div class="row">
                <span>Mã áp dụng</span>
                <strong>{{ result.code }}</strong>
            </div>
            <div class="row">
                <span>Quyền lợi</span>
                <strong>{{ result.name }}</strong>
            </div>
            <div class="row">
                <span>Loại</span>
                <strong>{{ result.type === 'PERCENT' ? `−${result.value}%` : `−${formatMoney(result.value)}` }}</strong>
            </div>
            <div class="row big">
                <span>Số tiền giảm</span>
                <strong class="save">−{{ formatMoney(result.discount) }}</strong>
            </div>
            <div v-if="result.min_order_subtotal > 0" class="hint">
                Yêu cầu đơn tối thiểu {{ formatMoney(result.min_order_subtotal) }}.
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import type { VoucherPreview } from '@/types/voucher'

const props = defineProps<{
    initialCode?: string
}>()

const code = ref(props.initialCode ?? '')
const subtotal = ref<number>(0)
const loading = ref(false)
const error = ref('')
const result = ref<VoucherPreview | null>(null)

const emit = defineEmits<{
    (e: 'submit', payload: { code: string; subtotal: number }): void
}>()

watch(() => props.initialCode, (v) => {
    if (v) code.value = v
})

async function onSubmit() {
    if (!code.value.trim() || !subtotal.value || subtotal.value <= 0) return
    loading.value = true
    error.value = ''
    try {
        emit('submit', { code: code.value.trim(), subtotal: Number(subtotal.value) })
    } finally {
        loading.value = false
    }
}

function formatMoney(n: number) {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}

defineExpose({
    setResult(v: VoucherPreview | null) { result.value = v },
    setError(msg: string) { error.value = msg; result.value = null },
    setLoading(v: boolean) { loading.value = v },
})
</script>

<style scoped>
.preview-panel {
    background: var(--cream, #FAF6F1);
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 14px;
    padding: 18px;
    display: flex; flex-direction: column; gap: 14px;
    color: var(--charcoal, #1A1A1A);
    font: 14px/1.5 'Inter', sans-serif;
    position: sticky; top: 12px;
}
.eyebrow { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 1.4px; color: var(--chili, #D71920); margin-bottom: 4px; }
.preview-panel h3 { margin: 0; font-size: 16px; font-weight: 700; }
.preview-panel small { color: #7a6e63; font-size: 12px; }

.form { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: end; }
.form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: #2a221c; }
.form input { padding: 9px 11px; border: 1px solid #d6cdc1; border-radius: 8px; font: inherit; background: #fff; }
.form input:focus { outline: none; border-color: var(--chili, #D71920); box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18); }

.btn { cursor: pointer; padding: 10px 16px; border-radius: 10px; font: inherit; font-weight: 600; border: 1px solid transparent; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: var(--chili, #D71920); color: #fff; }
.btn.primary:not(:disabled):hover { background: #b5131a; }

.alert { padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0; }
.alert.error { background: rgba(215, 25, 32, 0.08); color: var(--chili, #D71920); }

.result { display: flex; flex-direction: column; gap: 8px; padding: 14px; background: #fff; border-radius: 10px; }
.result .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; }
.result .row span { color: #7a6e63; }
.result .row.big { border-top: 1px solid rgba(26, 14, 6, 0.08); padding-top: 8px; }
.result .save { color: var(--chili, #D71920); font-size: 18px; font-weight: 800; }
.hint { font-size: 12px; color: #7a6e63; padding-top: 6px; border-top: 1px dashed rgba(26, 14, 6, 0.08); }

@media (max-width: 720px) {
    .form { grid-template-columns: 1fr; }
}
</style>