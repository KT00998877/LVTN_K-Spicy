<script setup lang="ts">
/**
 * UiContainer — single source of truth for horizontal layout width & gutters.
 *
 * Variants:
 *  - default       (max-width 1280) — most sections, forms, product info
 *  - narrow        (max-width 960)  — story blocks, centered copy, reservation
 *  - wide          (max-width 1440) — campaign, editorial gallery
 *  - editorial-full — full edge-to-edge (no max-width) — Hero only
 *
 * Spicy Editorial System — Phase 1 Foundation.
 * KEEP API — same name `size` to keep existing callers working.
 */
defineProps<{
    size?: 'default' | 'narrow' | 'wide' | 'editorial-full'
}>()
</script>

<template>
    <div class="ui-container" :class="`size-${size ?? 'default'}`">
        <slot />
    </div>
</template>

<style scoped>
.ui-container {
    width: 100%;
    margin-inline: auto;
    padding-inline: var(--space-6);
    position: relative;
}

.size-default        { max-width: var(--container-functional); }   /* 1280 */
.size-narrow         { max-width: var(--container-functional-narrow); } /* 960 */
.size-wide           { max-width: var(--container-editorial); }     /* 1440 */
.size-editorial-full { max-width: none; padding-inline: 0; }

/* Mobile: tighter gutters to give content more room. */
@media (max-width: 640px) {
    .ui-container {
        padding-inline: var(--space-5);
    }
    .size-editorial-full {
        padding-inline: 0;
    }
}
</style>
