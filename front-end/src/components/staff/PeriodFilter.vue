<template>
    <div class="period-filter" role="region" aria-label="Bộ lọc thời gian cho dashboard thống kê">
        <div class="period-filter__tabs" role="tablist">
            <button
                v-for="p in PRESETS"
                :key="p.value"
                role="tab"
                :aria-selected="period === p.value"
                :class="['period-filter__tab', { 'period-filter__tab--active': period === p.value }]"
                :disabled="loading"
                @click="onPreset(p.value)"
            >
                {{ p.label }}
            </button>
        </div>
        <div class="period-filter__custom">
            <input
                v-model="customFrom"
                type="date"
                aria-label="Từ ngày"
                :disabled="loading"
                :max="todayStr"
                @change="onCustomChange"
            />
            <span class="period-filter__sep" aria-hidden="true">→</span>
            <input
                v-model="customTo"
                type="date"
                aria-label="Đến ngày"
                :disabled="loading"
                :max="todayStr"
                @change="onCustomChange"
            />
        </div>
        <span v-if="periodLabel" class="period-filter__label">{{ periodLabel }}</span>
    </div>
</template>

<script setup lang="ts">
/**
 * PeriodFilter — date filter dùng chung cho dashboard statistics.
 *  - Preset: today / yesterday / last_7_days / last_30_days / this_week
 *    / previous_week / this_month / previous_month / this_year
 *  - Custom range: from + to (YYYY-MM-DD)
 *  - Validation: from <= to, cả hai <= today (giờ VN).
 */
import { computed, ref, watch } from 'vue'
import type { StatisticsPeriod } from '@/api/statistics'

const PRESETS: Array<{ value: StatisticsPeriod; label: string }> = [
    { value: 'today', label: 'Hôm nay' },
    { value: 'yesterday', label: 'Hôm qua' },
    { value: 'last_7_days', label: '7 ngày' },
    { value: 'last_30_days', label: '30 ngày' },
    { value: 'this_week', label: 'Tuần này' },
    { value: 'previous_week', label: 'Tuần trước' },
    { value: 'this_month', label: 'Tháng này' },
    { value: 'previous_month', label: 'Tháng trước' },
    { value: 'this_year', label: 'Cả năm' },
]

const props = defineProps<{
    period: StatisticsPeriod
    from?: string
    to?: string
    loading?: boolean
    /** Label hiển thị (overview.period.label). */
    periodLabel?: string
}>()

const emit = defineEmits<{
    (e: 'update:period', value: StatisticsPeriod): void
    (e: 'update:from', value: string): void
    (e: 'update:to', value: string): void
    (e: 'apply'): void
}>()

const customFrom = ref<string>(props.from ?? '')
const customTo = ref<string>(props.to ?? '')

const todayStr = computed(() => {
    const d = new Date()
    // YYYY-MM-DD theo timezone VN.
    const vn = new Date(d.toLocaleString('en-US', { timeZone: 'Asia/Ho_Chi_Minh' }))
    const y = vn.getFullYear()
    const m = String(vn.getMonth() + 1).padStart(2, '0')
    const day = String(vn.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
})

watch(
    () => [props.from, props.to],
    ([f, t]) => {
        customFrom.value = f ?? ''
        customTo.value = t ?? ''
    }
)

function onPreset(p: StatisticsPeriod): void {
    emit('update:period', p)
    emit('update:from', '')
    emit('update:to', '')
    emit('apply')
}

function onCustomChange(): void {
    if (!customFrom.value || !customTo.value) return
    if (customFrom.value > customTo.value) {
        // Auto-swap để tránh lỗi UX.
        const a = customFrom.value
        customFrom.value = customTo.value
        customTo.value = a
    }
    if (customFrom.value > todayStr.value || customTo.value > todayStr.value) {
        // Đảm bảo không chọn tương lai.
        if (customTo.value > todayStr.value) customTo.value = todayStr.value
        if (customFrom.value > todayStr.value) customFrom.value = todayStr.value
    }
    emit('update:period', 'custom')
    emit('update:from', customFrom.value)
    emit('update:to', customTo.value)
    emit('apply')
}
</script>

<style scoped>
.period-filter {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    background: #fff;
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 14px;
    padding: 10px 14px;
}
.period-filter__tabs {
    display: flex;
    gap: 4px;
    background: var(--mgr-gray-100, #f3f4f6);
    padding: 4px;
    border-radius: 10px;
    flex-wrap: wrap;
}
.period-filter__tab {
    padding: 7px 12px;
    border: none;
    background: transparent;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--mgr-gray-600, #4b5563);
    font-family: inherit;
    transition: background-color 120ms ease, color 120ms ease;
}
.period-filter__tab:hover:not(:disabled) { color: var(--mgr-charcoal-black, #111111); }
.period-filter__tab--active {
    background: var(--mgr-chili-red, #d71920);
    color: #fff;
    box-shadow: 0 2px 6px rgba(215, 25, 32, 0.25);
}
.period-filter__tab:disabled { opacity: 0.6; cursor: not-allowed; }
.period-filter__custom {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
}
.period-filter__custom input[type='date'] {
    padding: 7px 10px;
    border: 1px solid var(--mgr-gray-200, #e5e7eb);
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    color: var(--mgr-charcoal-black, #111111);
}
.period-filter__custom input:focus {
    outline: 2px solid var(--mgr-chili-red, #d71920);
    outline-offset: 1px;
}
.period-filter__sep {
    color: var(--mgr-gray-500, #6b7280);
    font-weight: 700;
}
.period-filter__label {
    font-size: 12px;
    color: var(--mgr-gray-500, #6b7280);
    font-variant-numeric: tabular-nums;
    background: var(--mgr-cream-dark, #fff5e6);
    color: #b45309;
    padding: 4px 10px;
    border-radius: 9999px;
    font-weight: 700;
}
@media (max-width: 720px) {
    .period-filter__custom { margin-left: 0; width: 100%; justify-content: flex-start; }
    .period-filter__tabs { width: 100%; }
}
</style>
