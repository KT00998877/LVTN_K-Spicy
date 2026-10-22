<script setup lang="ts">
/**
 * UiSpinner — minimal CSS-only loading indicator. No library, no SVG
 * dependency. Two size variants (md, lg) and two tones (red on white,
 * white on dark).
 */
withDefaults(
    defineProps<{
        size?: 'md' | 'lg'
        tone?: 'red' | 'white' | 'charcoal'
    }>(),
    { size: 'md', tone: 'red' },
)
</script>

<template>
    <span
        class="ui-spinner"
        :class="[`size-${size}`, `tone-${tone}`]"
        role="status"
        aria-label="Đang tải"
    ></span>
</template>

<style scoped>
.ui-spinner {
    display: inline-block;
    border-style: solid;
    border-radius: 50%;
    animation: ui-spin 0.7s linear infinite;
}

.size-md { width: 24px; height: 24px; border-width: 3px; }
.size-lg { width: 36px; height: 36px; border-width: 4px; }

.tone-red     { border-color: rgba(215, 25, 32, 0.2); border-top-color: var(--color-chili-red); }
.tone-white   { border-color: rgba(255, 255, 255, 0.25); border-top-color: var(--color-white); }
.tone-charcoal{ border-color: rgba(17, 17, 17, 0.15); border-top-color: var(--color-charcoal-black); }

@keyframes ui-spin {
    to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
    .ui-spinner { animation-duration: 1.6s; }
}
</style>
