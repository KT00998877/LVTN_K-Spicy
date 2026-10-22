<template>
    <span
        v-if="value !== null && value !== undefined"
        :class="['growth-pill', sizeClass, value >= 0 ? 'growth-pill--up' : 'growth-pill--down']"
        :aria-label="`${value >= 0 ? 'Tăng' : 'Giảm'} ${Math.abs(value).toFixed(1)} phần trăm`"
    >
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
            <polyline v-if="value >= 0" points="6 15 12 9 18 15" />
            <polyline v-else points="6 9 12 15 18 9" />
        </svg>
        {{ Math.abs(value).toFixed(1) }}%
        <span v-if="suffix" class="growth-pill__suffix">{{ suffix }}</span>
    </span>
    <span v-else :class="['growth-pill', 'growth-pill--none', sizeClass]">—</span>
</template>

<script setup lang="ts">
/**
 * GrowthPill — hiển thị % tăng/giảm.
 *  - value: number | null. null → render "—".
 *  - size: 'small' | 'normal'
 *  - suffix: chuỗi đi kèm (vd: "so với kỳ trước", "lượng món").
 */
import { computed } from 'vue'

const props = defineProps<{
    value: number | null | undefined
    suffix?: string
    small?: boolean
}>()

const sizeClass = computed(() => (props.small ? 'growth-pill--small' : ''))
</script>

<style scoped>
.growth-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    margin-top: 2px;
}
.growth-pill svg { flex: 0 0 auto; }
.growth-pill__suffix {
    margin-left: 4px;
    color: inherit;
    opacity: 0.7;
    font-weight: 600;
}
.growth-pill--up { background: rgba(34, 197, 94, 0.15); color: #15803d; }
.growth-pill--down { background: rgba(239, 68, 68, 0.15); color: #b91c1c; }
.growth-pill--small { padding: 2px 8px; font-size: 11px; }
.growth-pill--none {
    background: var(--mgr-gray-100, #f3f4f6);
    color: var(--mgr-gray-500, #6b7280);
}
</style>
