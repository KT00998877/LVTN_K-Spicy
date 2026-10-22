<script setup lang="ts">
/**
 * UiReveal — fade-up animation wrapper for scroll-triggered reveals.
 *
 * Uses IntersectionObserver with a single-shot threshold so the element
 * animates once when it enters the viewport. Falls back gracefully:
 *  - If IntersectionObserver isn't available (very old browsers, SSR),
 *    the content is shown immediately (no JS).
 *  - Under `prefers-reduced-motion`, no animation runs at all — the
 *    content is shown immediately.
 *
 * Element starts with opacity 0 + translateY 12px and transitions to
 * opacity 1 + translateY 0 over 600ms.
 */
import { onMounted, onBeforeUnmount, ref } from 'vue'

const props = withDefaults(
    defineProps<{
        /** Delay in ms before the animation runs. */
        delay?: number
        /** Threshold passed to IntersectionObserver (0–1). */
        threshold?: number
        /** When true, disable the JS fade entirely and show immediately. */
        immediate?: boolean
    }>(),
    { delay: 0, threshold: 0.12, immediate: false },
)

const root = ref<HTMLElement | null>(null)
const revealed = ref(false)
const revealing = ref(false)
let observer: IntersectionObserver | null = null
let revealTimer: number | null = null
let pendingTransitionEnd: ((ev: TransitionEvent) => void) | null = null

function clearRevealTimer() {
    if (revealTimer !== null) {
        window.clearTimeout(revealTimer)
        revealTimer = null
    }
    if (pendingTransitionEnd) {
        root.value?.removeEventListener('transitionend', pendingTransitionEnd)
        pendingTransitionEnd = null
    }
}

function finishReveal() {
    // Phase 8 §60: remove will-change after reveal completes.
    // Prefer transitionend (real); fallback timer (transitionend may not fire
    // if the element was unmounted mid-transition or transitions were disabled).
    clearRevealTimer()
    revealing.value = false
}

onMounted(() => {
    if (props.immediate) {
        revealed.value = true
        return
    }

    // Respect reduced motion — reveal immediately, no observer needed.
    if (typeof window !== 'undefined'
        && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealed.value = true
        return
    }

    if (typeof IntersectionObserver === 'undefined' || !root.value) {
        revealed.value = true
        return
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    revealing.value = true
                    revealed.value = true
                    observer?.disconnect()
                    observer = null

                    // Prefer real transitionend cleanup; timer is the safety net.
                    const el = root.value
                    const onEnd = (ev: TransitionEvent) => {
                        if (ev.target !== el) return
                        if (ev.propertyName !== 'opacity' && ev.propertyName !== 'transform') return
                        finishReveal()
                    }
                    if (el) {
                        pendingTransitionEnd = onEnd
                        el.addEventListener('transitionend', onEnd, { once: false, passive: true })
                    }
                    const dur = 600 + (props.delay || 0) + 80
                    revealTimer = window.setTimeout(finishReveal, dur)
                    break
                }
            }
        },
        { threshold: props.threshold },
    )
    observer.observe(root.value)
})

onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
    clearRevealTimer()
})
</script>

<template>
    <div
        ref="root"
        class="ui-reveal"
        :class="{
            'is-revealed': revealed,
            'is-revealing': revealing,
        }"
        :style="revealed ? { transitionDelay: `${delay}ms` } : { transitionDelay: '0ms' }"
    >
        <slot />
    </div>
</template>

<style scoped>
.ui-reveal {
    opacity: 0;
    transform: translateY(12px);
    transition:
        opacity 600ms var(--ease-out),
        transform 600ms var(--ease-out);
    /* Phase 8 §60: no persistent will-change — promoted on reveal, removed after. */
}

.ui-reveal.is-revealing {
    will-change: opacity, transform;
}

.ui-reveal.is-revealed {
    opacity: 1;
    transform: translateY(0);
}

@media (prefers-reduced-motion: reduce) {
    .ui-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
