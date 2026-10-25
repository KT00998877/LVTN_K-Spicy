<script setup lang="ts">
/**
 * VoucherInput — ô nhập mã giảm giá + danh sách voucher đang hiệu lực.
 *
 * Props:
 *  - modelValue: mã voucher hiện tại (string|null)
 *  - subtotal: tổng tiền món hiện tại (VND) — dùng để xem voucher nào đủ điều kiện
 *  - disabled: khoá khi đang xử lý
 *
 * Emits:
 *  - update:modelValue (string|null): mã voucher đang được chọn (sau khi preview OK)
 *  - applied (preview): khi áp dụng thành công (cho parent cập nhật tổng)
 *  - cleared (): khi khách bỏ voucher
 *
 * Khi áp dụng thành công → parent sẽ tự gọi /checkout/quote để lấy tổng tiền mới
 * (component không gọi quote trực tiếp để tránh duplicate).
 */
import { ref, onMounted } from 'vue'
import apiClient from '@/api/client'
import { previewVoucher, fetchActiveVouchers } from '@/api/voucher'
import type { Voucher, VoucherPreview } from '@/types/voucher'

const props = defineProps<{
    modelValue: string | null
    subtotal: number
    disabled?: boolean
    /**
     * Mã cần tự áp dụng khi mount (vd khách bấm banner ưu đãi → /checkout?voucher=CODE).
     * Component sẽ gọi preview và emit như khi khách tự nhập tay.
     */
    initialCode?: string | null
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void
    (e: 'applied', preview: VoucherPreview): void
    (e: 'cleared'): void
}>()

const codeInput = ref(props.modelValue ?? '')
const appliedCode = ref<string | null>(props.modelValue ?? null)
const preview = ref<VoucherPreview | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const list = ref<Voucher[]>([])
const listLoading = ref(false)

onMounted(async () => {
    await loadList()
    // Mã đến từ banner/chuông ưu đãi → tự áp dụng luôn.
    const initial = (props.initialCode ?? '').trim()
    if (initial && initial !== appliedCode.value) {
        codeInput.value = initial
        await applyCode(initial)
    }
})

async function loadList() {
    listLoading.value = true
    try {
        list.value = await fetchActiveVouchers()
    } catch {
        // im lặng — không quan trọng
    } finally {
        listLoading.value = false
    }
}

async function applyCode(targetCode?: string) {
    const trimmed = ((targetCode ?? codeInput.value) ?? '').trim().toUpperCase()
    if (!trimmed) return
    loading.value = true
    error.value = null
    try {
        const data = await previewVoucher(trimmed, props.subtotal)
        preview.value = data
        appliedCode.value = data.code
        codeInput.value = data.code
        emit('update:modelValue', data.code)
        emit('applied', data)
    } catch (e: { response?: { status?: number; data?: { message?: string } } } | any) {
        const status = e?.response?.status
        if (status === 422) {
            error.value = e?.response?.data?.message ?? 'Mã không hợp lệ.'
        } else {
            error.value = 'Không kiểm tra được mã. Vui lòng thử lại.'
        }
        preview.value = null
        appliedCode.value = null
        emit('update:modelValue', null)
        emit('cleared')
    } finally {
        loading.value = false
    }
}

function clearApplied() {
    codeInput.value = ''
    appliedCode.value = null
    preview.value = null
    error.value = null
    emit('update:modelValue', null)
    emit('cleared')
}

function pickFromList(v: Voucher) {
    codeInput.value = v.code
    applyCode(v.code)
}

function fmtMoney(n: number): string {
    return n.toLocaleString('vi-VN') + 'đ'
}
</script>

<template>
    <div class="voucher-block">
        <label class="voucher-label" for="voucher-input">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                <line x1="7" y1="7" x2="7.01" y2="7"></line>
            </svg>
            Mã giảm giá
        </label>

        <!-- Đã áp dụng -->
        <div v-if="appliedCode && preview" class="voucher-applied" role="status">
            <div class="applied-info">
                <span class="applied-badge">{{ preview.code }}</span>
                <span class="applied-name">{{ preview.name }}</span>
                <span class="applied-amount">−{{ fmtMoney(preview.discount) }}</span>
            </div>
            <button
                type="button"
                class="applied-clear"
                :disabled="disabled"
                @click="clearApplied"
                aria-label="Bỏ mã giảm giá"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
                Bỏ mã
            </button>
        </div>

        <!-- Form nhập -->
        <div v-else class="voucher-input-row">
            <input
                id="voucher-input"
                v-model="codeInput"
                type="text"
                class="voucher-input"
                placeholder="Nhập mã (vd: WELCOME10)"
                autocomplete="off"
                spellcheck="false"
                :disabled="disabled || loading"
                @keyup.enter="applyCode()"
            />
            <button
                type="button"
                class="voucher-apply-btn"
                :disabled="disabled || loading || !codeInput.trim()"
                @click="applyCode()"
            >
                {{ loading ? 'Đang kiểm tra…' : 'Áp dụng' }}
            </button>
        </div>

        <p v-if="error" class="voucher-msg error" role="alert">{{ error }}</p>

        <!-- Danh sách voucher đang hiệu lực -->
        <div v-if="!appliedCode && list.length" class="voucher-list">
            <p class="voucher-list-title">Mã khuyến mãi có sẵn</p>
            <div class="voucher-list-grid">
                <button
                    v-for="v in list"
                    :key="v.id"
                    type="button"
                    class="voucher-chip"
                    :disabled="disabled || loading || props.subtotal < v.min_order_subtotal"
                    :title="props.subtotal < v.min_order_subtotal ? `Đơn tối thiểu ${fmtMoney(v.min_order_subtotal)}` : ''"
                    @click="pickFromList(v)"
                >
                    <span class="chip-badge">{{ v.badge }}</span>
                    <span class="chip-name">{{ v.name }}</span>
                    <span class="chip-meta">
                        Đơn từ {{ fmtMoney(v.min_order_subtotal) }}
                        <template v-if="v.usage_limit_per_user"> · {{ v.usage_limit_per_user }}/khách</template>
                    </span>
                </button>
            </div>
        </div>
        <p v-if="!appliedCode && !listLoading && !list.length" class="voucher-msg muted">
            Hiện chưa có mã khuyến mãi nào.
        </p>
    </div>
</template>

<style scoped>
.voucher-block {
    margin-bottom: 24px;
}

.voucher-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 700;
    color: var(--color-gray-600);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.voucher-input-row {
    display: flex;
    gap: 8px;
}

.voucher-input {
    flex: 1;
    padding: 12px 14px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font: inherit;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.voucher-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.12);
}

.voucher-apply-btn {
    padding: 0 18px;
    background: var(--color-charcoal-black);
    color: var(--color-white);
    border: 0;
    border-radius: var(--radius-lg);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s;
}
.voucher-apply-btn:hover:not(:disabled) {
    background: #2d2d2d;
}
.voucher-apply-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Applied state */
.voucher-applied {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    background: rgba(22, 163, 74, 0.08);
    border: 1px solid #16a34a;
    border-radius: var(--radius-lg);
}
.applied-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 0;
}
.applied-badge {
    background: #16a34a;
    color: var(--color-white);
    font-weight: 800;
    font-size: 13px;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    letter-spacing: 0.5px;
}
.applied-name {
    font-size: 14px;
    color: var(--color-charcoal-black);
    font-weight: 600;
}
.applied-amount {
    font-size: 14px;
    color: #15803d;
    font-weight: 800;
}
.applied-clear {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    color: var(--color-gray-500);
    border: 0;
    padding: 6px 10px;
    border-radius: var(--radius-full);
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.2s;
}
.applied-clear:hover:not(:disabled) {
    background: var(--color-gray-100);
    color: var(--color-chili-red);
}
.applied-clear:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* List */
.voucher-list { margin-top: 16px; }
.voucher-list-title {
    font-size: 13px;
    color: var(--color-gray-500);
    margin: 0 0 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.voucher-list-grid {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.voucher-chip {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 10px 14px;
    background: var(--color-white);
    border: 1px dashed var(--color-gray-300);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: border-color 0.2s, background-color 0.2s;
    min-width: 160px;
    text-align: left;
    font: inherit;
}
.voucher-chip:hover:not(:disabled) {
    border-style: solid;
    border-color: var(--color-chili-red);
    background: rgba(215, 25, 32, 0.04);
}
.voucher-chip:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.chip-badge {
    background: var(--color-chili-red);
    color: var(--color-white);
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-weight: 800;
    font-size: 13px;
}
.chip-name {
    font-size: 13px;
    color: var(--color-charcoal-black);
    font-weight: 600;
    line-height: 1.4;
}
.chip-meta {
    font-size: 11.5px;
    color: var(--color-gray-500);
}

.voucher-msg {
    margin: 8px 0 0;
    font-size: 13px;
    font-weight: 500;
}
.voucher-msg.error { color: #dc2626; }
.voucher-msg.muted { color: var(--color-gray-500); }
</style>