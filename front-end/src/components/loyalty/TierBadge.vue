<script setup lang="ts">
import { computed } from 'vue'
import type { LoyaltyTier } from '@/types/auth'

/**
 * TierBadge — badge hạng thành viên (Đồng/Bạc/Vàng/VIP).
 *
 * Sử dụng:
 *   <TierBadge :tier="user.tier" size="sm" show-name />
 *
 * Props:
 *  - tier: object tier từ API (AuthUser.tier) hoặc public list
 *  - size: 'xs' | 'sm' | 'md'  (default 'sm')
 *  - show-name: có hiển thị tên hạng kế bên icon hay không (default false)
 */

interface Props {
    tier: LoyaltyTier | null | undefined
    size?: 'xs' | 'sm' | 'md'
    showName?: boolean
    progressPercent?: number | null | undefined
}

const props = withDefaults(defineProps<Props>(), {
    size: 'sm',
    showName: false,
    progressPercent: null,
})

const tooltip = computed(() => {
    if (!props.tier) return ''
    const t = props.tier
    const lines = [`Hạng ${t.name} (${t.code})`]
    if (t.point_multiplier && t.point_multiplier !== 1.0) {
        lines.push(`Tích điểm x${t.point_multiplier.toFixed(1)}`)
    }
    if (t.free_ship_threshold !== null && t.free_ship_threshold !== undefined) {
        if (t.free_ship_threshold === 0) {
            lines.push('Miễn phí ship mọi đơn')
        } else {
            lines.push(`Free ship đơn từ ${formatVnd(t.free_ship_threshold)}`)
        }
    }
    if (t.extra_discount_pct && t.extra_discount_pct > 0) {
        lines.push(`Giảm thêm ${t.extra_discount_pct}%`)
    }
    return lines.join(' · ')
})

function formatVnd(v: number): string {
    return new Intl.NumberFormat('vi-VN').format(v) + 'đ'
}
</script>

<template>
    <span
        v-if="tier"
        class="tier-badge"
        :class="['size-' + size, { 'with-name': showName }]"
        :title="tooltip"
        :style="{ '--tier-color': tier.color }"
        role="status"
        :aria-label="tooltip"
    >
        <span class="tier-icon" aria-hidden="true">{{ tier.icon || '⭐' }}</span>
        <span v-if="showName" class="tier-name">{{ tier.name }}</span>
        <span v-if="progressPercent !== null && progressPercent !== undefined && progressPercent > 0 && progressPercent < 100"
              class="tier-progress" aria-hidden="true">
            {{ Math.floor(progressPercent) }}%
        </span>
    </span>
</template>

<style scoped>
.tier-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--color-surface);
    border: 2px solid color-mix(in srgb, var(--tier-color, var(--color-brand-action)) 65%, var(--color-brand-ink));
    color: var(--color-brand-ink);
    box-shadow: var(--shadow-sm);
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
    transition: transform var(--dur-fast) var(--ease-out);
}

.tier-badge:hover {
    transform: translateY(-1px);
}

.size-xs {
    padding: 3px 7px;
    font-size: 10px;
}
.size-xs .tier-icon { font-size: 12px; }

.size-sm {
    padding: 4px 10px;
    font-size: 12px;
}
.size-sm .tier-icon { font-size: 13px; }

.size-md {
    padding: 6px 12px;
    font-size: 14px;
}
.size-md .tier-icon { font-size: 15px; }

.tier-icon {
    font-size: 13px;
    line-height: 1;
}

.tier-name {
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tier-progress {
    margin-left: 4px;
    padding-left: 6px;
    border-left: 1px solid currentColor;
    opacity: 0.85;
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .tier-badge:hover { transform: none; }
}
</style>
