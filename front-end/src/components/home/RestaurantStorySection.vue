<script setup lang="ts">
/**
 * RestaurantStorySection → Cinematic Human Story (Phase 6)
 *
 * Design goals (Phase 6 §31-§86):
 *  - ONE cohesive story experience (Food / Place / People)
 *  - Warm dark surface (charcoal + warm brown gradient), not pure black
 *  - Desktop: sticky visual + scrolling text chapters with crossfade
 *  - Mobile: vertical story (image + chapter alternating)
 *  - Slow crossfade 700-1000ms when active chapter changes
 *  - No fake history (Phase 6 §42): no "20 năm", "Seoul", "since 1998"
 *  - Real data only: restaurant slogan, address, open_hours
 *  - No hard-sell CTA (emotional pacing)
 *  - Restrained motion (target 2.5/5)
 *
 * Data:
 *  - Reuses homeStore.restaurant (real brand voice).
 *  - Story chapters hard-coded with NON-FACTUAL brand statements
 *    describing the food/place/people experience — NOT fictional history.
 *  - Images reuse existing /storage/brand/restaurant.jpg (place),
 *    /storage/menu/* (food). People chapter uses text-only fallback
 *    since no approved people photo asset exists.
 *
 * Reuses: UiSection bg="charcoal", UiContainer, UiEyebrow tone="gold".
 */
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { imageUrl } from '@/utils/image'
import { useHomeStore } from '@/stores/home'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiReveal from '@/components/ui/UiReveal.vue'

const store = useHomeStore()

// =========================================================
// CHAPTER CONTENT (Phase 6 §33-§35)
// =========================================================
// Three chapters: MÓN ĂN / KHÔNG GIAN / CON NGƯỜI.
// Copy is intentionally short, non-factual, experiential — not history.
// =========================================================
interface Chapter {
    no: string
    title: string
    subtitle: string
    body: string
    image: string
}

const chapters: Chapter[] = [
    {
        no: '01',
        title: 'Món ăn',
        subtitle: 'Nước dùng đậm vị, topping tươi mỗi ngày',
        body: 'Mỗi tô mì bắt đầu từ nồi nước dùng được ninh kỹ, lọc trong, giữ trọn vị ngọt tự nhiên. Sốt cay được pha tại bếp mỗi ngày, không dùng bột công nghiệp.',
        image: imageUrl('/storage/menu/mi-cay-hai-san.png'),
    },
    {
        no: '02',
        title: 'Không gian',
        subtitle: store.restaurant?.address ?? 'Quán ấm, ánh vàng, bàn gỗ',
        body: 'Không gian thiết kế theo phong cách Hàn Quốc hiện đại — ấm vào mùa lạnh, thoáng vào mùa hè. Từng bàn được đặt đủ rộng để nhóm bạn hay gia đình cùng quây quần.',
        image: imageUrl('/storage/brand/restaurant.jpg'),
    },
    {
        // Chapter 03 — TEXT-FIRST (Phase 6 Correction §1).
        // No approved staff / kitchen / hands / plating photo asset exists.
        // Per §48 we must NOT use stock/random people. Per §1 we must
        // NOT reuse the place image. Solution: render a typography-driven
        // fallback inside the sticky frame.
        no: '03',
        title: 'Con người',
        subtitle: 'Phục vụ tận tâm',
        body: 'Đội ngũ phục vụ nhớ mặt khách quen, gợi ý cấp cay phù hợp, ra tay khi topping cần thêm. K-SPICY không chỉ là quán ăn — đó là nơi bạn muốn quay lại.',
        image: imageUrl('/storage/brand/dau-bep.jpg'),  
    },
]

// =========================================================
// ACTIVE CHAPTER TRACKING (Phase 6 §40, §62, §76, §77)
// =========================================================
const activeIndex = ref(0)
const chapterRefs = ref<HTMLElement[]>([])
let observer: IntersectionObserver | null = null

function setChapterRef(el: Element | null, idx: number) {
    if (el instanceof HTMLElement) chapterRefs.value[idx] = el
}

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') return
    observer = new IntersectionObserver(
        (entries) => {
            // Pick the most visible chapter as active — prevents flicker.
            let bestIdx = activeIndex.value
            let bestRatio = -1
            entries.forEach((entry) => {
                const idx = chapterRefs.value.findIndex(
                    (el) => el === entry.target,
                )
                if (idx === -1) return
                if (entry.intersectionRatio > bestRatio) {
                    bestRatio = entry.intersectionRatio
                    bestIdx = idx
                }
            })
            if (bestRatio > 0) activeIndex.value = bestIdx
        },
        {
            // Trigger when chapter text crosses upper third of viewport.
            rootMargin: '-30% 0px -50% 0px',
            threshold: [0, 0.25, 0.5, 0.75, 1],
        },
    )
    chapterRefs.value.forEach((el) => el && observer?.observe(el))
})

onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
})

// =========================================================
// IMAGE ERROR FALLBACK (Phase 6 §29)
// =========================================================
const imageErrors = ref<Set<number>>(new Set())
function onImgError(idx: number) {
    if (!imageErrors.value.has(idx)) {
        imageErrors.value = new Set([...imageErrors.value, idx])
    }
}

function imgSrcFor(idx: number): string {
    if (imageErrors.value.has(idx)) return ''
    return chapters[idx]?.image ?? ''
}

// =========================================================
// HEADLINE
// =========================================================
const headlineEyebrow = 'Câu chuyện K-SPICY'
const headlineTitle = 'From heat to heart.'
const headlineSub = computed(() =>
    store.restaurant?.slogan ?? 'Chuẩn vị Hàn, nóng hổi trên bàn',
)
</script>

<template>
    <UiSection
        id="story"
        bg="charcoal"
        padding="default"
        aria-labelledby="story-title"
    >
        <div class="story-bg" aria-hidden="true">
            <span class="bg-shape bg-shape-1"></span>
            <span class="bg-shape bg-shape-2"></span>
        </div>

        <UiContainer>
            <!-- ===== Section header ===== -->
            <UiReveal>
                <header class="story-head">
                    <UiEyebrow tone="gold">{{ headlineEyebrow }}</UiEyebrow>
                    <h2 id="story-title" class="story-title">
                        From heat to heart<span class="accent">.</span>
                    </h2>
                    <p class="story-sub">{{ headlineSub }}</p>
                </header>
            </UiReveal>

            <!-- ===== Story body ===== -->
            <div class="story-body">

                <!-- ===== LEFT — sticky visual crossfade ===== -->
                <div class="story-sticky" aria-hidden="true">
                    <div class="story-frame">
                        <div
                            v-for="(c, idx) in chapters"
                            :key="c.no"
                            class="story-slide"
                            :class="{
                                'is-active': activeIndex === idx,
                                'is-text-only': !imgSrcFor(idx),
                            }"
                        >
                            <img
                                v-if="imgSrcFor(idx)"
                                :src="imgSrcFor(idx)"
                                :alt="''"
                                class="story-img"
                                loading="lazy"
                                decoding="async"
                                width="900"
                                height="1200"
                                @error="onImgError(idx)"
                            />
                            <div v-else class="story-fallback">
                                <span class="fallback-no">{{ c.no }}</span>
                                <span class="fallback-mark">{{ c.title }}</span>
                                <span class="fallback-line">Câu chuyện K-SPICY</span>
                            </div>
                            <div class="story-grad" aria-hidden="true"></div>
                            <span class="story-no">N° {{ c.no }}</span>
                        </div>
                    </div>
                </div>

                <!-- ===== RIGHT — scrolling chapters ===== -->
                <div class="story-chapters" role="list">
                    <article
                        v-for="(c, idx) in chapters"
                        :key="c.no"
                        :ref="(el) => setChapterRef(el as Element, idx)"
                        class="chapter"
                        :class="{ 'is-active': activeIndex === idx }"
                        role="listitem"
                        :aria-current="activeIndex === idx ? 'true' : undefined"
                    >
                        <header class="chapter-head">
                            <span class="chapter-no">{{ c.no }}</span>
                            <h3 class="chapter-title">{{ c.title }}</h3>
                        </header>
                        <p class="chapter-sub">{{ c.subtitle }}</p>
                        <p class="chapter-body">{{ c.body }}</p>
                    </article>
                </div>
            </div>

            <!-- ===== Footer CTA (emotional, NOT hard-sell) ===== -->
            <UiReveal :delay="120">
                <div class="story-foot">
                    <UiButton
                        as="router-link"
                        to="/khong-gian"
                        variant="ghost"
                        size="lg"
                        class="story-cta"
                    >
                        Ghé thăm chúng tôi
                        <svg width="16" height="16" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </UiButton>
                    <span class="story-meta">
                        {{ store.restaurant?.open_hours ?? '10:00 – 22:00' }}
                        ·
                        {{ store.restaurant?.address ?? 'Chi nhánh Quận 1' }}
                    </span>
                </div>
            </UiReveal>
        </UiContainer>
    </UiSection>
</template>

<style scoped>
/* =========================================================
   CINEMATIC HUMAN STORY — Phase 6
   Warm dark charcoal, sticky visual crossfade, slow pacing.
   ========================================================= */

.story {
    color: var(--color-white);
    position: relative;
    overflow: hidden;
}

/* Subtle warm radial glow — controlled, not gradient blobs. */
.story-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.bg-shape {
    position: absolute;
    border-radius: 50%;
}

.bg-shape-1 {
    top: -200px;
    right: -120px;
    width: 580px;
    height: 580px;
    background: radial-gradient(circle, rgba(215, 25, 32, 0.10) 0%, transparent 70%);
}

.bg-shape-2 {
    bottom: -160px;
    left: -120px;
    width: 460px;
    height: 460px;
    background: radial-gradient(circle, rgba(255, 176, 0, 0.05) 0%, transparent 70%);
}

/* ===== Header ===== */
.story-head {
    max-width: 720px;
    margin: 0 0 var(--space-11);
    position: relative;
    z-index: 1;
}

.story-title {
    font-family: var(--font-display);
    font-size: clamp(40px, 5.5vw, 80px);
    font-weight: var(--weight-black);
    color: var(--color-white);
    letter-spacing: -0.04em;
    line-height: 1;
    margin: var(--space-4) 0 var(--space-4);
}

.story-title .accent {
    color: var(--color-chili-red);
    font-style: italic;
}

.story-sub {
    font-size: clamp(16px, 1.5vw, 19px);
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.78);
    max-width: 520px;
    margin: 0;
}

/* ===== Story body ===== */
.story-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-11);
    align-items: start;
    position: relative;
    z-index: 1;
}

/* ===== LEFT — sticky visual ===== */
.story-sticky {
    position: sticky;
    top: calc(var(--navbar-height, 72px) + var(--space-6));
    align-self: start;
}

.story-frame {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 5;
    border-radius: var(--radius-lg);
    overflow: hidden;
    background: var(--color-charcoal-light);
    box-shadow: 0 30px 60px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.04);
}

/* All slides stack in same frame; crossfade via opacity (Phase 6 §77). */
.story-slide {
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 900ms var(--ease-in-out);
}

.story-slide.is-active {
    opacity: 1;
}

.story-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* Subtle scale — cinematic, not flashy */
    transform: scale(1.04);
    transition: transform 1200ms var(--ease-in-out);
}

.story-slide.is-active .story-img {
    transform: scale(1);
}

.story-grad {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            180deg,
            rgba(0, 0, 0, 0) 50%,
            rgba(0, 0, 0, 0.45) 100%
        );
    pointer-events: none;
}

.story-no {
    position: absolute;
    top: var(--space-5);
    left: var(--space-5);
    z-index: 2;
    padding: 8px 14px;
    background: rgba(17, 17, 17, 0.78);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: var(--radius-sm);
    backdrop-filter: blur(6px);
    color: var(--color-golden);
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: var(--weight-extrabold);
    letter-spacing: 0.16em;
    font-variant-numeric: tabular-nums;
}

.story-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    background:
        radial-gradient(circle at 30% 25%, rgba(255, 220, 130, 0.18) 0%, transparent 60%),
        linear-gradient(160deg, var(--color-charcoal-light) 0%, var(--color-dark-red) 100%);
    color: var(--color-white);
    padding: var(--space-6);
    text-align: center;
}

/* Chapter 03 text-first — oversized number + title typography.
   Used when no approved photo asset exists (Phase 6 Correction §1). */
.fallback-no {
    font-family: var(--font-display);
    font-size: clamp(96px, 12vw, 180px);
    font-weight: var(--weight-black);
    color: rgba(255, 255, 255, 0.10);
    letter-spacing: -0.04em;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.fallback-mark {
    font-family: var(--font-display);
    font-size: clamp(36px, 5vw, 64px);
    font-weight: var(--weight-black);
    color: var(--color-chili-red);
    letter-spacing: -0.03em;
    margin-top: calc(-1 * var(--space-4));
}

.fallback-line {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.32em;
    color: var(--color-golden);
    text-transform: uppercase;
}

/* Slides without photo: hide gradient overlay (gradient was for image legibility). */
.story-slide.is-text-only .story-grad {
    display: none;
}
.story-slide.is-text-only .story-no {
    /* Hide the redundant N° label since the giant number is already shown. */
    display: none;
}

/* ===== RIGHT — scrolling chapters ===== */
.story-chapters {
    display: flex;
    flex-direction: column;
    gap: 50vh;  /* scroll distance between chapters */
    padding-top: 5vh;
    padding-bottom: 20vh;
}

.chapter {
    max-width: 540px;
    transition: opacity 400ms var(--ease-out);
    opacity: 0.45;
}

.chapter.is-active {
    opacity: 1;
}

.chapter-head {
    display: flex;
    align-items: baseline;
    gap: var(--space-4);
    margin-bottom: var(--space-3);
}

.chapter-no {
    font-family: var(--font-display);
    font-size: clamp(36px, 3.6vw, 56px);
    font-weight: var(--weight-black);
    color: var(--color-chili-red);
    letter-spacing: -0.04em;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.chapter-title {
    font-family: var(--font-display);
    font-size: clamp(28px, 3vw, 44px);
    font-weight: var(--weight-extrabold);
    color: var(--color-white);
    letter-spacing: -0.03em;
    line-height: 1;
    margin: 0;
}

.chapter-sub {
    font-size: 14px;
    font-weight: var(--weight-extrabold);
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--color-golden);
    margin: var(--space-2) 0 var(--space-4);
}

.chapter-body {
    font-size: clamp(16px, 1.4vw, 18px);
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.85);
    margin: 0;
    max-width: 48ch;
}

/* ===== Footer CTA ===== */
.story-foot {
    position: relative;
    z-index: 1;
    margin-top: var(--space-11);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-5);
    padding-top: var(--space-7);
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.story-cta :deep(.ui-btn) {
    color: var(--color-white);
    border-color: rgba(255, 255, 255, 0.30);
    background: transparent;
}

.story-cta :deep(.ui-btn:hover) {
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--color-white);
}

.story-meta {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.55);
    letter-spacing: 0.02em;
}

/* =========================================================
   Tablet (1024) — disable sticky, single column story
   ========================================================= */
@media (max-width: 1023px) {
    .story-body {
        grid-template-columns: 1fr;
        gap: var(--space-9);
    }
    .story-sticky {
        position: relative;
        top: auto;
        max-width: 480px;
        margin: 0 auto;
    }
    .story-frame {
        aspect-ratio: 16 / 11;
    }
    .story-chapters {
        gap: var(--space-10);
        padding: 0;
    }
    .chapter { opacity: 1; }
    .chapter.is-active { opacity: 1; }
}

/* =========================================================
   Mobile (640) — vertical alternating
   ========================================================= */
@media (max-width: 640px) {
    .story-title { font-size: clamp(34px, 9vw, 48px); }
    .story-sub { font-size: 15px; }
    .story-frame { aspect-ratio: 4 / 5; max-width: 320px; margin: 0 auto; }
    .chapter-head { gap: var(--space-3); }
    .chapter-no { font-size: 32px; }
    .chapter-title { font-size: 26px; }
    .chapter-body { font-size: 15px; }
    .story-foot {
        flex-direction: column;
        align-items: stretch;
        gap: var(--space-3);
    }
    .story-cta :deep(.ui-btn) {
        width: 100%;
        justify-content: center;
        min-height: 48px;
    }
    .story-meta { text-align: center; }
}

/* =========================================================
   Reduced motion — no crossfade choreography
   ========================================================= */
@media (prefers-reduced-motion: reduce) {
    .story-slide,
    .story-img,
    .chapter {
        transition: none !important;
    }
}
</style>
