<script setup lang="ts">
/**
 * UiEyebrow — Editorial Kicker.
 *
 * Uppercase label that sits above section titles and headings.
 * Establishes editorial rhythm: kicker → title → sub. One
 * component so every kicker shares letter-spacing, weight, and
 * font treatment.
 *
 * Spicy Editorial System — Phase 1 Foundation.
 * - All caps, 11–12px
 * - Weight 600–700 (semibold/bold), Inter UI font (NOT display)
 * - Wide tracking (0.12em)
 * - Tone for color (red default; gold/charcoal/inherit optional)
 * - Optional slot for a leading icon (e.g. chili for spicy sections).
 *
 * KEEP API — same tone prop as v1 so existing callers don't break.
 */
defineProps<{
    /** Override the default chili-red color. */
    tone?: 'red' | 'gold' | 'charcoal' | 'inherit'
}>()
</script>

<template>
    <span class="ui-eyebrow" :class="`tone-${tone ?? 'red'}`">
        <span v-if="$slots.icon" class="ui-eyebrow-icon" aria-hidden="true">
            <slot name="icon" />
        </span>
        <span class="ui-eyebrow-label">
            <slot />
        </span>
    </span>
</template>

<style scoped>
.ui-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: var(--weight-bold);
    text-transform: uppercase;
    letter-spacing: var(--tracking-eyebrow);
    line-height: 1;
}

.ui-eyebrow-label {
    /* Spacing added via the gap on the parent, no decorative bar. */
    display: inline-block;
}

.ui-eyebrow-icon {
    display: inline-flex;
    align-items: center;
    line-height: 1;
}

.tone-red      { color: var(--color-brand-action); }
.tone-gold     { color: var(--color-golden); }
.tone-charcoal { color: var(--color-text); }
.tone-inherit  { color: inherit; }

/* Bold, no oversized decoration. */
.ui-eyebrow::before { content: none; }
</style>
