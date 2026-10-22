<script setup lang="ts">
/**
 * MenuDiscoverySection — aligned rows of suggested dishes.
 *
 * Design goals (Phase 6 §5-§30):
 *  - Curated food editorial — NOT product grid, NOT marketplace listing
 *  - 6–8 dishes max
 *  - Equal-width cards with consistent image and metadata alignment
 *  - Food photography dominates; minimal metadata
 *  - NO add-to-cart on each item (gallery = discovery)
 *  - Click item → opens FoodDetailModal via cartStore.openAddModal
 *  - Restrained motion (target 2/5)
 *  - Pale red surface with red headings, prices and CTA
 *
 * Data:
 *  - Reuses homeStore.bestSellers + featured (already loaded by Hero).
 *  - Curation: available first → diverse category → no duplicates → cap 8.
 *  - No random shuffle on page load (Phase 6 §75).
 *
 * Reuses: UiContainer, UiEyebrow, UiButton, UiReveal.
 */
import { computed } from 'vue'
import { useHomeStore } from '@/stores/home'
import { useCartStore } from '@/stores/cart'
import type { MenuItemCard } from '@/types/home'
import { imageUrl } from '@/utils/image'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiReveal from '@/components/ui/UiReveal.vue'

const store = useHomeStore()
const cartStore = useCartStore()

// =========================================================
// CURATED GALLERY (Phase 6 §7, §8, §74)
// =========================================================
// Strategy:
//   1. available products first
//   2. maximize category diversity
//   3. fill remainder from any pool
//   4. dedup by id
//   5. cap 8
const MAX_ITEMS = 8

interface GalleryItem {
    id: number
    name: string
    category: string
    price_formatted: string
    image: string
    spicy_level: number
    item: MenuItemCard  // reference for modal handler
}

function isAvailable(item: MenuItemCard): boolean {
    return item.is_available !== false
}

const gallery = computed<GalleryItem[]>(() => {
    const bestSellers = store.bestSellers ?? []
    const featured = store.featured ?? []
    const seen = new Set<number>()
    const out: GalleryItem[] = []
    const catCount = new Map<string, number>()

    function pushIf(item: MenuItemCard) {
        if (!item || seen.has(item.id)) return
        seen.add(item.id)
        const cat = item.category_name ?? 'K-SPICY'
        catCount.set(cat, (catCount.get(cat) ?? 0) + 1)
        out.push({
            id: item.id,
            name: item.name,
            category: cat,
            price_formatted: item.price_formatted,
            image: imageUrl(item.image_url),
            spicy_level: item.spicy_level ?? 0,
            item,
        })
    }

    // Round 1: available, prioritize diversity (skip if category already full).
    function pickDiverse(list: MenuItemCard[]) {
        for (const p of list) {
            if (out.length >= MAX_ITEMS) break
            if (!isAvailable(p)) continue
            const cat = p.category_name ?? 'K-SPICY'
            if ((catCount.get(cat) ?? 0) >= 2) continue
            pushIf(p)
        }
    }

    pickDiverse(bestSellers)
    pickDiverse(featured)

    // Round 2: fill remainder with any available (relax diversity rule).
    function pickAnyAvailable(list: MenuItemCard[]) {
        for (const p of list) {
            if (out.length >= MAX_ITEMS) break
            if (!isAvailable(p)) continue
            pushIf(p)
        }
    }
    pickAnyAvailable(bestSellers)
    pickAnyAvailable(featured)

    // Round 3: still short? include unavailable.
    if (out.length < 4) {
        function pickAny(list: MenuItemCard[]) {
            for (const p of list) {
                if (out.length >= MAX_ITEMS) break
                pushIf(p)
            }
        }
        pickAny(bestSellers)
        pickAny(featured)
    }

    return out.slice(0, MAX_ITEMS)
})

const hasGallery = computed(() => gallery.value.length > 0)

// =========================================================
// ITEM INTERACTION (Phase 6 §19, §20)
// =========================================================
function openItem(item: GalleryItem) {
    if (!isAvailable(item.item)) return
    cartStore.openAddModal({
        id: item.item.id,
        name: item.item.name,
        price: item.item.price,
        price_formatted: item.item.price_formatted,
        default_spicy: item.item.spicy_level ?? 0,
        image_url: item.item.image_url,
    })
}

// =========================================================
// IMAGE ERROR (Phase 6 §29) — semantic branded fallback
// =========================================================
const failedImages = ref<Set<number>>(new Set())
function onImgError(id: number) {
    if (!failedImages.value.has(id)) {
        failedImages.value = new Set([...failedImages.value, id])
    }
}

function imgSrc(item: GalleryItem): string {
    if (failedImages.value.has(item.id)) return ''
    return item.image
}

import { ref } from 'vue'
</script>

<template>
    <UiSection
        id="menu-gallery"
        class="gallery-section"
        bg="cream"
        padding="default"
        aria-labelledby="gallery-title"
    >
        <UiContainer>
            <UiReveal>
                <header class="gallery-head">
                    <UiEyebrow tone="red">Khám phá thực đơn</UiEyebrow>
                    <h2 id="gallery-title" class="gallery-title">
                        Nhiều món hơn
                        <span class="accent">đang chờ bạn.</span>
                    </h2>
                    <p class="gallery-sub">
                        Mỗi tô mì, mỗi đĩa gà, mỗi ly đồ uống — đều là một câu chuyện
                        được nấu bằng nguyên liệu tươi và công thức chuẩn Hàn.
                    </p>
                </header>
            </UiReveal>

            <ul v-if="hasGallery" class="gallery-grid">
                <li
                    v-for="(item, idx) in gallery"
                    :key="item.id"
                    class="gallery-list-item"
                >
                    <UiReveal :delay="40 + idx * 40">
                        <button
                            type="button"
                            class="gallery-item"
                            :disabled="item.item.is_available === false"
                            :aria-label="`Mở chi tiết món ${item.name} — ${item.price_formatted}`"
                            @click="openItem(item)"
                        >
                            <div class="gallery-photo">
                                <img
                                    v-if="imgSrc(item)"
                                    :src="imgSrc(item)"
                                    :alt="`Món ${item.name}`"
                                    loading="lazy"
                                    decoding="async"
                                    width="800"
                                    height="800"
                                    @error="onImgError(item.id)"
                                />
                                <div v-else class="gallery-photo-fallback" aria-hidden="true">
                                    <span>{{ item.category }}</span>
                                </div>

                                <!-- Unavailable status badge (Phase 6 Correction §3) -->
                                <span
                                    v-if="item.item.is_available === false"
                                    class="gallery-status"
                                    aria-label="Tạm hết"
                                >
                                    Tạm hết
                                </span>
                            </div>
                            <div class="gallery-meta">
                                <span class="gallery-cat">{{ item.category }}</span>
                                <h3 class="gallery-name">{{ item.name }}</h3>
                                <span class="gallery-price">{{ item.price_formatted }}</span>
                            </div>
                        </button>
                    </UiReveal>
                </li>
            </ul>

            <div v-else class="gallery-empty">
                <p>Chưa có món để gợi ý hôm nay.</p>
                <UiButton variant="outline" as="router-link" to="/thuc-don">
                    Xem thực đơn
                </UiButton>
            </div>

            <UiReveal :delay="120">
                <div class="gallery-foot">
                    <UiButton
                        as="router-link"
                        to="/thuc-don"
                        variant="primary"
                        size="lg"
                        class="gallery-cta"
                    >
                        Xem toàn bộ thực đơn
                        <svg width="16" height="16" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </UiButton>
                </div>
            </UiReveal>
        </UiContainer>
    </UiSection>
</template>

<style scoped>
/* =========================================================
   MENU GALLERY — consistent rows, food-first, red accents.
   ========================================================= */

.gallery-section {
    background-color: color-mix(in srgb, var(--color-chili-red) 7%, var(--color-cream));
}

.gallery-head {
    margin-bottom: var(--space-7);
    max-width: 800px;
}

.gallery-title {
    font-family: var(--font-display);
    font-size: clamp(32px, 4vw, 56px);
    font-weight: var(--weight-extrabold);
    color: var(--color-spicy-4);
    letter-spacing: -0.035em;
    line-height: 1.2;
    margin: var(--space-3) 0 var(--space-5);
}

.gallery-title .accent {
    color: var(--color-spicy-4);
}

.gallery-sub {
    font-size: 16px;
    line-height: 1.65;
    color: var(--color-gray-600);
    max-width: 480px;
    margin: 0;
}

/* ===== Equal-width rows; native list semantics ===== */
.gallery-grid {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--space-6);
}

/* Each <li> takes one grid cell; the <button> inside fills it. */
.gallery-list-item {
    display: flex;
    min-width: 0;
}
.gallery-list-item > :deep(.ui-reveal) {
    width: 100%;
    height: 100%;
}

/* Item base */
.gallery-item {
    position: relative;
    overflow: hidden;
    background: var(--color-white);
    border: 1px solid color-mix(in srgb, var(--color-chili-red) 20%, var(--color-white));
    border-radius: var(--radius-md);
    padding: 0;
    cursor: pointer;
    text-align: left;
    color: inherit;
    transition: transform 250ms var(--ease-out), border-color 250ms var(--ease-out);
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
}

.gallery-item:hover:not(:disabled) {
    transform: translateY(-2px);
    border-color: var(--color-chili-red);
}

.gallery-item:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.gallery-item:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 3px;
}

/* ===== Photo ===== */
.gallery-photo {
    flex: none;
    width: 100%;
    aspect-ratio: 4 / 3;
    position: relative;
    overflow: hidden;
    background: color-mix(in srgb, var(--color-chili-red) 5%, var(--color-cream));
}

.gallery-photo img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: var(--space-4);
    transition: transform 600ms var(--ease-out);
}

.gallery-item:hover:not(:disabled) .gallery-photo img {
    transform: scale(1.04);
}

.gallery-photo-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(circle at 30% 25%, rgba(255, 220, 130, 0.30) 0%, transparent 60%),
        var(--color-cream-deep);
    color: var(--color-charcoal-black);
    font-family: var(--font-display);
    font-size: clamp(18px, 2vw, 28px);
    font-weight: var(--weight-extrabold);
    letter-spacing: -0.02em;
}

/* Unavailable status (Phase 6 Correction §3) */
.gallery-status {
    position: absolute;
    top: var(--space-3);
    left: var(--space-3);
    z-index: 2;
    padding: 6px 12px;
    background: var(--color-charcoal-black);
    color: var(--color-white);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: var(--weight-extrabold);
    text-transform: uppercase;
    letter-spacing: 0.16em;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
}

/* ===== Meta ===== */
.gallery-meta {
    flex: 1;
    width: 100%;
    padding: var(--space-4);
    background: var(--color-white);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.gallery-cat {
    font-size: 12px;
    font-weight: var(--weight-extrabold);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-dark-red);
}

.gallery-name {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: var(--weight-extrabold);
    letter-spacing: -0.01em;
    line-height: 1.3;
    min-height: 2.6em;
    margin: 0;
    color: var(--color-charcoal-black);
}

.gallery-price {
    font-family: var(--font-ui);
    font-size: 16px;
    font-weight: var(--weight-bold);
    color: var(--color-dark-red);
    margin-top: auto;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.01em;
}

/* ===== Empty state ===== */
.gallery-empty {
    text-align: center;
    padding: var(--space-10) var(--space-6);
    color: var(--color-gray-500);
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    align-items: center;
}

/* ===== Footer CTA ===== */
.gallery-foot {
    display: flex;
    justify-content: center;
    margin-top: var(--space-9);
}

/* =========================================================
   Tablet (1024): simpler 2-col
   ========================================================= */
@media (max-width: 1023px) {
    .gallery-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: var(--space-4);
    }
}

/* =========================================================
   Mobile (640): aligned single column
   ========================================================= */
@media (max-width: 640px) {
    .gallery-grid {
        grid-template-columns: 1fr;
    }
    .gallery-foot { margin-top: var(--space-7); }
}

/* =========================================================
   Reduced motion — no reveal/scale animation
   ========================================================= */
@media (prefers-reduced-motion: reduce) {
    .gallery-item,
    .gallery-photo img,
    .gallery-meta {
        transition: none !important;
    }
    .gallery-item:hover:not(:disabled) {
        transform: none;
    }
    .gallery-item:hover:not(:disabled) .gallery-photo img {
        transform: none;
    }
}
</style>
