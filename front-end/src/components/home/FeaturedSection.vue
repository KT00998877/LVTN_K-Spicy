<script setup lang="ts">
/**
 * FeaturedSection → Cinematic Product Stage (Phase 4)
 *
 * Phase 4 §24-§95 — single-product spotlight, user-controlled, no autoplay.
 *
 * Design goals:
 *  - ONE PRODUCT AT A TIME — 3-4 curated products max
 *  - Deep charcoal surface (rhythm: red Hero → cream Category → charcoal Product)
 *  - Photography dominates (45-60% viewport visual weight)
 *  - User-controlled transitions: arrows + index + drag swipe
 *  - Reuse cartStore.openAddModal (existing FoodDetailModal flow)
 *
 * Selection: món được quản lý bật cờ “đặc sắc”, ưu tiên món còn bán, tối đa 4 món.
 *
 * Reuses: useHomeStore, useCartStore, UiContainer, UiEyebrow tone="gold",
 *         UiButton variant="brand"/outline, UiBadge, UiReveal.
 */
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useHomeStore } from '@/stores/home'
import { useCartStore } from '@/stores/cart'
import { imageUrl } from '@/utils/image'
import type { MenuItemCard } from '@/types/home'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiReveal from '@/components/ui/UiReveal.vue'

const store = useHomeStore()
const cartStore = useCartStore()

// =========================================================
// PRODUCT SELECTION (Phase 4 §26 + Correction: sold-out strategy)
//
// Selection order: featured available → featured unavailable → cap at 4.
//
// Result: product stage may render sold-out items when the available
// pool is short. UI handles this via .stage-soldout overlay + disabled CTA.
// =========================================================
function isAvailable(item: MenuItemCard): boolean {
    return item.is_available !== false
}

const stageProducts = computed<MenuItemCard[]>(() => {
    const featured = store.featured ?? []
    return [
        ...featured.filter(isAvailable),
        ...featured.filter(item => !isAvailable(item)),
    ].slice(0, 4)
})

const isStageEmpty = computed(() => stageProducts.value.length === 0)
const isLoading = computed(() => store.loading && !stageProducts.value.length)

// =========================================================
// ACTIVE PRODUCT STATE (local — Phase 4 §44 / §81)
// =========================================================
const activeIndex = ref(0)
const activeProduct = computed<MenuItemCard | null>(
    () => stageProducts.value[activeIndex.value] ?? null,
)
const totalCount = computed(() => stageProducts.value.length)

function go(delta: number) {
    if (!totalCount.value) return
    const next = (activeIndex.value + delta + totalCount.value) % totalCount.value
    activeIndex.value = next
}
function goTo(i: number) {
    if (i >= 0 && i < totalCount.value) activeIndex.value = i
}

function prev() { go(-1) }
function next() { go(1) }

// =========================================================
// CART INTEGRATION (Phase 4 §45-§48 / §82)
// =========================================================
function handleOrder() {
    const p = activeProduct.value
    if (!p) return
    if (!isAvailable(p)) return
    cartStore.openAddModal({
        id: p.id,
        name: p.name,
        price: p.price,
        price_formatted: p.price_formatted,
        default_spicy: p.spicy_level ?? 2,
        image_url: p.image_url,
    })
}
function handleDetail() {
    // Same modal flow — FoodDetailModal covers full detail + options.
    handleOrder()
}

// =========================================================
// IMAGE SOURCE + ERROR
// =========================================================
const FALLBACK_IMG = '/storage/menu/mi-cay-hai-san.png'
function foodSrc(item: MenuItemCard | null): string {
    if (!item) return FALLBACK_IMG
    return imageUrl(item.image_url) || FALLBACK_IMG
}
const imgError = ref(false)
function onImgError() { imgError.value = true }

// =========================================================
// POINTER DRAG (Phase 4 §58-§59)
// =========================================================
const stageEl = ref<HTMLElement | null>(null)
let dragStartX = 0
let dragCurrentX = 0
let dragging = false
const DRAG_THRESHOLD = 60

function onPointerDown(e: PointerEvent) {
    if (e.pointerType === 'mouse' && e.button !== 0) return
    dragging = true
    dragStartX = e.clientX
    dragCurrentX = e.clientX
}
function onPointerMove(e: PointerEvent) {
    if (!dragging) return
    dragCurrentX = e.clientX
}
function onPointerUp() {
    if (!dragging) return
    dragging = false
    const dx = dragCurrentX - dragStartX
    if (Math.abs(dx) >= DRAG_THRESHOLD) {
        if (dx < 0) next()
        else prev()
    }
}

// =========================================================
// KEYBOARD (Phase 4 §61)
// =========================================================
function onStageKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowLeft')  { e.preventDefault(); prev() }
    if (e.key === 'ArrowRight') { e.preventDefault(); next() }
}

onMounted(() => {
    if (!stageEl.value) return
    stageEl.value.addEventListener('keydown', onStageKeydown)
})
onBeforeUnmount(() => {
    if (!stageEl.value) return
    stageEl.value.removeEventListener('keydown', onStageKeydown)
})
</script>

<template>
    <section
        id="featured"
        ref="stageEl"
        class="stage"
        aria-labelledby="stage-title"
        tabindex="0"
    >
        <UiContainer>
            <UiReveal>
                <header class="stage-head">
                    <UiEyebrow tone="gold">Best seller</UiEyebrow>
                    <h2 id="stage-title" class="stage-title">Món đáng thử nhất tuần này</h2>
                    <p class="stage-sub">
                        Một món mỗi lần — chọn từ 4 lựa chọn dưới đây.
                    </p>
                </header>
            </UiReveal>

            <!-- Loading -->
            <div v-if="isLoading" class="stage-skel" aria-hidden="true">
                <div class="stage-skel-img"></div>
                <div class="stage-skel-info">
                    <div class="stage-skel-line stage-skel-line-lg"></div>
                    <div class="stage-skel-line"></div>
                    <div class="stage-skel-line stage-skel-line-sm"></div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="isStageEmpty" class="stage-empty">
                <p>Chưa có món best-seller tuần này.</p>
                <UiButton variant="brand" as="router-link" to="/thuc-don">
                    Xem thực đơn
                </UiButton>
            </div>

            <!-- Stage -->
            <div
                v-else
                class="stage-grid"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @pointerleave="onPointerUp"
            >

                <!-- LEFT / TOP: massive food photo -->
                <div
                    :key="activeProduct?.id ?? 'empty'"
                    class="stage-image-wrap"
                >
                    <img
                        v-if="!imgError"
                        :src="foodSrc(activeProduct)"
                        :alt="activeProduct ? `Món ${activeProduct.name}` : 'Món ăn'"
                        class="stage-image"
                        :loading="activeIndex === 0 ? 'eager' : 'lazy'"
                        decoding="async"
                        width="1200"
                        height="1200"
                        @error="onImgError"
                    />
                    <div v-else class="stage-image-fallback" aria-hidden="true">
                        <span>K-SPICY</span>
                    </div>
                    <div class="stage-image-spot" aria-hidden="true"></div>

                    <!-- Best seller stamp on current product -->
                    <span
                        v-if="activeProduct"
                        class="stage-stamp"
                    >
                        <UiBadge tone="red" variant="solid" size="sm">
                            <svg width="11" height="11" viewBox="0 0 24 24"
                                fill="currentColor" aria-hidden="true">
                                <path d="M12 2l2.39 7.36H22l-6.18 4.5L18.21 22 12 17.27 5.79 22l2.39-8.14L2 9.36h7.61z"/>
                            </svg>
                            Best seller
                        </UiBadge>
                    </span>

                    <!-- Sold-out overlay -->
                    <div
                        v-if="activeProduct && !isAvailable(activeProduct)"
                        class="stage-soldout"
                        aria-label="Món tạm hết"
                    >
                        <span>TẠM HẾT</span>
                    </div>
                </div>

                <!-- RIGHT / BOTTOM: product info + CTAs -->
                <div class="stage-info">
                    <Transition name="stage-info" mode="out-in">
                        <div
                            :key="activeProduct?.id ?? 'empty'"
                            class="stage-info-inner"
                        >
                            <span
                                v-if="activeProduct?.category_name"
                                class="stage-cat"
                            >{{ activeProduct.category_name }}</span>

                            <h3 class="stage-name">
                                {{ activeProduct?.name ?? '—' }}
                            </h3>

                            <div
                                v-if="activeProduct"
                                class="stage-spicy"
                                :aria-label="`Cấp cay ${activeProduct.spicy_level}/5`"
                            >
                                <span class="stage-spicy-dots" aria-hidden="true">
                                    <span
                                        v-for="n in 5"
                                        :key="n"
                                        class="stage-spicy-dot"
                                        :class="{ 'is-on': n <= (activeProduct?.spicy_level ?? 0) }"
                                    ></span>
                                </span>
                                <span class="stage-spicy-label">
                                    Cấp cay {{ activeProduct.spicy_level }}/5
                                </span>
                            </div>

                            <p
                                v-if="activeProduct?.description"
                                class="stage-desc"
                            >{{ activeProduct.description }}</p>

                            <div class="stage-price-row">
                                <span class="stage-price">
                                    {{ activeProduct?.price_formatted ?? '' }}
                                </span>
                                <span
                                    v-if="activeProduct?.preparation_minutes"
                                    class="stage-prep"
                                >
                                    <svg width="13" height="13" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor"
                                        stroke-width="2.2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <circle cx="12" cy="12" r="9"/>
                                        <path d="M12 7v5l3 2"/>
                                    </svg>
                                    {{ activeProduct.preparation_minutes }} phút
                                </span>
                            </div>

                            <div class="stage-cta">
                                <UiButton
                                    variant="brand"
                                    size="lg"
                                    :disabled="!activeProduct || !isAvailable(activeProduct)"
                                    @click="handleOrder"
                                >
                                    Đặt món
                                    <svg width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor"
                                        stroke-width="2.4"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <polyline points="9 18 15 12 9 6"/>
                                    </svg>
                                </UiButton>
                                <UiButton
                                    variant="outline"
                                    size="lg"
                                    :disabled="!activeProduct"
                                    @click="handleDetail"
                                    class="stage-cta-secondary"
                                >
                                    Xem chi tiết
                                </UiButton>
                            </div>
                        </div>
                    </Transition>

                    <!-- Controls — index + arrows -->
                    <div class="stage-controls">
                        <span class="stage-index" aria-live="polite">
                            {{ String(activeIndex + 1).padStart(2, '0') }}
                            <span class="stage-index-sep">/</span>
                            {{ String(totalCount).padStart(2, '0') }}
                        </span>

                        <div class="stage-arrows">
                            <button
                                type="button"
                                class="stage-arrow"
                                aria-label="Món trước"
                                :disabled="totalCount < 2"
                                @click="prev"
                            >
                                <svg width="20" height="20" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor"
                                    stroke-width="2.4"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <polyline points="15 18 9 12 15 6"/>
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="stage-arrow"
                                aria-label="Món tiếp theo"
                                :disabled="totalCount < 2"
                                @click="next"
                            >
                                <svg width="20" height="20" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor"
                                    stroke-width="2.4"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Mini pagination — semantic buttons, no tablist semantics -->
                    <div
                        v-if="totalCount > 1"
                        class="stage-pager"
                        aria-label="Chọn món"
                    >
                        <button
                            v-for="(p, i) in stageProducts"
                            :key="p.id"
                            type="button"
                            class="stage-pager-dot"
                            :class="{ 'is-active': i === activeIndex }"
                            :aria-label="`Hiển thị món ${p.name}`"
                            :aria-current="i === activeIndex ? 'true' : undefined"
                            @click="goTo(i)"
                        ></button>
                    </div>
                </div>
            </div>

            <!-- Section footer CTA -->
            <div class="stage-foot">
                <UiButton
                    variant="white"
                    as="router-link"
                    to="/mon-dac-sac"
                >
                    Khám phá món đặc sắc
                    <svg width="16" height="16" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2.4"
                        stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </UiButton>
            </div>
        </UiContainer>
    </section>
</template>

<style scoped>
/* =========================================================
   CINEMATIC PRODUCT STAGE — deep charcoal (Phase 4)
   ONE product spotlight at a time. Photography dominates.
   ========================================================= */

.stage {
    background: var(--color-charcoal);
    color: var(--color-text-inverse);
    padding-block: var(--space-editorial-xl);
    position: relative;
    overflow: hidden;
}
@media (max-width: 1023px) {
    .stage { padding-block: var(--space-editorial-lg); }
}
@media (max-width: 640px) {
    .stage { padding-block: var(--space-9); }
}

/* Subtle bottom-of-stage radial glow to soften edge. */
.stage::after {
    content: '';
    position: absolute;
    left: 0; right: 0; bottom: -1px;
    height: 120px;
    background: linear-gradient(
        180deg,
        rgba(17, 17, 17, 0) 0%,
        rgba(241, 160, 40, 0.06) 100%
    );
    pointer-events: none;
}

/* ===== Head ===== */
.stage-head {
    margin-bottom: var(--space-9);
    max-width: 720px;
}
.stage-title {
    font-family: var(--font-display);
    font-size: clamp(36px, 5vw, 72px);
    font-weight: var(--weight-extrabold);
    line-height: 0.95;
    letter-spacing: -0.035em;
    color: var(--color-text-inverse);
    margin: var(--space-3) 0 var(--space-3);
}
.stage-sub {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.65);
    margin: 0;
    line-height: 1.6;
}

/* ===== Stage grid: photo + info ===== */
.stage-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: var(--space-9);
    align-items: center;
    touch-action: pan-y;
    cursor: grab;
}
.stage-grid:active { cursor: grabbing; }

@media (max-width: 1023px) {
    .stage-grid {
        grid-template-columns: 1fr;
        gap: var(--space-7);
    }
}

/* ===== Image ===== */
.stage-image-wrap {
    position: relative;
    aspect-ratio: 4 / 5;
    overflow: hidden;
    background: var(--color-charcoal-light);
    border-radius: var(--radius-sm);
    animation: stage-image-in 600ms var(--ease-out);
}
@keyframes stage-image-in {
    from { opacity: 0; transform: scale(0.96); }
    to   { opacity: 1; transform: scale(1); }
}

.stage-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 700ms var(--ease-out);
}
.stage-image-wrap:hover .stage-image { transform: scale(1.03); }

.stage-image-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2A0808 0%, #8C0A11 100%);
    color: #FFE6A8;
    font-family: var(--font-display);
    font-weight: var(--weight-black);
    font-size: clamp(48px, 8vw, 88px);
    letter-spacing: -0.04em;
}

.stage-image-spot {
    position: absolute;
    inset: 0;
    background: radial-gradient(
        circle at 30% 25%,
        rgba(255, 220, 130, 0.18) 0%,
        transparent 55%
    );
    pointer-events: none;
}

.stage-stamp {
    position: absolute;
    top: var(--space-5);
    left: var(--space-5);
    z-index: 2;
}

.stage-soldout {
    position: absolute;
    inset: 0;
    background: rgba(17, 17, 17, 0.65);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
}
.stage-soldout span {
    padding: 14px 28px;
    background: var(--color-cream);
    color: var(--color-charcoal-black);
    font-family: var(--font-display);
    font-size: clamp(20px, 3vw, 32px);
    font-weight: var(--weight-black);
    letter-spacing: 0.06em;
    border-radius: var(--radius-sm);
}

/* ===== Info column ===== */
.stage-info {
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
    min-width: 0;
    max-width: 480px;
}
@media (max-width: 1023px) {
    .stage-info { max-width: none; }
}

.stage-info-inner {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.stage-cat {
    font-size: 11px;
    font-weight: var(--weight-extrabold);
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--color-golden);
}

.stage-name {
    font-family: var(--font-display);
    font-size: clamp(40px, 5vw, 72px);
    font-weight: var(--weight-extrabold);
    line-height: 1.02;
    letter-spacing: -0.035em;
    color: var(--color-text-inverse);
    margin: 0;
}

/* Spicy level — accessible dots */
.stage-spicy {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}
.stage-spicy-dots {
    display: inline-flex;
    gap: 4px;
}
.stage-spicy-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.18);
    transition: background-color var(--dur-fast) var(--ease-out);
}
.stage-spicy-dot.is-on {
    background: var(--color-chili-red);
    box-shadow: 0 0 8px rgba(215, 25, 32, 0.4);
}
.stage-spicy-label {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.7);
    letter-spacing: 0.02em;
}

.stage-desc {
    font-size: 15px;
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.75);
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.stage-price-row {
    display: flex;
    align-items: baseline;
    gap: var(--space-4);
    margin-top: var(--space-2);
}
.stage-price {
    font-family: var(--font-ui);
    font-size: clamp(28px, 3vw, 36px);
    font-weight: var(--weight-black);
    color: var(--color-cream);
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.stage-prep {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.5);
    letter-spacing: 0.02em;
}

.stage-cta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: var(--space-3);
}
.stage-cta-secondary :deep(.ui-btn) {
    color: var(--color-cream);
    border-color: rgba(255, 255, 255, 0.4);
}
.stage-cta-secondary :deep(.ui-btn:hover) {
    border-color: var(--color-cream);
    background: rgba(255, 255, 255, 0.06);
}

/* ===== Controls ===== */
.stage-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-top: var(--space-3);
}
.stage-index {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: var(--weight-bold);
    color: var(--color-text-inverse);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.04em;
}
.stage-index-sep {
    color: rgba(255, 255, 255, 0.35);
    margin: 0 4px;
}

.stage-arrows {
    display: flex;
    gap: var(--space-2);
}
.stage-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: transparent;
    border: 1.5px solid rgba(255, 255, 255, 0.30);
    border-radius: 50%;
    color: var(--color-cream);
    cursor: pointer;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        border-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out);
}
.stage-arrow:hover:not(:disabled) {
    background: var(--color-cream);
    color: var(--color-charcoal);
    border-color: var(--color-cream);
}
.stage-arrow:focus-visible {
    outline: 2px solid var(--color-cream);
    outline-offset: 4px;
}
.stage-arrow:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Mini pager dots */
.stage-pager {
    display: flex;
    gap: var(--space-2);
    margin-top: var(--space-2);
}
.stage-pager-dot {
    width: 36px;
    height: 4px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    padding: 0;
    transition: background-color var(--dur-fast) var(--ease-out);
}
.stage-pager-dot:hover {
    background: rgba(255, 255, 255, 0.35);
}
.stage-pager-dot.is-active {
    background: var(--color-cream);
}

/* ===== Footer CTA ===== */
.stage-foot {
    display: flex;
    justify-content: center;
    margin-top: var(--space-9);
}

/* ===== Skeleton ===== */
.stage-skel {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: var(--space-9);
}
@media (max-width: 1023px) {
    .stage-skel { grid-template-columns: 1fr; }
}
.stage-skel-img {
    aspect-ratio: 4/5;
    background: rgba(255, 255, 255, 0.04);
    border-radius: var(--radius-sm);
    animation: stage-skel-pulse 1.5s ease-in-out infinite;
}
.stage-skel-info {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    align-self: center;
}
.stage-skel-line {
    height: 18px;
    background: rgba(255, 255, 255, 0.04);
    border-radius: var(--radius-sm);
    animation: stage-skel-pulse 1.5s ease-in-out infinite;
}
.stage-skel-line-lg { height: 36px; width: 80%; }
.stage-skel-line-sm { width: 50%; }
@keyframes stage-skel-pulse {
    0%, 100% { opacity: 0.4; }
    50%      { opacity: 1; }
}

/* ===== Empty ===== */
.stage-empty {
    text-align: center;
    padding: var(--space-10) var(--space-6);
    color: rgba(255, 255, 255, 0.6);
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    align-items: center;
}

/* ===== Info transition ===== */
.stage-info-enter-active,
.stage-info-leave-active {
    transition:
        opacity 500ms var(--ease-out),
        transform 500ms var(--ease-out);
}
.stage-info-enter-from {
    opacity: 0;
    transform: translateY(12px);
}
.stage-info-leave-to {
    opacity: 0;
    transform: translateY(-12px);
}

/* ===== Reduced motion ===== */
@media (prefers-reduced-motion: reduce) {
    .stage-image-wrap {
        animation: none !important;
    }
    .stage-info-enter-active,
    .stage-info-leave-active,
    .stage-image,
    .stage-arrow,
    .stage-pager-dot,
    .stage-spicy-dot {
        transition: none !important;
    }
}

/* ===== Mobile narrow (<= 640) — Phase 9 cap image height ===== */
@media (max-width: 640px) {
    .stage-image-wrap {
        /* Phase 9 §33: keep product image ≤ 55vh */
        max-height: 55vh;
        aspect-ratio: 4 / 5;
    }
    .stage-arrows {
        gap: var(--space-2);
    }
    /* Phase 9 §35: ensure controls meet 44px. */
    .stage-arrow {
        width: 44px;
        height: 44px;
    }
}
</style>
