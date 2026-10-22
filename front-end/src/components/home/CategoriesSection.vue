<script setup lang="ts">
/**
 * CategoriesSection — Interactive Category Rail (Phase 4)
 *
 * Design goals:
 *  - EDITORIAL CATEGORY LIST + LIVE IMAGE PREVIEW (Phase 4 §8)
 *  - Cream surface — rhythm giảm năng lượng sau Hero red
 *  - Desktop: 2-column editorial — left list (60-65%), right sticky preview (35-40%)
 *  - Mobile: horizontal scroll-snap pills (CSS-only, no carousel lib)
 *
 * Composition:
 *  - Desktop large typography: clamp(36px, 5vw, 72px)
 *  - Hover/focus đổi preview — 200–400ms fade + scale 0.98→1
 *  - Click category → /thuc-don?category=<slug> (existing Menu filter)
 *  - Preview source: existing items trong homeStore.featured filter theo category_slug
 *    (không N+1 fetch — reuse data đã load).
 *
 * Reuses: useHomeStore, UiContainer, UiEyebrow, UiReveal.
 */
import { computed, ref, watch } from 'vue'
import { useHomeStore } from '@/stores/home'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiReveal from '@/components/ui/UiReveal.vue'
import type { MenuItemCard } from '@/types/home'
import { imageUrl } from '@/utils/image'

const store = useHomeStore()

const CATEGORY_IMAGES: Record<string, string> = {
    'do-uong': '/images/home/thuc-uong-demo.png',
    'mon-phu': '/images/home/kimbap-demo.png',
    'topping-them': '/storage/menu/tom-them.png',
    'mon-an-them': '/storage/menu/tom-them.png',
}

interface CategoryPreview {
    id: number
    name: string
    slug: string
    items_count: number
    representative: MenuItemCard | null
    /** Semantic fallback type — no category shares a mi-cay food image. */
    fallbackKind: 'do-uong' | 'ga-han-quoc' | 'tokbokki' | 'kimbap' | 'mi-cay' | 'default'
}

/**
 * Generic brand-categorical placeholder map. Each slug maps to a text-only
 * placeholder — never to an unrelated food image. This keeps the preview
 * semantically correct ("Đồ uống" never shows a bowl of mì cay).
 */
const PLACEHOLDER_LABEL: Record<string, string> = {
    'do-uong': 'Đồ uống',
    'ga-han-quoc': 'Gà Hàn Quốc',
    'ga-ran': 'Gà Hàn Quốc',
    'tokbokki': 'Tokbokki',
    'tteokbokki': 'Tokbokki',
    'kimbap': 'Kimbap',
    'mi-cay': 'Mì cay',
    'mi-cay-han-quoc': 'Mì cay',
    'mon-an-them': 'Món ăn thêm',
    'topping-them': 'Topping',
    'mandu': 'Manti',
    'combo': 'Combo',
}

function fallbackKindFor(slug: string): CategoryPreview['fallbackKind'] {
    const s = (slug ?? '').toLowerCase()
    if (s.includes('do-uong')) return 'do-uong'
    if (s.includes('ga-ran') || s.includes('ga-han-quoc') || s.includes('chicken')) return 'ga-han-quoc'
    if (s.includes('tokbokki') || s.includes('tteokbokki')) return 'tokbokki'
    if (s.includes('kimbap')) return 'kimbap'
    if (s.includes('mi-cay')) return 'mi-cay'
    return 'default'
}

function placeholderLabel(slug: string): string {
    return PLACEHOLDER_LABEL[slug] ?? PLACEHOLDER_LABEL[fallbackKindFor(slug)] ?? 'K-SPICY'
}

/**
 * Map category slug → representative dish từ featured list.
 * Reuse data đã có — không gọi N+1 API.
 */
const categoriesWithPreview = computed<CategoryPreview[]>(() => {
    const cats = store.categories ?? []
    const featured = [...(store.featured ?? []), ...(store.bestSellers ?? [])]
    return cats.map((c) => {
        const match = featured.find((f) => f.category_slug === c.slug) ?? null
        return {
            id: c.id,
            name: c.name,
            slug: c.slug,
            items_count: c.items_count,
            representative: match,
            fallbackKind: fallbackKindFor(c.slug),
        }
    })
})

const activeIndex = ref(0)
const activeSlug = computed<string | null>(() =>
    categoriesWithPreview.value[activeIndex.value]?.slug ?? null,
)

function previewSrc(cat: CategoryPreview | undefined): string {
    if (!cat) return ''
    return cat.representative?.image_url
        ? imageUrl(cat.representative.image_url)
        : CATEGORY_IMAGES[cat.slug] ?? ''
}

function previewAlt(cat: CategoryPreview | undefined): string {
    if (!cat) return ''
    return cat.representative
        ? `${cat.representative.name} — ${cat.name}`
        : `${cat.name}`
}

function categoryHref(slug: string | null | undefined): string {
    if (!slug) return '/thuc-don'
    return `/thuc-don?category=${encodeURIComponent(slug)}`
}

const previewFailed = ref(false)
watch(activeSlug, () => { previewFailed.value = false })
function onPreviewError() { previewFailed.value = true }

const visiblePreviewSrc = computed(() => {
    const src = previewSrc(categoriesWithPreview.value[activeIndex.value])
    if (previewFailed.value || !src) return ''
    return src
})

const activeFallbackKind = computed(() =>
    categoriesWithPreview.value[activeIndex.value]?.fallbackKind ?? 'default',
)
const activeFallbackLabel = computed(() => {
    const cat = categoriesWithPreview.value[activeIndex.value]
    if (!cat) return 'K-SPICY'
    return placeholderLabel(cat.slug)
})
</script>

<template>
    <section
        id="categories"
        class="cat-section"
        aria-labelledby="cat-title"
    >
        <UiContainer>
            <UiReveal>
                <header class="cat-head">
                    <UiEyebrow tone="charcoal">Khám phá</UiEyebrow>
                    <h2 id="cat-title" class="cat-title">
                        Bạn đang thèm <span class="cat-title-accent">món gì?</span>
                    </h2>
                    <p class="cat-sub">
                        Lướt qua các nhóm món — click để xem thực đơn đã lọc sẵn.
                    </p>
                </header>
            </UiReveal>

            <!-- Loading skeleton -->
            <div
                v-if="store.loading && !categoriesWithPreview.length"
                class="cat-skel"
                aria-hidden="true"
            >
                <div class="cat-skel-list">
                    <div v-for="i in 6" :key="i" class="cat-skel-row"></div>
                </div>
                <div class="cat-skel-preview"></div>
            </div>

            <!-- Editorial split: list + live preview -->
            <div v-else-if="categoriesWithPreview.length" class="cat-grid">

                <!-- LEFT — editorial list -->
                <ul class="cat-list" role="list">
                    <li
                        v-for="(cat, idx) in categoriesWithPreview"
                        :key="cat.id"
                        class="cat-item"
                        :class="{ 'is-active': idx === activeIndex }"
                    >
                        <a
                            :href="categoryHref(cat.slug)"
                            class="cat-link"
                            :aria-current="idx === activeIndex ? 'true' : undefined"
                            @mouseenter="activeIndex = idx"
                            @focus="activeIndex = idx"
                            @click="activeIndex = idx"
                        >
                            <span class="cat-index" aria-hidden="true">
                                {{ String(idx + 1).padStart(2, '0') }}
                            </span>
                            <span class="cat-name">
                                <img
                                    v-if="CATEGORY_IMAGES[cat.slug]"
                                    :src="CATEGORY_IMAGES[cat.slug]"
                                    class="cat-title-image"
                                    alt=""
                                    width="64"
                                    height="64"
                                    loading="lazy"
                                    decoding="async"
                                />
                                {{ cat.name }}
                            </span>
                            <span
                                v-if="cat.items_count"
                                class="cat-count"
                                aria-label="Số món"
                            >{{ cat.items_count }} món</span>
                            <span class="cat-arrow" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor"
                                    stroke-width="2.2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                    <polyline points="13 6 19 12 13 18"/>
                                </svg>
                            </span>
                        </a>
                    </li>
                </ul>

                <!-- RIGHT — sticky preview (desktop) -->
                <div class="cat-preview-wrap" aria-live="polite">
                    <div
                        :key="activeSlug ?? 'empty'"
                        class="cat-preview"
                        :class="`cat-preview--${activeFallbackKind}`"
                    >
                        <img
                            v-if="visiblePreviewSrc"
                            :src="visiblePreviewSrc"
                            :alt="previewAlt(categoriesWithPreview[activeIndex])"
                            class="cat-preview-img"
                            width="800"
                            height="800"
                            loading="lazy"
                            decoding="async"
                            @error="onPreviewError"
                        />
                        <div
                            v-else
                            class="cat-preview-placeholder"
                            :aria-hidden="visiblePreviewSrc ? 'true' : undefined"
                        >
                            <span class="cat-preview-placeholder-label">
                                {{ activeFallbackLabel }}
                            </span>
                        </div>
                        <div class="cat-preview-grad" aria-hidden="true"></div>
                        <div class="cat-preview-caption">
                            <span class="cat-preview-kicker">Danh mục</span>
                            <span class="cat-preview-tag">
                                {{ categoriesWithPreview[activeIndex]?.name ?? '—' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty fallback -->
            <div v-else class="cat-empty">
                <p>Chưa có danh mục món ăn để hiển thị.</p>
                <a class="cat-fallback" href="/thuc-don">Xem thực đơn</a>
            </div>
        </UiContainer>
    </section>
</template>

<style scoped>
/* =========================================================
   CATEGORY RAIL — editorial list + live preview (Phase 4)
   ========================================================= */

.cat-section {
    background: var(--color-cream);
    color: var(--color-text);
    padding-block: var(--space-editorial-lg);
    position: relative;
    overflow: hidden;
}

@media (max-width: 1023px) {
    .cat-section { padding-block: var(--space-editorial); }
}

@media (max-width: 640px) {
    .cat-section { padding-block: var(--space-9); }
}

/* Subtle hero handoff — cream rises into Hero warm orange. */
.cat-section::before {
    content: '';
    position: absolute;
    top: -1px;
    left: 0;
    right: 0;
    height: 80px;
    background: linear-gradient(
        180deg,
        rgba(241, 160, 40, 0.10) 0%,
        rgba(241, 160, 40, 0) 100%
    );
    pointer-events: none;
}

/* ===== Section head ===== */
.cat-head {
    margin-bottom: var(--space-9);
    max-width: 640px;
}

.cat-title {
    font-family: var(--font-display);
    font-size: clamp(36px, 5vw, 72px);
    font-weight: var(--weight-black);
    line-height: 0.95;
    letter-spacing: -0.035em;
    color: var(--color-charcoal-black);
    margin: var(--space-3) 0 var(--space-4);
}
.cat-title-accent {
    color: var(--color-chili-red);
    font-style: italic;
}

.cat-sub {
    font-size: clamp(15px, 1.4vw, 17px);
    color: var(--color-gray-500);
    line-height: 1.6;
    margin: 0;
    max-width: 40ch;
}

/* ===== Desktop split: list + preview ===== */
.cat-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.85fr);
    gap: var(--space-9);
    align-items: start;
}

@media (max-width: 1023px) {
    .cat-grid {
        grid-template-columns: 1fr;
        gap: var(--space-7);
    }
}

/* ===== Editorial list (desktop) ===== */
.cat-list {
    list-style: none;
    margin: 0;
    padding: 0;
    border-top: 1.5px solid rgba(17, 17, 17, 0.10);
}

.cat-item {
    border-bottom: 1.5px solid rgba(17, 17, 17, 0.10);
}

.cat-link {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    align-items: center;
    gap: var(--space-5);
    padding: var(--space-4) 0;
    text-decoration: none;
    color: inherit;
    transition:
        color var(--dur-fast) var(--ease-out),
        padding var(--dur-fast) var(--ease-out);
    cursor: pointer;
}

.cat-link:hover,
.cat-link:focus-visible,
.cat-item.is-active .cat-link {
    color: var(--color-chili-red);
    padding-left: var(--space-3);
}

.cat-link:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 6px;
    border-radius: var(--radius-sm);
}

.cat-index {
    font-family: var(--font-display);
    font-size: clamp(20px, 2vw, 28px);
    font-weight: var(--weight-bold);
    color: rgba(17, 17, 17, 0.30);
    letter-spacing: 0.04em;
    transition: color var(--dur-fast) var(--ease-out);
    min-width: 2ch;
    text-align: right;
}
.cat-item.is-active .cat-index,
.cat-link:hover .cat-index,
.cat-link:focus-visible .cat-index {
    color: var(--color-chili-red);
}

.cat-name {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: var(--font-display);
    font-size: clamp(28px, 4.2vw, 56px);
    font-weight: var(--weight-extrabold);
    line-height: 1.05;
    letter-spacing: -0.03em;
    color: var(--color-charcoal-black);
    transition: color var(--dur-fast) var(--ease-out);
}
.cat-title-image {
    width: clamp(40px, 5vw, 64px);
    height: clamp(40px, 5vw, 64px);
    flex-shrink: 0;
    object-fit: contain;
    border-radius: var(--radius-sm);
    background: var(--color-brand-soft);
}
.cat-item.is-active .cat-name,
.cat-link:hover .cat-name,
.cat-link:focus-visible .cat-name {
    color: var(--color-chili-red);
}

.cat-count {
    font-size: 13px;
    font-weight: var(--weight-semibold);
    color: rgba(17, 17, 17, 0.45);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    transition: color var(--dur-fast) var(--ease-out);
}
@media (max-width: 720px) {
    .cat-count { display: none; }
}

.cat-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    color: rgba(17, 17, 17, 0.40);
    transition:
        color var(--dur-fast) var(--ease-out),
        transform var(--dur-fast) var(--ease-out);
}
.cat-link:hover .cat-arrow,
.cat-link:focus-visible .cat-arrow,
.cat-item.is-active .cat-arrow {
    color: var(--color-chili-red);
    transform: translateX(4px);
}

/* ===== Preview image (desktop) ===== */
.cat-preview-wrap {
    position: sticky;
    top: calc(var(--navbar-height) + var(--space-7));
    align-self: start;
}

.cat-preview {
    position: relative;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    background: var(--color-cream-deep);
    border-radius: var(--radius-sm);
    animation: cat-preview-in 320ms var(--ease-out);
}

@keyframes cat-preview-in {
    from { opacity: 0; transform: scale(0.98); }
    to   { opacity: 1; transform: scale(1); }
}

.cat-preview-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 600ms var(--ease-out);
}
.cat-preview:hover .cat-preview-img { transform: scale(1.03); }

/* ===== Semantic placeholder when no image available =====
   Per category-kind — never shows unrelated food. */
.cat-preview-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(
            circle at 30% 25%,
            rgba(255, 220, 130, 0.30) 0%,
            transparent 60%
        ),
        var(--color-cream-deep);
    color: var(--color-charcoal-black);
}
.cat-preview-placeholder-label {
    font-family: var(--font-display);
    font-size: clamp(28px, 4vw, 56px);
    font-weight: var(--weight-black);
    letter-spacing: -0.03em;
    color: var(--color-charcoal-black);
    text-align: center;
    padding: 0 var(--space-4);
    line-height: 1.05;
}

/* Subtle kind-specific accent (decorative — not category-locked to an image). */
.cat-preview--do-uong   .cat-preview-placeholder { background:
    radial-gradient(circle at 30% 25%, rgba(241, 160, 40, 0.25) 0%, transparent 60%),
    var(--color-cream-deep);
}
.cat-preview--ga-han-quoc .cat-preview-placeholder { background:
    radial-gradient(circle at 30% 25%, rgba(215, 25, 32, 0.18) 0%, transparent 60%),
    var(--color-cream-deep);
}
.cat-preview--tokbokki  .cat-preview-placeholder { background:
    radial-gradient(circle at 30% 25%, rgba(231, 76, 60, 0.18) 0%, transparent 60%),
    var(--color-cream-deep);
}
.cat-preview--kimbap    .cat-preview-placeholder { background:
    radial-gradient(circle at 30% 25%, rgba(46, 204, 113, 0.15) 0%, transparent 60%),
    var(--color-cream-deep);
}
.cat-preview--mi-cay    .cat-preview-placeholder { background:
    radial-gradient(circle at 30% 25%, rgba(221, 53, 8, 0.20) 0%, transparent 60%),
    var(--color-cream-deep);
}

.cat-preview-grad {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    height: 50%;
    background: linear-gradient(
        to top,
        rgba(10, 10, 10, 0.76) 0%,
        rgba(10, 10, 10, 0.32) 44%,
        transparent 72%
    );
    pointer-events: none;
}

.cat-preview-caption {
    position: absolute;
    left: 18px;
    right: 18px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.cat-preview-kicker {
    font-family: var(--font-body);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.60);
    line-height: 1;
}

.cat-preview-tag {
    font-family: var(--font-display);
    font-size: clamp(17px, 2vw, 24px);
    font-weight: 800;
    line-height: 1.08;
    letter-spacing: -0.02em;
    color: #fff;
}

/* ===== Mobile: horizontal scroll-snap pills ===== */
@media (max-width: 1023px) {
    .cat-preview-wrap {
        position: relative;
        top: auto;
        max-width: 420px;
        margin: 0 auto;
    }

    .cat-preview-caption {
        left: 14px;
        right: 14px;
        bottom: 13px;
    }

    .cat-preview-kicker {
        font-size: 9px;
        letter-spacing: 0.14em;
    }

    .cat-preview-tag {
        font-size: clamp(15px, 4vw, 18px);
    }
}

@media (max-width: 720px) {
    .cat-list {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        /* Phase 9 §29: use proximity instead of mandatory so swipe feels natural */
        scroll-snap-type: x proximity;
        scroll-padding-left: var(--space-5);
        border-top: none;
        padding: 0 var(--space-5) var(--space-3);
        margin: 0 calc(-1 * var(--space-5));
        mask-image: linear-gradient(
            to right,
            transparent 0,
            #000 24px,
            #000 calc(100% - 24px),
            transparent 100%
        );
        -webkit-mask-image: linear-gradient(
            to right,
            transparent 0,
            #000 24px,
            #000 calc(100% - 24px),
            transparent 100
        );
    }

    .cat-item {
        flex: 0 0 220px;
        scroll-snap-align: start;
        border-bottom: none;
        background: transparent;
        border: none;
        border-top: 2px solid rgba(17, 17, 17, 0.10);
        border-radius: 0;
        margin-right: var(--space-5);
        overflow: visible;
        transition:
            border-color var(--dur-fast) var(--ease-out);
    }
    .cat-item.is-active {
        border-top-color: var(--color-chili-red);
    }

    .cat-link {
        grid-template-columns: 1fr;
        grid-template-rows: auto auto auto;
        gap: var(--space-2);
        padding: var(--space-4);
    }
    .cat-link:hover,
    .cat-link:focus-visible,
    .cat-item.is-active .cat-link {
        padding-left: var(--space-4);
    }
    .cat-index {
        font-size: 13px;
        letter-spacing: 0.1em;
        text-align: left;
    }
    .cat-name {
        font-size: 22px;
        line-height: 1.15;
    }
    .cat-arrow { display: none; }
    .cat-list::-webkit-scrollbar { display: none; }
    .cat-list { scrollbar-width: none; }
}

/* ===== Skeleton ===== */
.cat-skel {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.85fr);
    gap: var(--space-9);
}
@media (max-width: 1023px) {
    .cat-skel { grid-template-columns: 1fr; }
}

.cat-skel-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}
.cat-skel-row {
    height: 64px;
    background: linear-gradient(90deg,
        rgba(17, 17, 17, 0.05) 0%,
        rgba(17, 17, 17, 0.10) 50%,
        rgba(17, 17, 17, 0.05) 100%);
    background-size: 200% 100%;
    animation: cat-skel-shimmer 1.4s linear infinite;
    border-radius: var(--radius-sm);
}

.cat-skel-preview {
    aspect-ratio: 1 / 1;
    background: linear-gradient(90deg,
        rgba(17, 17, 17, 0.05) 0%,
        rgba(17, 17, 17, 0.10) 50%,
        rgba(17, 17, 17, 0.05) 100%);
    background-size: 200% 100%;
    animation: cat-skel-shimmer 1.4s linear infinite;
    border-radius: var(--radius-sm);
}

@keyframes cat-skel-shimmer {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

/* ===== Empty state ===== */
.cat-empty {
    text-align: center;
    padding: var(--space-10) var(--space-6);
    color: var(--color-gray-500);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-4);
}

.cat-fallback {
    color: var(--color-chili-red);
    font-weight: var(--weight-bold);
    text-decoration: none;
}

/* ===== Reduced motion ===== */
@media (prefers-reduced-motion: reduce) {
    .cat-preview {
        animation: none !important;
    }
    .cat-preview-img,
    .cat-link,
    .cat-arrow,
    .cat-index,
    .cat-name {
        transition: none !important;
    }
    .cat-link:hover,
    .cat-link:focus-visible,
    .cat-item.is-active .cat-link {
        padding-left: 0;
    }
}
</style>
