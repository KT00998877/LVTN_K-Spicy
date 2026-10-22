<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
    inventoryBalanceApi,
    type IngredientInventoryBalance,
} from '@/api/ingredients'

const props = defineProps<{ ingredientId: number | null }>()

const balance = ref<IngredientInventoryBalance | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
    if (!props.ingredientId) {
        balance.value = null
        return
    }
    loading.value = true
    error.value = ''
    try {
        balance.value = await inventoryBalanceApi.forIngredient(props.ingredientId)
    } catch (err: unknown) {
        error.value = err instanceof Error ? err.message : 'Không tải được tồn dự kiến.'
        balance.value = null
    } finally {
        loading.value = false
    }
}

watch(() => props.ingredientId, load, { immediate: true })

const statusMessage = computed(() => {
    if (!balance.value) return null
    switch (balance.value.data_status) {
        case 'ready':
            return null
        case 'insufficient_data':
            return 'Chưa có sổ chốt nào — hệ thống không thể ước tính tồn khả dụng. Bếp vẫn quyết định thủ công.'
        case 'missing_snapshot':
            return 'Có món đang nấu nhưng thiếu ảnh chụp công thức — hệ thống không thể ước tính.'
        case 'uncomparable':
            return 'Có nguyên liệu lệch đơn vị giữa công thức và kho — hệ thống không thể so sánh.'
    }
})

function formatNumber(value: number): string {
    return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 3 })
}
</script>

<template>
    <section class="ing-balance" aria-label="Tồn dự kiến">
        <header class="ing-balance__head">
            <strong>Tồn dự kiến (vận hành)</strong>
            <small>3 con số này dùng để xem — không tự chặn; cờ tự chặn đặt ở món, không đặt ở NL.</small>
        </header>

        <p v-if="loading" class="ing-balance__loading" role="status">Đang tải tồn dự kiến…</p>
        <p v-else-if="error" class="ing-balance__error" role="alert">{{ error }}</p>

        <table v-else-if="balance" class="ing-balance__table">
            <tbody>
                <tr>
                    <th scope="row">Tồn thực tế</th>
                    <td class="ing-balance__num">{{ formatNumber(balance.actual_stock) }} <small>{{ balance.unit }}</small></td>
                    <td class="ing-balance__hint">bếp chốt gần nhất</td>
                </tr>
                <tr>
                    <th scope="row">Dự kiến đã dùng</th>
                    <td class="ing-balance__num">{{ formatNumber(balance.expected_usage_qty) }} <small>{{ balance.unit }}</small></td>
                    <td class="ing-balance__hint">món đã bắt đầu nấu</td>
                </tr>
                <tr>
                    <th scope="row">Giữ chỗ</th>
                    <td class="ing-balance__num">{{ formatNumber(balance.reserved_qty) }} <small>{{ balance.unit }}</small></td>
                    <td class="ing-balance__hint">đơn chưa bắt đầu nấu</td>
                </tr>
            </tbody>
            <tfoot>
                <tr :class="['ing-balance__row--avail', `ing-balance__row--${balance.data_status}`]">
                    <th scope="row">Khả dụng ước tính</th>
                    <td class="ing-balance__num ing-balance__num--big">
                        <template v-if="balance.estimated_available !== null">
                            {{ formatNumber(balance.estimated_available) }} <small>{{ balance.unit }}</small>
                        </template>
                        <span v-else>—</span>
                    </td>
                    <td class="ing-balance__hint">thực tế − đã dùng − giữ chỗ</td>
                </tr>
            </tfoot>
        </table>

        <p v-if="statusMessage" class="ing-balance__warn" role="status">{{ statusMessage }}</p>
    </section>
</template>

<style scoped>
.ing-balance { padding: 12px 14px; border-radius: 10px; background: #f7fafc;
               border: 1px solid var(--mgr-gray-200); font-size: 13px; display: flex;
               flex-direction: column; gap: 10px; }
.ing-balance__head { display: flex; flex-direction: column; gap: 2px; }
.ing-balance__head strong { font-size: 13px; font-weight: 800; color: var(--mgr-charcoal-black); }
.ing-balance__head small { font-size: 11px; color: var(--mgr-gray-500); }
.ing-balance__loading, .ing-balance__error, .ing-balance__warn {
    margin: 0; font-size: 12px; line-height: 1.45;
}
.ing-balance__loading { color: var(--mgr-gray-600); }
.ing-balance__error { color: #991b1b; background: #fee2e2; padding: 6px 10px; border-radius: 8px; }
.ing-balance__warn { color: #92400e; background: #fef3c7; padding: 8px 10px; border-radius: 8px; }

.ing-balance__table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ing-balance__table th { text-align: left; font-weight: 600; color: var(--mgr-gray-700); padding: 6px 0; width: 38%; }
.ing-balance__table td { padding: 6px 0; }
.ing-balance__table tbody tr { border-bottom: 1px dashed var(--mgr-gray-100); }
.ing-balance__table tfoot tr { border-top: 2px solid var(--mgr-gray-200); }
.ing-balance__num { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--mgr-charcoal-black); }
.ing-balance__num small { font-size: 11px; font-weight: 500; color: var(--mgr-gray-500); margin-left: 2px; }
.ing-balance__num--big { font-size: 16px; font-weight: 800; }
.ing-balance__hint { color: var(--mgr-gray-500); font-size: 11px; padding-left: 12px; }

/* Tone cho dòng khả dụng ước tính */
.ing-balance__row--ready .ing-balance__num--big { color: #166534; }
.ing-balance__row--ready { background: rgba(34, 197, 94, 0.04); }

.ing-balance__row--insufficient_data .ing-balance__num--big,
.ing-balance__row--missing_snapshot .ing-balance__num--big,
.ing-balance__row--uncomparable .ing-balance__num--big { color: #92400e; }
.ing-balance__row--insufficient_data,
.ing-balance__row--missing_snapshot,
.ing-balance__row--uncomparable { background: rgba(180, 83, 9, 0.05); }
</style>