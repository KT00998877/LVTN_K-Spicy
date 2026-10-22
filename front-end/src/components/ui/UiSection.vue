<script setup lang="ts">
/**
 * UiSection — section rhythm wrapper. Centralizes section padding,
 * id, and background-color variant so every section has the same
 * outer shape.
 *
 * Spicy Editorial System — Phase 1 Foundation.
 *
 * Backgrounds (semantic — never inline `background: red`):
 *  - cream      (default — `--color-background`)
 *  - cream-alt  (`--color-background-alt` — slightly deeper)
 *  - cream-deep (`--color-background-deep` — hero tint)
 *  - white
 *  - charcoal   (dark band — `--color-surface-dark`, white text)
 *  - spicy      (spicy-red band)
 *  - chili      (chili-red band — alias of spicy for commerce CTA)
 *
 * Padding:
 *  - default  — `--section-y` (96 desktop, 48 mobile)
 *  - tight    — `--section-y-tight` (64)
 *  - hero     — `--section-y-hero` (128 desktop)
 *  - flush    — 0 (bleed into next)
 *
 * KEEP API — bg/padding/as unchanged.
 */
withDefaults(
    defineProps<{
        id?: string
        /** Semantic surface tone. `cream-dark` retained as a compatibility alias for `cream-alt`. */
        bg?: 'cream' | 'cream-alt' | 'cream-dark' | 'cream-deep' | 'white' | 'charcoal' | 'spicy' | 'chili'
        padding?: 'default' | 'tight' | 'hero' | 'flush'
        as?: 'section' | 'div'
    }>(),
    { bg: 'cream', padding: 'default', as: 'section' },
)
</script>

<template>
    <component :is="as" :id="id" class="ui-section" :class="[`bg-${bg}`, `pad-${padding}`]">
        <slot />
    </component>
</template>

<style scoped>
.ui-section {
    width: 100%;
    position: relative;
}

/* ===== Semantic backgrounds ===== */
.bg-cream       { background-color: var(--color-background); color: var(--color-text); }
.bg-cream-alt   { background-color: var(--color-background-alt); color: var(--color-text); }
.bg-cream-deep  { background-color: var(--color-background-deep); color: var(--color-text); }
.bg-white       { background-color: var(--color-surface); color: var(--color-text); }
.bg-charcoal    { background-color: var(--color-brand-dark); color: var(--color-text-inverse); }
.bg-spicy       { background-color: var(--color-brand-primary); color: var(--color-text-inverse); }
.bg-chili       { background-color: var(--color-brand-action); color: var(--color-text-inverse); }

/* ===== Padding rhythm ===== */
.pad-default { padding-block: var(--section-y); }
.pad-tight   { padding-block: var(--section-y-tight); }
.pad-hero    { padding-block: var(--section-y-hero); }
.pad-flush   { padding-block: 0; }

/* ===== Mobile ===== */
@media (max-width: 768px) {
    .pad-default { padding-block: var(--section-y-mobile); }
    .pad-tight   { padding-block: var(--section-y-mobile); }
    .pad-hero    { padding-block: var(--section-y-mobile); }
}
</style>
