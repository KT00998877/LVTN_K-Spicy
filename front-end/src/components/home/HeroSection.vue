<script setup lang="ts">
/**
 * HeroSection — Cinematic Layered Food Hero (Phase 3)
 *
 * Layered composition:
 *   L0  Background: spicy-red gradient field + grain SVG + heat radial
 *   L1  Oversized editorial typography (clamped 80–160px desktop)
 *   L2  Main food photography (featured[0])
 *   L3  Supporting food photography (featured[1..3])
 *   L4  Decorative SVG: chilies, sesame dots, steam layers
 *   L5  Commerce UI: primary + secondary CTAs
 *
 * Visual weights (target):
 *   - Main bowl: 45–50% of the visual frame
 *   - Supporting dishes: 35–40%
 *   - Typography + decoration: rest
 *
 * Motion:
 *   - Native CSS keyframes for entrance (no GSAP)
 *   - CSS variables + rAF for pointer parallax (decorative only)
 *   - rAF throttled, cleanup on unmount
 *   - prefers-reduced-motion → instant reveal, no parallax
 *
 * Data: consume useHomeStore (featured items + stats).
 *       Fallback: static approved image if store is empty / fails.
 *
 * No business logic changes.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { imageUrl } from '@/utils/image'
import { useHomeStore } from '@/stores/home'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'
import type { MenuItemCard } from '@/types/home'

const store = useHomeStore()

// =========================================================
// DATA — pick main + supporting from featured
// Phase 3 correction: priority the spicy noodle category for the main
// anchor, then fall back to featured[0]. Supporting dishes exclude the
// main item so the same dish never appears twice.
// =========================================================
const featuredList = computed<MenuItemCard[]>(() => store.featured ?? [])

function isSpicyNoodle(item: MenuItemCard | null | undefined): boolean {
    if (!item) return false
    const cat = String(item.category_slug ?? '').toLowerCase()
    const slug = String(item.slug ?? '').toLowerCase()
    return cat.includes('mi-cay') || slug.includes('mi-cay')
}

const mainBowl = computed<MenuItemCard | null>(() => {
    const items = featuredList.value
    return items.find(isSpicyNoodle) ?? items[0] ?? null
})

const supportingDishes = computed<MenuItemCard[]>(() => {
    const main = mainBowl.value
    return featuredList.value.filter((d) => d.id !== main?.id).slice(0, 3)
})

// Hard-coded approved fallback if API fails (matches old Hero fallback).
const FALLBACK_MAIN = '/storage/menu/mi-cay-hai-san.png'

function foodSrc(item: MenuItemCard | null | undefined): string {
    if (!item) return FALLBACK_MAIN
    return imageUrl(item.image_url) || FALLBACK_MAIN
}

// Track per-image load errors so failed supporting dishes hide cleanly.
const failedImages = ref<Record<number, true>>({})
const mainError = ref(false)
function onMainError() { mainError.value = true }
function onSupportError(id: number) { failedImages.value[id] = true }

const visibleSupporting = computed<MenuItemCard[]>(() =>
    supportingDishes.value.filter((d) => !failedImages.value[d.id]),
)
function supSrc(d: MenuItemCard): string { return foodSrc(d) }

// =========================================================
// POINTER PARALLAX — decorative only, imperative DOM CSS vars + rAF
// Phase 8 Correction §3: write directly to element.style to avoid
// Vue reactivity + template re-render churn on every frame.
// =========================================================
const heroEl = ref<HTMLElement | null>(null)
let rafHandle = 0
let pointerActive = false
let lastX = 0
let lastY = 0

function applyParallaxVars(x: number, y: number) {
    const el = heroEl.value
    if (!el) return
    el.style.setProperty('--parallax-x', x.toFixed(3))
    el.style.setProperty('--parallax-y', y.toFixed(3))
}

function onPointerMove(e: PointerEvent) {
    if (e.pointerType !== 'mouse') return
    pointerActive = true
    lastX = e.clientX
    lastY = e.clientY
    scheduleParallax()
}

function scheduleParallax() {
    if (rafHandle) return
    rafHandle = requestAnimationFrame(() => {
        rafHandle = 0
        if (!pointerActive || !heroEl.value) return
        const rect = heroEl.value.getBoundingClientRect()
        const cx = rect.left + rect.width / 2
        const cy = rect.top + rect.height / 2
        // Normalize to -1..1
        const dx = (lastX - cx) / (rect.width / 2)
        const dy = (lastY - cy) / (rect.height / 2)
        applyParallaxVars(
            Math.max(-1, Math.min(1, dx)),
            Math.max(-1, Math.min(1, dy)),
        )
    })
}

function resetParallax() {
    pointerActive = false
    applyParallaxVars(0, 0)
}

// =========================================================
// REPLAY ENTRANCE — when route returns to /
// =========================================================
const entranceKey = ref(0)
function replayEntrance() {
    // Re-mount the hero subtree by toggling the key.
    entranceKey.value++
}

defineExpose({ replayEntrance })

// =========================================================
// LIFECYCLE
// =========================================================
onMounted(() => {
    if (typeof window === 'undefined') return
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reduce) return
    if (heroEl.value) {
        heroEl.value.addEventListener('pointermove', onPointerMove, { passive: true })
        heroEl.value.addEventListener('pointerleave', resetParallax, { passive: true })
    }
})

onBeforeUnmount(() => {
    if (typeof window === 'undefined') return
    if (heroEl.value) {
        heroEl.value.removeEventListener('pointermove', onPointerMove)
        heroEl.value.removeEventListener('pointerleave', resetParallax)
    }
    if (rafHandle) cancelAnimationFrame(rafHandle)
})

// =========================================================
// TEMPLATE
// =========================================================
</script>

<template>
    <section
        id="hero"
        ref="heroEl"
        class="hero"
        aria-labelledby="hero-title"
    >
        <!-- ===== L0: Background field ===== -->
        <div class="hero-bg" aria-hidden="true">
            <div class="hero-bg-base"></div>
            <div class="hero-bg-heat"></div>
            <div class="hero-bg-glow"></div>
            <svg class="hero-bg-grain" width="100%" height="100%" preserveAspectRatio="none" aria-hidden="true">
                <filter id="hero-grain">
                    <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch"/>
                    <feColorMatrix type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 0.6 0"/>
                </filter>
                <rect width="100%" height="100%" filter="url(#hero-grain)"/>
            </svg>
        </div>

        <!-- ===== L4: Decorative objects (chilies, sesame, steam) ===== -->
        <div class="hero-decor" aria-hidden="true">
            <!-- Floating chili (top-left) -->
            <svg class="hero-chili hero-chili--tl" width="78" height="78" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0">
                <path d="M12 2c2 1 4 3 5 6 1 4-1 9-4 12-2 1-4 1-5 0-2-2-2-5-1-8 1-4 3-7 5-10z" fill="var(--decor-chili-1)"/>
                <path d="M12 5c-1 2-2 5-1 8" stroke="rgba(0,0,0,0.35)" stroke-width="0.8" fill="none"/>
            </svg>
            <!-- Floating chili (bottom-right) -->
            <svg class="hero-chili hero-chili--br" width="58" height="58" viewBox="0 0 24 24" fill="none">
                <path d="M12 2c2 1 4 3 5 6 1 4-1 9-4 12-2 1-4 1-5 0-2-2-2-5-1-8 1-4 3-7 5-10z" fill="var(--decor-chili-2)"/>
            </svg>
            <!-- Floating chili (mid-left) -->
            <svg class="hero-chili hero-chili--ml" width="44" height="44" viewBox="0 0 24 24" fill="none">
                <path d="M12 2c2 1 4 3 5 6 1 4-1 9-4 12-2 1-4 1-5 0-2-2-2-5-1-8 1-4 3-7 5-10z" fill="var(--decor-chili-1)" transform="rotate(35 12 12)"/>
            </svg>

            <!-- Sesame dots cluster (top-right) -->
            <svg class="hero-sesame" width="120" height="80" viewBox="0 0 120 80" fill="none">
                <g fill="var(--decor-sesame)">
                    <ellipse cx="20" cy="20" rx="2.4" ry="1.4" transform="rotate(-15 20 20)"/>
                    <ellipse cx="42" cy="14" rx="2.4" ry="1.4" transform="rotate(20 42 14)"/>
                    <ellipse cx="65" cy="22" rx="2.4" ry="1.4" transform="rotate(-30 65 22)"/>
                    <ellipse cx="88" cy="16" rx="2.4" ry="1.4" transform="rotate(10 88 16)"/>
                    <ellipse cx="30" cy="40" rx="2.4" ry="1.4" transform="rotate(45 30 40)"/>
                    <ellipse cx="55" cy="48" rx="2.4" ry="1.4" transform="rotate(-10 55 48)"/>
                    <ellipse cx="80" cy="42" rx="2.4" ry="1.4" transform="rotate(25 80 42)"/>
                    <ellipse cx="100" cy="50" rx="2.4" ry="1.4" transform="rotate(0 100 50)"/>
                    <ellipse cx="20" cy="64" rx="2.4" ry="1.4" transform="rotate(-20 20 64)"/>
                    <ellipse cx="60" cy="70" rx="2.4" ry="1.4" transform="rotate(15 60 70)"/>
                </g>
            </svg>

            <!-- Steam (3 layers above bowl) -->
            <div class="hero-steam">
                <span class="hero-steam__line"></span>
                <span class="hero-steam__line"></span>
                <span class="hero-steam__line"></span>
            </div>
        </div>

        <UiContainer size="editorial-full" class="hero-container">
            <div class="hero-grid" :key="entranceKey">

                <!-- ===== L1: Oversized typography ===== -->
                <header class="hero-head">
                    <UiEyebrow tone="gold">K-SPICY · MÌ CAY CHUẨN VỊ HÀN</UiEyebrow>

                    <h1 id="hero-title" class="hero-title">
                        <span class="hero-title__line hero-title__line--head" style="--delay: 150ms">CAY</span>
                        <span class="hero-title__line hero-title__line--accent" style="--delay: 280ms">BÙNG VỊ</span>
                        <span class="hero-title__line hero-title__line--tail" style="--delay: 410ms">NGON HẾT Ý.</span>
                    </h1>

                    <p class="hero-sub" style="--delay: 560ms">
                        Nước dùng <strong>12 tiếng</strong>, sợi mì dai, topping tươi mỗi ngày.
                        Tự chọn cấp độ cay — từ nhẹ nhàng đến <em>cực hạn</em>.
                    </p>
                </header>

                <!-- ===== L2 + L3: Layered food photography ===== -->
                <div class="hero-food" aria-hidden="false">
                    <!-- Main bowl — center-right, largest -->
                    <figure class="hero-bowl hero-bowl--main" style="--delay: 380ms">
                        <img
                            v-if="!mainError"
                            :src="foodSrc(mainBowl)"
                            :alt="mainBowl ? `Bát ${mainBowl.name} đặc trưng của K-SPICY` : 'Bát mì cay đặc trưng K-SPICY'"
                            class="hero-bowl__img"
                            width="900"
                            height="900"
                            loading="eager"
                            fetchpriority="high"
                            decoding="async"
                            @error="onMainError"
                        />
                        <div v-else class="hero-bowl__fallback">
                            <span>K-SPICY</span>
                        </div>
                        <figcaption v-if="mainBowl" class="hero-bowl__caption">
                            <span class="hero-bowl__cat">{{ mainBowl.category_name }}</span>
                            <span class="hero-bowl__name">{{ mainBowl.name }}</span>
                        </figcaption>
                    </figure>

                    <!-- Supporting dish 1 (top-right) -->
                    <figure
                        v-if="visibleSupporting[0]"
                        class="hero-bowl hero-bowl--sup hero-bowl--sup-1"
                        style="--delay: 520ms"
                    >
                        <img
                            :src="supSrc(visibleSupporting[0])"
                            :alt="`Món phụ: ${visibleSupporting[0].name}`"
                            class="hero-bowl__img"
                            width="320"
                            height="320"
                            loading="eager"
                            decoding="async"
                            @error="onSupportError(visibleSupporting[0].id)"
                        />
                    </figure>

                    <!-- Supporting dish 2 (bottom-left) -->
                    <figure
                        v-if="visibleSupporting[1]"
                        class="hero-bowl hero-bowl--sup hero-bowl--sup-2"
                        style="--delay: 640ms"
                    >
                        <img
                            :src="supSrc(visibleSupporting[1])"
                            :alt="`Món phụ: ${visibleSupporting[1].name}`"
                            class="hero-bowl__img"
                            width="280"
                            height="280"
                            loading="lazy"
                            decoding="async"
                            @error="onSupportError(visibleSupporting[1].id)"
                        />
                    </figure>

                    <!-- Supporting dish 3 (mid-right) -->
                    <figure
                        v-if="visibleSupporting[2]"
                        class="hero-bowl hero-bowl--sup hero-bowl--sup-3"
                        style="--delay: 760ms"
                    >
                        <img
                            :src="supSrc(visibleSupporting[2])"
                            :alt="`Món phụ: ${visibleSupporting[2].name}`"
                            class="hero-bowl__img"
                            width="240"
                            height="240"
                            loading="lazy"
                            decoding="async"
                            @error="onSupportError(visibleSupporting[2].id)"
                        />
                    </figure>
                </div>

                <!-- ===== L5: Commerce UI (CTAs) =====
                     Phase 3 correction: both CTAs lead to /thuc-don
                     (no /order route exists). "Đặt bàn" lives in Navbar. -->
                <div class="hero-cta" style="--delay: 920ms">
                    <UiButton
                        as="router-link"
                        to="/thuc-don"
                        variant="white"
                        size="lg"
                        class="hero-cta__primary"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="18" x2="13" y2="18"/>
                        </svg>
                        Xem thực đơn
                    </UiButton>
                    <UiButton
                        as="router-link"
                        to="/thuc-don"
                        variant="link"
                        size="lg"
                        class="hero-cta__secondary"
                    >
                        Đặt món ngay
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </UiButton>
                </div>

            </div>
        </UiContainer>
    </section>
</template>

<style scoped>
/* =========================================================
   CINEMATIC LAYERED FOOD HERO — Phase 3
   Spicy red field, layered photography, editorial typography.
   ========================================================= */

.hero {
    /* Local CSS variables for decorative colors. Spicy/red palette
       consumed via the design tokens where possible. */
    --decor-chili-1:   #D71920;
    --decor-chili-2:   #F1A028;
    --decor-sesame:    #F1D2A0;

    /* Position: full-bleed start, behind Navbar. */
    position: relative;
    min-height: 100svh;
    padding: var(--navbar-height) 0 var(--space-9);
    overflow: hidden;
    isolation: isolate;
    color: var(--color-text-inverse);
}

@media (max-width: 768px) {
    .hero { padding-top: var(--navbar-height-mobile); }
}

/* =========== L0: Background =========== */

.hero-bg {
    position: absolute;
    inset: 0;
    z-index: -2;
}
.hero-bg-base {
    position: absolute; inset: 0;
    background:
        linear-gradient(
            180deg,
            var(--color-brand-dark) 0%,
            var(--color-spicy-red) 30%,
            var(--color-brand-action) 65%,
            var(--color-brand-dark) 100%
        );
}
.hero-bg-heat {
    /* Abstract heat field — radial energy */
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse at 80% 20%, rgba(255, 188, 60, 0.55) 0%, transparent 50%),
        radial-gradient(ellipse at 15% 80%, rgba(140, 10, 17, 0.65) 0%, transparent 55%),
        radial-gradient(ellipse at 50% 50%, rgba(221, 53, 8, 0.0) 0%, rgba(140, 10, 17, 0.4) 100%);
    mix-blend-mode: screen;
}
.hero-bg-glow {
    /* Subtle warm glow at center where main bowl sits */
    position: absolute;
    top: 30%; left: 50%;
    transform: translate(-50%, -50%);
    width: 60%; height: 70%;
    background: radial-gradient(circle, rgba(255, 220, 130, 0.25) 0%, transparent 65%);
    pointer-events: none;
}
.hero-bg-grain {
    position: absolute; inset: 0;
    opacity: 0.08;
    mix-blend-mode: overlay;
    pointer-events: none;
}

/* =========== L4: Decorative objects =========== */

.hero-decor {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 1;
}

.hero-chili {
    position: absolute;
    transform: translate(
        calc(var(--parallax-x, 0) * 14px),
        calc(var(--parallax-y, 0) * 14px)
    );
    filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.4));
    opacity: 0.92;
    animation: chili-float 7s ease-in-out infinite;
    will-change: transform;
}
.hero-chili--tl {
    top: 12%;
    left: 6%;
    transform-origin: center;
    animation-delay: 0s;
}
.hero-chili--br {
    bottom: 14%;
    right: 8%;
    animation-delay: 1.4s;
}
.hero-chili--ml {
    top: 48%;
    left: 2%;
    transform: translate(calc(var(--parallax-x, 0) * 18px), calc(var(--parallax-y, 0) * 18px)) rotate(35deg);
    animation-delay: 0.7s;
    opacity: 0.85;
}
@keyframes chili-float {
    0%, 100% { transform: translate(calc(var(--parallax-x, 0) * 14px), calc(var(--parallax-y, 0) * 14px)); }
    50%      { transform: translate(calc(var(--parallax-x, 0) * 14px), calc(var(--parallax-y, 0) * 14px - 10px)); }
}

.hero-sesame {
    position: absolute;
    top: 8%;
    right: 4%;
    transform: translate(
        calc(var(--parallax-x, 0) * -8px),
        calc(var(--parallax-y, 0) * -8px)
    );
    opacity: 0.75;
    animation: sesame-drift 9s ease-in-out infinite;
    will-change: transform;
}
@keyframes sesame-drift {
    0%, 100% { transform: translate(calc(var(--parallax-x, 0) * -8px), calc(var(--parallax-y, 0) * -8px)) rotate(0deg); }
    50%      { transform: translate(calc(var(--parallax-x, 0) * -8px), calc(var(--parallax-y, 0) * -8px)) rotate(4deg); }
}

.hero-steam {
    position: absolute;
    top: 28%;
    left: 50%;
    transform: translate(-50%, 0);
    width: 220px;
    height: 120px;
    z-index: 1;
    pointer-events: none;
}
.hero-steam__line {
    position: absolute;
    bottom: 0;
    width: 14px;
    height: 100%;
    background: linear-gradient(
        180deg,
        rgba(255, 255, 255, 0) 0%,
        rgba(255, 255, 255, 0.25) 50%,
        rgba(255, 255, 255, 0) 100%
    );
    border-radius: 50%;
    filter: blur(6px);
    animation: steam-up 4s ease-in-out infinite;
}
.hero-steam__line:nth-child(1) { left: 30%; animation-delay: 0s; }
.hero-steam__line:nth-child(2) { left: 50%; animation-delay: 1.3s; }
.hero-steam__line:nth-child(3) { left: 70%; animation-delay: 2.6s; }
@keyframes steam-up {
    0%   { opacity: 0; transform: translateY(20px) scale(0.9); }
    50%  { opacity: 0.7; }
    100% { opacity: 0; transform: translateY(-60px) scale(1.3); }
}

/* =========== Layout grid: editorial asymmetric =========== */

.hero-container {
    position: relative;
    z-index: 2;
    height: 100%;
    padding-inline: var(--space-6);
    max-width: var(--container-editorial);
    margin-inline: auto;
}

@media (min-width: 1280px) {
    .hero-container {
        padding-inline: var(--space-7);
    }
}
@media (max-width: 640px) {
    .hero-container {
        padding-inline: var(--space-5);
    }

    .hero-bowl__caption {
        bottom: 11px;
        left: 11px;
        padding: 10px 13px 11px;
        gap: 4px;
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
    }
    .hero-bowl__caption::before {
        width: 22px;
        height: 1.5px;
        margin-bottom: 0;
    }
    .hero-bowl__cat {
        font-size: 10px;
        letter-spacing: 0.12em;
    }
    .hero-bowl__name {
        font-size: clamp(16px, 4.5vw, 20px);
    }
}

.hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
    grid-template-rows: auto 1fr auto;
    grid-template-areas:
        "head     head"
        "food     food"
        "cta      cta";
    gap: var(--space-5);
    min-height: calc(100svh - var(--navbar-height) - var(--space-9));
    align-items: start;
}

.hero-head   { grid-area: head; }
.hero-food   { grid-area: food; }
.hero-cta    { grid-area: cta; justify-self: start; }

@media (min-width: 1024px) {
    .hero-grid {
        grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
        grid-template-rows: 1fr auto;
        grid-template-areas:
            "head     food"
            "cta      food";
        gap: var(--space-7) var(--space-9);
        min-height: calc(100svh - var(--navbar-height) - var(--space-9));
        align-items: center;
    }
    .hero-cta { justify-self: start; align-self: end; }
}

/* =========== L1: Typography =========== */

.hero-head {
    container-type: inline-size;
    color: var(--color-text-inverse);
    text-shadow: 0 2px 16px rgba(0, 0, 0, 0.35);
}

.hero-title {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    font-family: var(--font-display);
    font-weight: var(--weight-black);
    line-height: 1.2;
    letter-spacing: -0.04em;
    color: var(--color-text-inverse);
    margin: var(--space-4) 0 var(--space-5);
    max-width: 100%;
}
.hero-title__line {
    display: block;
    font-size: clamp(28px, 17cqi, 104px);
    white-space: nowrap;
    opacity: 0;
    transform: translateY(24px) scale(0.96);
    animation: line-in 900ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
.hero-title__line--head {
    font-size: clamp(72px, 30cqi, 176px);
    color: var(--color-chili-red);
    -webkit-text-stroke: 2px #FFE6A8;
    paint-order: stroke fill;
}
.hero-title__line--accent {
    color: #FFE6A8; /* warm cream accent over red */
    text-shadow:
        0 2px 0 #6A0A0F,
        0 6px 22px rgba(0, 0, 0, 0.5);
    transform-origin: left center;
    transform: translateY(24px) scale(0.96) skewX(-2deg);
}
.hero-title__line--tail {
    font-size: clamp(24px, 12cqi, 88px);
    opacity: 0.95;
    letter-spacing: -0.03em;
}

@keyframes line-in {
    to { opacity: 1; transform: translateY(0) scale(1) skewX(0); }
}

.hero-sub {
    font-size: clamp(15px, 1.5vw, 18px);
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.95);
    max-width: 36ch;
    margin: 0;
    opacity: 0;
    transform: translateY(12px);
    animation: sub-in 700ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
.hero-sub strong {
    color: #FFE6A8;
    font-weight: var(--weight-bold);
}
.hero-sub em {
    font-style: normal;
    color: var(--color-brand-action-hover);
    font-weight: var(--weight-bold);
}
@keyframes sub-in {
    to { opacity: 1; transform: translateY(0); }
}

/* =========== L2/L3: Food composition =========== */

.hero-food {
    position: relative;
    aspect-ratio: 1 / 1;
    max-height: 70vh;
    min-height: 380px;
    margin: 0 auto;
    width: 100%;
    max-width: 640px;
}

.hero-bowl {
    position: absolute;
    margin: 0;
    filter: drop-shadow(0 24px 36px rgba(0, 0, 0, 0.45));
    will-change: transform, opacity;
}
.hero-bowl__img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 22%;
    /* Slight tilt feels editorial, not stock. */
    transform: rotate(-2deg);
}
.hero-bowl__fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2A0808 0%, #8C0A11 100%);
    color: #FFE6A8;
    font-family: var(--font-display);
    font-weight: var(--weight-black);
    font-size: 48px;
    letter-spacing: -0.04em;
    border-radius: 22%;
    transform: rotate(-2deg);
}

/* Main bowl: largest, slightly off-center */
.hero-bowl--main {
    top: 12%;
    left: 18%;
    width: 64%;
    opacity: 0;
    transform: translateY(40px) scale(0.88);
    animation: bowl-in 1100ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
.hero-bowl--main .hero-bowl__img {
    /* The main bowl's image is the visual anchor — preserve. */
    border-radius: 26%;
}
.hero-bowl--main:hover .hero-bowl__img {
    transform: rotate(-2deg) scale(1.03);
}
@keyframes bowl-in {
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* ===== Premium editorial food label ===== */
.hero-bowl__caption {
    position: absolute;
    bottom: 14px;
    left: 14px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 13px 16px 14px;
    background:
        linear-gradient(
            135deg,
            rgba(18, 18, 18, 0.90),
            rgba(18, 18, 18, 0.62)
        );
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: var(--radius-sm);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.24);
}

/* Accent line */
.hero-bowl__caption::before {
    content: '';
    display: block;
    width: 26px;
    height: 2px;
    margin-bottom: 1px;
    background: var(--color-cream);
    opacity: 0.82;
}

.hero-bowl__cat {
    font-family: var(--font-body);
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.68);
}

.hero-bowl__name {
    font-family: var(--font-display);
    font-size: clamp(19px, 1.7vw, 26px);
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.025em;
    color: #fff;
}

/* Supporting dishes — smaller, slide in from edges */
.hero-bowl--sup {
    opacity: 0;
}
.hero-bowl--sup .hero-bowl__img {
    transform: rotate(var(--rot, 0deg));
}
.hero-bowl--sup-1 {
    top: -2%;
    right: 0;
    width: 36%;
    --rot: 6deg;
    transform: translate(20px, -20px);
    animation: sup-in-1 900ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
@keyframes sup-in-1 {
    to { opacity: 1; transform: translate(0, 0); }
}
.hero-bowl--sup-2 {
    bottom: 4%;
    left: -2%;
    width: 32%;
    --rot: -8deg;
    transform: translate(-20px, 20px);
    animation: sup-in-2 900ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
@keyframes sup-in-2 {
    to { opacity: 1; transform: translate(0, 0); }
}
.hero-bowl--sup-3 {
    top: 56%;
    right: 6%;
    width: 26%;
    --rot: 5deg;
    transform: translate(20px, 0);
    animation: sup-in-3 900ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
@keyframes sup-in-3 {
    to { opacity: 1; transform: translate(0, 0); }
}

/* Subtle idle motion on supporting dishes */
.hero-bowl--sup-1 .hero-bowl__img { animation: idle-1 9s ease-in-out infinite 1.6s; }
.hero-bowl--sup-2 .hero-bowl__img { animation: idle-2 10s ease-in-out infinite 2.2s; }
.hero-bowl--sup-3 .hero-bowl__img { animation: idle-3 8s ease-in-out infinite 1.8s; }
@keyframes idle-1 { 0%,100% { transform: rotate(var(--rot, 6deg)) translateY(0); } 50% { transform: rotate(var(--rot, 6deg)) translateY(-6px); } }
@keyframes idle-2 { 0%,100% { transform: rotate(var(--rot, -8deg)) translateY(0); } 50% { transform: rotate(var(--rot, -8deg)) translateY(-4px); } }
@keyframes idle-3 { 0%,100% { transform: rotate(var(--rot, 5deg)) translateY(0); } 50% { transform: rotate(var(--rot, 5deg)) translateY(-5px); } }

/* =========== L5: CTAs =========== */

.hero-cta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    opacity: 0;
    transform: translateY(8px);
    animation: cta-in 700ms var(--ease-out) forwards;
    animation-delay: var(--delay, 0ms);
}
@keyframes cta-in {
    to { opacity: 1; transform: translateY(0); }
}
.hero-cta__primary {
    /* Surface the CTA visibly on the red field. */
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
}
.hero-cta__secondary :deep(.ui-btn) {
    color: var(--color-text-inverse);
    font-weight: var(--weight-semibold);
}
.hero-cta__secondary :deep(.ui-btn:hover) {
    color: #FFE6A8;
}

/* =========================================================
   RESPONSIVE — tablet / mobile
   ========================================================= */

@media (max-width: 1024px) {
    .hero { min-height: 100svh; }
    .hero-food { max-height: 60vh; }
}

@media (max-width: 768px) {
    .hero {
        padding-bottom: var(--space-7);
    }
    .hero-grid {
        gap: var(--space-4);
    }
    .hero-sub { font-size: 15px; }

    .hero-food {
        max-height: 52vh;
        min-height: 320px;
        max-width: 420px;
    }
    .hero-bowl--sup-3 { display: none; }
    .hero-bowl--sup-1 { width: 32%; }
    .hero-bowl--sup-2 { width: 28%; }

    .hero-cta { gap: var(--space-2); }
    .hero-cta :deep(.ui-btn) {
        padding: 12px 20px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .hero-food { min-height: 260px; max-height: 44vh; max-width: 320px; }
    .hero-bowl--sup-2 { width: 26%; bottom: 0; }
    /* Phase 9 §23: cap to 1 support on narrow mobile. */
    .hero-bowl--sup-1 { display: none; }
}

/* =========================================================
   MOBILE NARROW (<= 420) — Phase 9 ultra-narrow polish
   ========================================================= */
@media (max-width: 420px) {
    .hero {
        min-height: auto;
        padding-block: var(--space-8) var(--space-7);
    }
    .hero-grid {
        gap: var(--space-5);
    }
    .hero-food {
        min-height: 240px;
        max-height: 42vh;
        max-width: 300px;
    }
    .hero-bowl--sup-2 { width: 24%; }
    .hero-sub { font-size: 14px; max-width: 32ch; }
    /* Phase 9 §25-§26: stack CTA, primary full-width, ensure ≥48px touch. */
    .hero-cta {
        flex-direction: column;
        align-items: stretch;
    }
    .hero-cta :deep(.ui-btn) {
        width: 100%;
        min-height: 48px;
        padding: 14px 24px;
        font-size: 15px;
    }
    .hero-bowl--main { left: 12%; }
}

/* =========================================================
   MOBILE MOTION REDUCTION — Phase 8 Correction §1
   On mobile/tablet we cap entrance distance + decorative
   movement amplitude so choreography stays below ~30px and
   background loops are smaller.
   ========================================================= */
@media (max-width: 1023px) {
    .hero-title__line--head,
    .hero-title__line--tail {
        transform: translateY(12px) scale(0.96);
    }
    .hero-title__line--tail {
        transform: translateY(12px) scale(0.96) skewX(-2deg);
    }
    .hero-bowl--main {
        transform: translateY(18px) scale(0.92);
    }
    .hero-bowl--sup-1,
    .hero-bowl--sup-2,
    .hero-bowl--sup-3 {
        opacity: 0.92;
        transform: translateY(14px) scale(0.94) rotate(var(--rot, 0deg));
    }
    /* Decorative loops — quieter amplitude on mobile. */
    @keyframes chili-float {
        0%, 100% { transform: translate(calc(var(--parallax-x, 0) * 6px), calc(var(--parallax-y, 0) * 6px)); }
        50%      { transform: translate(calc(var(--parallax-x, 0) * 6px), calc(var(--parallax-y, 0) * 6px - 5px)); }
    }
    @keyframes sesame-drift {
        0%, 100% { transform: translate(calc(var(--parallax-x, 0) * -4px), calc(var(--parallax-y, 0) * -4px)) rotate(0deg); }
        50%      { transform: translate(calc(var(--parallax-x, 0) * -4px), calc(var(--parallax-y, 0) * -4px)) rotate(2deg); }
    }
    @keyframes idle-1 { 0%,100% { transform: rotate(var(--rot, 6deg)) translateY(0); } 50% { transform: rotate(var(--rot, 6deg)) translateY(-3px); } }
    @keyframes idle-2 { 0%,100% { transform: rotate(var(--rot, -8deg)) translateY(0); } 50% { transform: rotate(var(--rot, -8deg)) translateY(-2px); } }
    @keyframes idle-3 { 0%,100% { transform: rotate(var(--rot, 5deg)) translateY(0); } 50% { transform: rotate(var(--rot, 5deg)) translateY(-3px); } }
}

/* =========================================================
   REDUCED MOTION
   ========================================================= */

@media (prefers-reduced-motion: reduce) {
    .hero {
        min-height: 100svh;
    }

    /* Kill parallax vars — no mouse tracking needed */
    :root,
    .hero-parallax-container {
        --parallax-x: 0 !important;
        --parallax-y: 0 !important;
    }

    .hero-title__line,
    .hero-sub,
    .hero-cta,
    .hero-bowl--main,
    .hero-bowl--sup-1,
    .hero-bowl--sup-2,
    .hero-bowl--sup-3 {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }

    .hero-chili,
    .hero-sesame,
    .hero-steam__line,
    .hero-bowl--sup-1 .hero-bowl__img,
    .hero-bowl--sup-2 .hero-bowl__img,
    .hero-bowl--sup-3 .hero-bowl__img {
        animation: none !important;
    }

    .hero-bowl__img { transform: none !important; }
}
</style>
