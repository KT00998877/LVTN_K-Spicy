<script setup lang="ts">
/**
 * SpicyLevelSection → Interactive Heat Journey (Phase 5)
 *
 * Design goals:
 *  - SIGNATURE INTERACTION — heat reacts to selected level
 *  - Cream → orange → deep red progression via continuous CSS variable
 *    interpolation (no scattered `level === 4` conditionals)
 *  - User-driven only (no autoplay, no scroll-hijack)
 *  - Keyboard accessible (Arrow Left/Right, Home, End) — native
 *    <input type="range"> with custom styling for semantics
 *  - Bowl visual is reactive, NOT a Hero marketing composition
 *  - Reduced motion safe
 *
 * Heat levels: 0–6 (7 stops total) — matches existing menu spicy_level
 * field range and preserves existing implementation behavior (Phase 5 §77).
 *
 * Reuses: UiSection bg="cream", UiContainer, UiEyebrow tone="red",
 *         UiButton variant="primary".
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { imageUrl } from '@/utils/image'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'

const router = useRouter()

// =========================================================
// LOCAL STATE (Phase 5 §55)
// =========================================================
// Phase 5 Correction §1 — restored to 0–7 (8 stops total).
// Heat Journey is a brand/discovery interaction, NOT the business
// product spicy selector (which lives in FoodDetailModal).
const MAX_LEVEL = 7
const selectedLevel = ref(3)

// =========================================================
// LEVEL CONFIG (Phase 5 Correction §1 — restored to 0–7 / 8 stops)
// =========================================================
interface LevelInfo {
    name: string
    description: string
}

const levelInfo: LevelInfo[] = [
    { name: 'Không cay',    description: 'Vị nước dùng chuẩn Hàn, không thêm ớt.' },
    { name: 'Nhẹ nhàng',    description: 'Hương ớt nhẹ, phù hợp người mới.' },
    { name: 'Ấm lên',       description: 'Bắt đầu cảm nhận vị cay ở đầu lưỡi.' },
    { name: 'Vừa đủ',       description: 'Cay vừa, giữ hương vị nước dùng.' },
    { name: 'Bắt đầu nóng', description: 'Cay rõ, ấm cổ, hơi nổ da mặt.' },
    { name: 'Rất cay',      description: 'Cay đậm, cay sâu, hơi thở nóng.' },
    { name: 'Cực cay',      description: 'Cay nặng, chỉ dành cho người quen cay.' },
    { name: 'Thử thách',    description: 'Cay cực đại — bạn dám thử không?' },
]

const currentLevelInfo = computed<LevelInfo>(() => {
    const lvl = selectedLevel.value
    if (lvl < 0 || lvl >= levelInfo.length) return levelInfo[0]!
    return levelInfo[lvl] ?? levelInfo[0]!
})

// =========================================================
// CTA — Phase 5 Correction §2: Menu doesn't support ?spicy=N filter,
// so navigate to /thuc-don without fake query. Heat level remains
// local presentation state.
// =========================================================
const ctaHref = '/thuc-don'
const ctaLabel = computed(() => {
    const lvl = selectedLevel.value
    if (lvl === 0) return 'Chọn món để thử cấp 0'
    return `Chọn món để thử cấp ${lvl}`
})

function tryAtThisLevel() {
    router.push(ctaHref)
}

// =========================================================
// HEAT REACTIVE VISUAL SYSTEM (Phase 5 Correction §6)
//
// Single source-of-truth: heatFactor (0..1). ALL CSS percentage
// derivations are computed in Vue and bound as custom properties.
// CSS reads them directly — no `calc((1 - var(--x)) * 100%)`.
// =========================================================
const heatFactor = computed(() => selectedLevel.value / MAX_LEVEL)

const heatStyle = computed(() => {
    const heat = heatFactor.value
    const hotPct = `${(heat * 100).toFixed(2)}%`
    const coolPct = `${((1 - heat) * 100).toFixed(2)}%`
    return {
        '--heat-progress': heat.toFixed(3),
        '--heat-hot-pct': hotPct,
        '--heat-cool-pct': coolPct,
        '--heat-glow-alpha': (0.08 + heat * 0.32).toFixed(3),
        '--heat-glow-blur': `${(8 + heat * 28).toFixed(0)}px`,
        '--heat-text-shadow': (0.4 + heat * 0.8).toFixed(2),
        '--heat-stripe-opacity': (0.04 + heat * 0.18).toFixed(3),
    }
})

// =========================================================
// BOWL VISUAL (Phase 5 §78 / §79)
// =========================================================
const bowlImage = computed(() => imageUrl('/storage/menu/mi-cay-hai-san.png'))

const imgFailed = ref(false)
const visibleBowlSrc = computed(() => (imgFailed.value ? '' : bowlImage.value))
function onBowlError() { imgFailed.value = true }

// =========================================================
// KEYBOARD (Phase 5 §33) — input range handles Arrow/Home/End natively.
// =========================================================
function onRangeInput(e: Event) {
    const v = Number((e.target as HTMLInputElement).value)
    if (Number.isFinite(v) && v >= 0 && v <= MAX_LEVEL) {
        selectedLevel.value = Math.round(v)
    }
}
</script>

<template>
    <UiSection
        id="heat"
        bg="cream"
        padding="default"
        aria-labelledby="heat-title"
    >
        <div class="heat-section" :style="heatStyle">
            <UiContainer>
                <header class="heat-head">
                    <UiEyebrow tone="red">Câu chuyện cay</UiEyebrow>
                    <h2 id="heat-title" class="heat-title">
                        Bạn chịu cay đến <span class="heat-title-accent">đâu?</span>
                    </h2>
                    <p class="heat-desc">
                        Mỗi cấp độ là một trải nghiệm khác biệt.
                        Kéo để cảm nhận độ cay phù hợp với bạn.
                    </p>
                </header>

                <div class="heat-layout">

                    <!-- ===== LEFT — reactive bowl ===== -->
                    <div class="heat-visual">
                        <!-- Reactive heat layer behind bowl -->
                        <div class="heat-glow" aria-hidden="true"></div>
                        <div class="heat-stripes" aria-hidden="true"></div>

                        <div class="visual-frame">
                            <img
                                v-if="visibleBowlSrc"
                                :src="visibleBowlSrc"
                                alt="Tô mì cay Hàn Quốc"
                                class="heat-bowl"
                                loading="lazy"
                                decoding="async"
                                width="600"
                                height="600"
                                @error="onBowlError"
                            />
                            <div v-else class="visual-fallback" aria-hidden="true">
                                <span>Bowl</span>
                            </div>
                        </div>

                        <!-- Active level badge -->
                        <div class="visual-badge">
                            <span class="badge-level">{{ selectedLevel }}</span>
                            <span class="badge-label">{{ currentLevelInfo.name }}</span>
                        </div>

                        <!-- Subtle heat distortion overlay (max 6 = 60% level) -->
                        <div class="heat-distort" aria-hidden="true"></div>
                    </div>

                    <!-- ===== RIGHT — interactive controls + live value ===== -->
                    <div class="heat-controls">

                        <!-- Live value (Phase 5 §34) -->
                        <div
                            class="heat-value"
                            role="status"
                            aria-live="polite"
                        >
                            <span class="heat-value-label">Cấp cay hiện tại</span>
                            <strong class="heat-value-num">
                                {{ selectedLevel }} <span class="heat-value-sep">/</span> {{ MAX_LEVEL }}
                            </strong>
                            <span class="heat-value-name">
                                {{ currentLevelInfo.name }}
                            </span>
                            <p class="heat-value-desc">
                                {{ currentLevelInfo.description }}
                            </p>
                        </div>

                        <!-- Slider (Phase 5 §32 — semantic input[type=range]) -->
                        <div class="heat-slider-wrap">
                            <label for="heat-slider" class="heat-slider-label">
                                Kéo để chọn cấp cay
                            </label>
                            <input
                                id="heat-slider"
                                type="range"
                                :min="0"
                                :max="MAX_LEVEL"
                                :step="1"
                                :value="selectedLevel"
                                class="heat-slider"
                                aria-valuemin="0"
                                aria-valuemax="7"
                                :aria-valuenow="selectedLevel"
                                :aria-valuetext="`Cấp ${selectedLevel} / ${MAX_LEVEL} — ${currentLevelInfo.name}`"
                                @input="onRangeInput"
                            />
                            <!-- Visual track fill -->
                            <div class="heat-track" aria-hidden="true">
                                <div
                                    class="heat-track-fill"
                                    :style="{ width: heatStyle['--heat-hot-pct'] }"
                                ></div>
                            </div>
                            <!-- Tick markers (8 stops: 0..7) -->
                            <ul class="heat-ticks" aria-hidden="true">
                                <li
                                    v-for="lvl in 8"
                                    :key="lvl"
                                    :class="{
                                        'is-active': selectedLevel >= (lvl - 1),
                                        'is-current': selectedLevel === (lvl - 1),
                                    }"
                                ></li>
                            </ul>
                            <div class="heat-tick-labels" aria-hidden="true">
                                <span
                                    v-for="lvl in 8"
                                    :key="`l-${lvl}`"
                                    :class="{ 'is-current': selectedLevel === (lvl - 1) }"
                                >{{ lvl - 1 }}</span>
                            </div>
                        </div>

                        <!-- CTA (Phase 5 Correction §2: /thuc-don without fake spicy query) -->
                        <div class="heat-cta">
                            <UiButton
                                as="router-link"
                                :to="ctaHref"
                                variant="primary"
                                size="lg"
                            >
                                {{ ctaLabel }}
                                <svg width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor"
                                    stroke-width="2.5"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                            </UiButton>
                            <p class="heat-cta-note">
                                Tùy chọn cay thực tế vẫn nằm trong trang chi tiết món.
                            </p>
                        </div>
                    </div>
                </div>
            </UiContainer>
        </div>
    </UiSection>
</template>

<style scoped>
/* =========================================================
   INTERACTIVE HEAT JOURNEY — Phase 5
   Reactive visual system driven by --heat-progress (0..1).
   ========================================================= */

.heat-section {
    position: relative;
    overflow: hidden;
    /* Default fallback (overridden by Vue bindings). */
    --heat-progress: 0.43;
    --heat-hot-pct: 43%;
    --heat-cool-pct: 57%;
    --heat-glow-alpha: 0.18;
    --heat-glow-blur: 18px;
    --heat-text-shadow: 0.6;
    --heat-stripe-opacity: 0.10;

    /* Background interpolates cream → warm orange → deep red.
       Uses CSS color-mix for smooth interpolation from a small set
       of foundation tokens. */
    /* Background interpolates cream → warm orange → deep red.
       Heat percentages are computed in Vue and passed directly
       (Phase 5 Correction §6) — no `calc((1 - var(--x)) * 100%)`. */
    background:
        radial-gradient(
            ellipse at 50% 30%,
            color-mix(
                in oklab,
                var(--color-cream) var(--heat-cool-pct),
                var(--color-chili-red) var(--heat-hot-pct)
            ) 0%,
            transparent 65%
        ),
        var(--color-cream);
    transition: background var(--dur-slow) var(--ease-out);
}

/* ===== Header ===== */
.heat-head {
    text-align: center;
    max-width: 720px;
    margin: 0 auto var(--space-10);
    position: relative;
    z-index: 1;
}

.heat-title {
    font-family: var(--font-display);
    font-size: clamp(36px, 4.5vw, 64px);
    font-weight: var(--weight-extrabold);
    line-height: 1.05;
    letter-spacing: -0.035em;
    margin: var(--space-3) 0 var(--space-5);
    color: var(--color-charcoal-black);
    /* Subtle reaction: text shadow intensifies with heat. */
    text-shadow: 0 0 calc(var(--heat-text-shadow) * 8px)
        color-mix(in oklab, transparent var(--heat-cool-pct),
            var(--color-chili-red) var(--heat-hot-pct));
    transition: text-shadow 300ms var(--ease-out);
}

.heat-title-accent {
    color: var(--color-chili-red);
    font-style: italic;
}

.heat-desc {
    font-size: 16px;
    color: var(--color-gray-600);
    line-height: 1.65;
    max-width: 560px;
    margin: 0 auto;
}

/* ===== Layout ===== */
.heat-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-11);
    align-items: center;
    position: relative;
    z-index: 1;
}

/* ===== Bowl visual ===== */
.heat-visual {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 480px;
}

/* Reactive heat glow behind bowl */
.heat-glow {
    position: absolute;
    inset: 10%;
    background: radial-gradient(
        circle,
        rgba(255, 60, 30, var(--heat-glow-alpha)) 0%,
        transparent 60%
    );
    filter: blur(var(--heat-glow-blur));
    pointer-events: none;
    transition:
        opacity 300ms var(--ease-out),
        filter 300ms var(--ease-out);
}

/* Faint heat stripes that fade in with level */
.heat-stripes {
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        45deg,
        rgba(215, 25, 32, calc(var(--heat-stripe-opacity) * 0.6)) 0px,
        rgba(215, 25, 32, calc(var(--heat-stripe-opacity) * 0.6)) 2px,
        transparent 2px,
        transparent 14px
    );
    pointer-events: none;
    mix-blend-mode: multiply;
    opacity: var(--heat-progress);
    transition: opacity 300ms var(--ease-out);
}

.visual-frame {
    position: relative;
    width: 100%;
    max-width: 460px;
    aspect-ratio: 1;
    border-radius: 50%;
    overflow: hidden;
    background: var(--color-cream-deep);
    box-shadow:
        0 24px 56px rgba(17, 17, 17, 0.12),
        inset 0 0 0 calc(2px + var(--heat-progress) * 4px)
            color-mix(in oklab,
                var(--color-cream) var(--heat-cool-pct),
                var(--color-spicy-4) var(--heat-hot-pct));
    transition: box-shadow var(--dur-slow) var(--ease-out);
    z-index: 1;
}

.heat-bowl {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* Subtle warm-tone overlay baked via filter — increases with heat. */
    filter:
        saturate(calc(1 + var(--heat-progress) * 0.5))
        contrast(calc(1 + var(--heat-progress) * 0.15));
    transition: filter 300ms var(--ease-out);
}

.visual-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #8C0A11 0%, #2A0808 100%);
    color: var(--color-cream);
    font-family: var(--font-display);
    font-size: clamp(36px, 6vw, 72px);
    font-weight: var(--weight-black);
    letter-spacing: -0.03em;
}

/* Subtle heat distortion — purely CSS, no JS calculation */
.heat-distort {
    position: absolute;
    inset: 0;
    pointer-events: none;
    opacity: var(--heat-progress);
    background:
        radial-gradient(ellipse 60% 30% at 50% 0%,
            rgba(255, 220, 130, 0.15) 0%,
            transparent 70%),
        radial-gradient(ellipse 80% 20% at 50% 100%,
            rgba(215, 25, 32, 0.18) 0%,
            transparent 70%);
    mix-blend-mode: screen;
    transition: opacity 300ms var(--ease-out);
}

/* Active level badge over bowl */
.visual-badge {
    position: absolute;
    top: var(--space-4);
    left: var(--space-4);
    z-index: 2;
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 10px 18px 10px 10px;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    color: var(--color-white);
    background:
        color-mix(in oklab,
            var(--color-cream) var(--heat-cool-pct),
            var(--color-spicy-4) var(--heat-hot-pct));
    transition: background var(--dur-slow) var(--ease-out);
}

.badge-level {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    background: rgba(255, 255, 255, 0.25);
    color: var(--color-white);
    font-weight: var(--weight-extrabold);
    font-size: 14px;
    border-radius: 50%;
    font-variant-numeric: tabular-nums;
}

.badge-label {
    font-size: 13px;
    font-weight: var(--weight-extrabold);
    letter-spacing: 0.02em;
}

/* ===== Controls (right) ===== */
.heat-controls {
    display: flex;
    flex-direction: column;
    gap: var(--space-7);
    max-width: 560px;
}

/* Live value display */
.heat-value {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.heat-value-label {
    font-size: 12px;
    font-weight: var(--weight-extrabold);
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.heat-value-num {
    font-family: var(--font-display);
    font-size: clamp(40px, 5vw, 64px);
    font-weight: var(--weight-black);
    line-height: 1;
    letter-spacing: -0.04em;
    color: var(--color-charcoal-black);
    font-variant-numeric: tabular-nums;
    /* Color interpolates with heat. */
    color:
        color-mix(in oklab,
            var(--color-charcoal-black) var(--heat-cool-pct),
            var(--color-spicy-4) var(--heat-hot-pct));
    transition: color 300ms var(--ease-out);
}

.heat-value-sep {
    color: var(--color-gray-600);
    font-weight: var(--weight-bold);
    margin: 0 6px;
}

.heat-value-name {
    font-size: 18px;
    font-weight: var(--weight-extrabold);
    letter-spacing: -0.01em;
    color:
        color-mix(in oklab,
            var(--color-gray-700) var(--heat-cool-pct),
            var(--color-spicy-3) var(--heat-hot-pct));
    transition: color 300ms var(--ease-out);
}

.heat-value-desc {
    font-size: 15px;
    line-height: 1.6;
    color: var(--color-gray-600);
    margin: var(--space-2) 0 0;
}

/* Slider */
.heat-slider-wrap {
    position: relative;
}

.heat-slider-label {
    display: block;
    font-size: 12px;
    font-weight: var(--weight-extrabold);
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.16em;
    margin-bottom: var(--space-3);
}

/* Native range — but visually hidden; we style .heat-track separately. */
.heat-slider {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 56px;
    margin: 0;
    opacity: 0;
    cursor: pointer;
    z-index: 2;
}
.heat-slider:focus-visible + .heat-track {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 6px;
}

/* Custom visual track */
.heat-track {
    position: relative;
    height: 56px;
    background: var(--color-gray-200);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.heat-track-fill {
    height: 100%;
    background: linear-gradient(
        90deg,
        var(--color-spicy-1) 0%,
        var(--color-spicy-2) 33%,
        var(--color-spicy-3) 66%,
        var(--color-spicy-4) 100%
    );
    border-radius: var(--radius-sm);
    transition: width 300ms var(--ease-out);
}

/* Tick markers */
.heat-ticks {
    position: relative;
    display: flex;
    justify-content: space-between;
    list-style: none;
    margin: var(--space-2) 0 0;
    padding: 0;
    pointer-events: none;
}

.heat-ticks li {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--color-gray-300);
    transition: background-color var(--dur-fast) var(--ease-out);
}

.heat-ticks li.is-active {
    background:
        color-mix(in oklab,
            var(--color-spicy-2) 60%,
            var(--color-spicy-4) 40%);
}
.heat-ticks li.is-current {
    background: var(--color-spicy-4);
    box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.6),
                0 0 0 5px var(--color-spicy-4);
    transform: scale(1.2);
}

.heat-tick-labels {
    display: flex;
    justify-content: space-between;
    margin-top: var(--space-2);
    padding: 0 4px;
    pointer-events: none;
}

.heat-tick-labels span {
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: var(--weight-bold);
    color: var(--color-gray-600);
    width: 14px;
    text-align: center;
    font-variant-numeric: tabular-nums;
    transition: color var(--dur-fast) var(--ease-out);
}
.heat-tick-labels span.is-current {
    color: var(--color-charcoal-black);
    font-weight: var(--weight-extrabold);
}

/* CTA */
.heat-cta {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.heat-cta :deep(.ui-btn) {
    box-shadow: var(--shadow-md);
}

.heat-cta-note {
    font-size: 12px;
    color: var(--color-gray-500);
    line-height: 1.55;
    margin: 0;
    font-style: italic;
}

/* =========================================================
   Tablet (1024) — stack visual above controls
   ========================================================= */
@media (max-width: 1024px) {
    .heat-layout {
        grid-template-columns: 1fr;
        gap: var(--space-9);
    }
    .heat-controls { max-width: 100%; }
    .heat-visual { min-height: 380px; }
    .visual-frame { max-width: 360px; margin: 0 auto; }
}

/* =========================================================
   Mobile (640) — reduce decorative density
   ========================================================= */
@media (max-width: 640px) {
    .heat-section {
        padding-block: var(--space-9);
    }
    .heat-head {
        margin-bottom: var(--space-8);
    }
    .heat-visual {
        min-height: 320px;
    }
    .visual-frame {
        max-width: 280px;
    }
    .visual-badge {
        top: var(--space-3);
        left: var(--space-3);
        padding: 8px 14px 8px 8px;
    }
    .badge-level {
        width: 26px;
        height: 26px;
        font-size: 13px;
    }
    .badge-label {
        font-size: 12px;
    }
    /* Reduce stripes and distortion on mobile — perf */
    .heat-stripes {
        display: none;
    }
    .heat-distort {
        opacity: calc(var(--heat-progress) * 0.5);
    }
    /* Slider tap target ≥ 44px */
    .heat-slider {
        height: 48px;
    }
    .heat-track { height: 48px; }
    .heat-cta :deep(.ui-btn) {
        width: 100%;
        justify-content: center;
    }
}

/* =========================================================
   Reduced motion
   ========================================================= */
@media (prefers-reduced-motion: reduce) {
    .heat-section,
    .heat-glow,
    .heat-stripes,
    .heat-bowl,
    .heat-distort,
    .heat-value-num,
    .heat-value-name,
    .heat-track-fill,
    .heat-ticks li,
    .heat-tick-labels span,
    .heat-title {
        transition: none !important;
    }
}

/* =========================================================
   MOBILE MOTION REDUCTION — Phase 8 Correction §1
   On tablet/mobile we reduce filter cost during slider drag.
   The heat glow alpha/blur are still driven by Vue, but the
   applied filter chain is lighter so redraws stay cheap.
   ========================================================= */
@media (max-width: 1023px) {
    .heat-bowl-img {
        filter:
            saturate(calc(1 + var(--heat-progress) * 0.30))
            contrast(calc(1 + var(--heat-progress) * 0.10));
    }
    .heat-distort {
        filter: blur(2px) !important;
    }
}
</style>
