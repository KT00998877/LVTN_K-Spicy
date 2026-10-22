<template>
    <span v-if="badge" class="promo-badge" :class="`kind-${kind?.toLowerCase() ?? 'default'}`">
        {{ badge }}
    </span>
</template>

<script setup lang="ts">
import type { PromotionKind } from '@/types/promotion'

interface Props {
    kind?: PromotionKind | null
    badge?: string | null
}
defineProps<Props>()
</script>

<style scoped>
.promo-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.3px;
    font-variant-numeric: tabular-nums;
    background: var(--chili, #d71920);
    color: #fff;
    box-shadow: 0 1px 2px rgba(215, 25, 32, 0.25);
}
.promo-badge.kind-percent_off {
    background: var(--chili, #d71920);
}
.promo-badge.kind-fixed_off {
    background: #0f766e;
}
.promo-badge.kind-bxgy {
    background: #6d28d9;
}
.promo-badge.kind-combo {
    background: #1d4ed8;
}
</style>
