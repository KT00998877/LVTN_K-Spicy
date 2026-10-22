<script setup lang="ts">
/**
 * UiButton — single primary button primitive used by every CTA.
 * Centralizes variants / sizes / states so the design system rule
 * "one primary style, one secondary style" is enforced in code.
 *
 * Spicy Editorial System — Phase 1 Foundation.
 * - Primary commerce CTA uses chili-red (#E4263D)
 * - Hero/brand surfaces use spicy-red (#DD3508) — set variant="brand"
 * - Text CTA is unstyled; the caller composes with arrow slot
 *
 * Variants:
 *  - primary   — chili-red fill, white text                  (commerce CTA)
 *  - brand     — spicy-red fill, white text                  (hero/campaign CTA)
 *  - outline   — transparent, chili-red border + text
 *  - ghost     — transparent, charcoal text
 *  - danger    — error red
 *  - white     — for use on chili/dark/charcoal backgrounds
 *  - link      — text + arrow (no border / no fill)
 *
 * Sizes:
 *  - sm  (8/16 padding,  14px)
 *  - md  (12/22 padding, 15px)   default
 *  - lg  (14/28 padding, 16px)
 *
 * States: default, hover, active, focus-visible, disabled, loading.
 *
 * KEEP API — same props/emits as v1 so existing callers don't break.
 */
import { computed } from 'vue'

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'brand' | 'outline' | 'ghost' | 'danger' | 'white' | 'link' | 'outline-white'
        size?: 'sm' | 'md' | 'lg'
        block?: boolean
        disabled?: boolean
        loading?: boolean
        /** Element to render — for router-link CTA */
        as?: 'button' | 'a' | 'router-link'
        href?: string
        to?: string | Record<string, unknown>
        type?: 'button' | 'submit' | 'reset'
        ariaLabel?: string
    }>(),
    {
        variant: 'primary',
        size: 'md',
        block: false,
        disabled: false,
        loading: false,
        as: 'button',
        type: 'button',
    },
)

defineEmits<{
    (e: 'click', ev: MouseEvent): void
}>()

const classes = computed(() => [
    'ui-btn',
    `variant-${props.variant}`,
    `size-${props.size}`,
    { block: props.block, loading: props.loading },
])

const isDisabled = computed(() => props.disabled || props.loading)
</script>

<template>
    <router-link
        v-if="as === 'router-link' && to"
        :class="classes"
        :to="to"
        :aria-disabled="isDisabled || undefined"
        :aria-label="ariaLabel"
        @click="$emit('click', $event)"
    >
        <span v-if="loading" class="ui-btn-spinner" aria-hidden="true" />
        <slot v-else />
    </router-link>

    <a
        v-else-if="as === 'a' && href"
        :class="classes"
        :href="href"
        :aria-disabled="isDisabled || undefined"
        :aria-label="ariaLabel"
        @click="$emit('click', $event)"
    >
        <span v-if="loading" class="ui-btn-spinner" aria-hidden="true" />
        <slot v-else />
    </a>

    <button
        v-else
        :class="classes"
        :type="type"
        :disabled="isDisabled"
        :aria-label="ariaLabel"
        @click="$emit('click', $event)"
    >
        <span v-if="loading" class="ui-btn-spinner" aria-hidden="true" />
        <slot v-else />
    </button>
</template>

<style scoped>
.ui-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    border-radius: var(--radius-button);
    font-family: var(--font-ui);
    font-weight: var(--weight-semibold);
    line-height: 1.2;
    font-size: 15px;
    cursor: pointer;
    user-select: none;
    text-align: center;
    white-space: nowrap;
    border: var(--border-width-strong) solid transparent;
    text-decoration: none;
    /* Calm, quick transition. No bouncy springs on UI controls. */
    transition:
        background-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out),
        border-color var(--dur-fast) var(--ease-out),
        transform var(--dur-fast) var(--ease-out),
        box-shadow var(--dur-fast) var(--ease-out);
}

.ui-btn:disabled,
.ui-btn[aria-disabled='true'] {
    opacity: 0.55;
    pointer-events: none;
}

.ui-btn:active {
    transform: translateY(0);
}

/* ===== SIZES ===== */
.size-sm { padding: 8px 16px; font-size: 14px; }
.size-md { padding: 12px 22px; font-size: 15px; }
.size-lg { padding: 14px 28px; font-size: 16px; }

.block { width: 100%; }

/* ===== VARIANT: primary (commerce CTA) ===== */
.variant-primary {
    background-color: var(--color-brand-action);   /* chili-red */
    color: var(--color-text-inverse);
    border-color: var(--color-brand-action);
}
.variant-primary:hover {
    background-color: var(--color-brand-action-hover);
    border-color: var(--color-brand-action-hover);
    /* Subtle nudge, never throws. */
    transform: translateY(-1px);
}
.variant-primary:active {
    transform: translateY(0);
}

/* ===== VARIANT: brand (hero / campaign CTA — spicy-red) ===== */
.variant-brand {
    background-color: var(--color-brand-primary);   /* spicy-red */
    color: var(--color-text-inverse);
    border-color: var(--color-brand-primary);
}
.variant-brand:hover {
    background-color: var(--color-brand-action-hover);
    border-color: var(--color-brand-action-hover);
    transform: translateY(-1px);
}
.variant-brand:active {
    transform: translateY(0);
}

/* ===== VARIANT: outline (1px chili-red border) ===== */
.variant-outline {
    background-color: transparent;
    color: var(--color-brand-action);
    border-color: var(--color-brand-action);
}
.variant-outline:hover {
    background-color: var(--color-brand-action);
    color: var(--color-text-inverse);
}

/* ===== VARIANT: ghost (transparent, charcoal text) ===== */
.variant-ghost {
    background-color: transparent;
    color: var(--color-text);
    border-color: transparent;
}
.variant-ghost:hover {
    background-color: var(--neutral-100);
}

/* ===== VARIANT: danger ===== */
.variant-danger {
    background-color: var(--status-error);
    color: var(--color-text-inverse);
    border-color: var(--status-error);
}
.variant-danger:hover {
    background-color: #DC2626;
    border-color: #DC2626;
}

/* ===== VARIANT: outline-white (transparent, white border — for dark/spicy bands) ===== */
.variant-outline-white {
    background-color: transparent;
    color: var(--color-white);
    border-color: rgba(255, 255, 255, 0.50);
}
.variant-outline-white:hover {
    background-color: rgba(255, 255, 255, 0.10);
    border-color: var(--color-white);
}
.variant-outline-white:active {
    background-color: rgba(255, 255, 255, 0.06);
}

/* ===== VARIANT: white (chili/dark bands) ===== */
.variant-white {
    background-color: var(--color-surface);
    color: var(--color-brand-action);
    border-color: var(--color-surface);
}
.variant-white:hover {
    background-color: var(--cream-deep);
    color: var(--chili-red-hover);
    transform: translateY(-1px);
}
.variant-white:active {
    transform: translateY(0);
}

/* ===== VARIANT: link (text + arrow, no border) ===== */
.variant-link {
    background-color: transparent;
    color: var(--color-text);
    border-color: transparent;
    padding: var(--space-2) 0;
    border-radius: 0;
    font-weight: var(--weight-semibold);
    gap: var(--space-2);
}
.variant-link:hover {
    color: var(--color-brand-action);
}
.variant-link:hover :deep(.ui-btn-arrow) {
    transform: translateX(4px);
}
.variant-link :deep(.ui-btn-arrow) {
    display: inline-block;
    transition: transform var(--dur-fast) var(--ease-out);
}

/* ===== LOADING SPINNER (CSS only — no library) ===== */
.ui-btn-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: ui-btn-spin 0.7s linear infinite;
    display: inline-block;
}

@keyframes ui-btn-spin {
    to { transform: rotate(360deg); }
}

.loading :deep(svg),
.loading :deep(img) { display: none; }

/* Prevent layout shift while loading. */
.loading { min-width: 80px; }
</style>
