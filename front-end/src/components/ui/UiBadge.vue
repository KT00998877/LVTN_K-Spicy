<script setup lang="ts">
/**
 * UiBadge — pill / chip label. Used for "Best-seller", spicy markers,
 * new dish tags, savings pills. One component so we never have
 * stack-of-badges slop and the visual language is consistent.
 *
 * Spicy Editorial System — Phase 1 Foundation.
 * - All pills. Never rectangles. Never oversized.
 * - Homepage / product card should show ONE primary promotional badge.
 *
 * Tones:
 *  - red        — signature badge (best-seller, hot)
 *  - chili      — commerce (price cut, promotion)
 *  - spicy      — hot/spicy indicator
 *  - gold       — savings, special offer
 *  - green      — fresh, success, in stock
 *  - neutral    — metadata (size, prep time)
 *  - dark       — for use on light backgrounds when red feels too loud
 *  - new        — new dish (uses brand red gradient feel via solid red)
 *  - sold-out   — out of stock
 *
 * Variants:
 *  - solid   — filled
 *  - soft    — tinted background
 *  - outline — border only
 *
 * KEEP API — tone/variant/size/slot unchanged from v1.
 */
defineProps<{
    tone?: 'red' | 'chili' | 'spicy' | 'gold' | 'green' | 'neutral' | 'dark' | 'new' | 'sold-out'
    variant?: 'solid' | 'soft' | 'outline'
    size?: 'sm' | 'md'
}>()
</script>

<template>
    <span
        class="ui-badge"
        :class="[`tone-${tone ?? 'red'}`, `variant-${variant ?? 'solid'}`, `size-${size ?? 'md'}`]"
    >
        <slot />
    </span>
</template>

<style scoped>
.ui-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: var(--radius-pill);
    font-family: var(--font-ui);
    font-weight: var(--weight-bold);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    line-height: 1;
    white-space: nowrap;
}

/* ===== SIZES ===== */
.size-sm { font-size: 10px; padding: 5px 9px; }
.size-md { font-size: 11px; padding: 6px 12px; }

/* ===== SOLID VARIANT ===== */
.tone-red.variant-solid {
    background-color: var(--color-brand-action);
    color: var(--color-text-inverse);
}
.tone-chili.variant-solid {
    background-color: var(--color-brand-action);
    color: var(--color-text-inverse);
}
.tone-spicy.variant-solid {
    background-color: var(--color-brand-primary);
    color: var(--color-text-inverse);
}
.tone-new.variant-solid {
    background-color: var(--color-brand-primary);
    color: var(--color-text-inverse);
}
.tone-gold.variant-solid {
    background-color: var(--color-golden);
    color: var(--color-text);
}
.tone-green.variant-solid {
    background-color: var(--status-success);
    color: var(--color-text-inverse);
}
.tone-neutral.variant-solid {
    background-color: var(--neutral-100);
    color: var(--color-text);
}
.tone-dark.variant-solid {
    background-color: var(--color-text);
    color: var(--color-text-inverse);
}
.tone-sold-out.variant-solid {
    background-color: var(--neutral-700);
    color: var(--color-text-inverse);
}

/* ===== SOFT VARIANT ===== */
.tone-red.variant-soft,
.tone-chili.variant-soft {
    background-color: rgba(228, 38, 61, 0.10);
    color: var(--color-brand-action);
}
.tone-spicy.variant-soft,
.tone-new.variant-soft {
    background-color: rgba(221, 53, 8, 0.12);
    color: var(--color-brand-primary);
}
.tone-gold.variant-soft {
    background-color: rgba(255, 176, 0, 0.18);
    color: var(--status-warning-text);
}
.tone-green.variant-soft {
    background-color: var(--status-success-bg);
    color: var(--status-success-text);
}
.tone-neutral.variant-soft {
    background-color: var(--neutral-50);
    color: var(--neutral-600);
}
.tone-sold-out.variant-soft {
    background-color: var(--neutral-100);
    color: var(--neutral-700);
}

/* ===== OUTLINE VARIANT ===== */
.tone-red.variant-outline,
.tone-chili.variant-outline {
    background-color: transparent;
    color: var(--color-brand-action);
    border: var(--border-width-strong) solid var(--color-brand-action);
}
.tone-spicy.variant-outline,
.tone-new.variant-outline {
    background-color: transparent;
    color: var(--color-brand-primary);
    border: var(--border-width-strong) solid var(--color-brand-primary);
}
.tone-gold.variant-outline {
    background-color: transparent;
    color: var(--status-warning-text);
    border: var(--border-width-strong) solid var(--color-golden);
}
.tone-neutral.variant-outline {
    background-color: transparent;
    color: var(--neutral-600);
    border: var(--border-width) solid var(--color-border);
}
</style>
