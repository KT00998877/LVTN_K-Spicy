<script setup lang="ts">
/**
 * ComboPromotionSection → Spicy Campaign Poster (Phase 5)
 *
 * Phase 5 Correction §3 — campaign data source RE-VERIFIED.
 *
 * Verified (this correction pass):
 *  - HomePayload (src/types/home.ts) does NOT contain a
 *    `combo_promotion` / `comboPromotion` / `campaign` field.
 *  - useHomeStore (src/stores/home.ts) has no such getter.
 *  - fetchHomePayload (src/api/home.ts) returns the typed payload
 *    directly — backend has no slot for it.
 *
 *   Conclusion: there is no real promotion data on /api/home.
 *   Per Phase 5 §3 + §66 we MUST hide the section gracefully
 *   instead of faking promotion content from `featured[0]`.
 *
 *   This component reads an OPTIONAL `payload?.combo_promotion`
 *   field via a typed extension. If backend later ships a real
 *   promotion endpoint, the component will render automatically
 *   without changes.
 *
 * Design goals:
 *  - ONE featured campaign, no carousel / no slider / no fake claims
 *  - Spicy red / chili orange poster surface
 *  - One-shot reveal animation (700–1100ms), then STATIC
 *  - No new dependencies — pure CSS + Vue transition
 *
 * Reuses: UiSection bg="chili", UiContainer, UiEyebrow tone="gold",
 *         UiButton variant="white", imageUrl util.
 */
import { computed, ref } from 'vue'
import { imageUrl } from '@/utils/image'
import { useHomeStore } from '@/stores/home'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'

const store = useHomeStore()

// =========================================================
// REAL CAMPAIGN DATA (Phase 5 Correction §3 + §4)
//
// We read an OPTIONAL field on the home payload. If backend
// later ships it, the section renders. If absent, we hide
// gracefully — no fake promotion content from featured items.
// =========================================================
interface ComboPromotionPayload {
    id: number
    title: string
    subtitle: string | null
    description: string | null
    image_url: string | null
    menu_item_slug: string | null
    // No fake fields here — discount / voucher / expiry / VAT
    // must come from a real backend schema when shipped.
}

function readCampaign(): ComboPromotionPayload | null {
    // Opt-in: backend may extend HomePayload later.
    const p = store.payload as unknown as
        { combo_promotion?: ComboPromotionPayload | null } | null
    if (!p || !p.combo_promotion) return null
    if (!p.combo_promotion.title) return null
    return p.combo_promotion
}

const campaign = computed<ComboPromotionPayload | null>(() => readCampaign())
const hasCampaign = computed(() => campaign.value !== null)

const campaignTitle = computed(() => campaign.value?.title ?? '')
const campaignSubtitle = computed(() => campaign.value?.subtitle ?? '')
const campaignDesc = computed(() => campaign.value?.description ?? '')

// =========================================================
// IMAGE ERROR FALLBACK (semantic branded fallback — Phase 5 §69)
// =========================================================
const FALLBACK_LABEL = 'K-SPICY'

const campaignImage = computed(() => {
    const url = campaign.value?.image_url
    return url ? imageUrl(url) : ''
})

const imgFailed = ref(false)
const visibleImgSrc = computed(() => {
    if (imgFailed.value) return ''
    return campaignImage.value
})

function onImgError() { imgFailed.value = true }

// =========================================================
// CTA — single primary, routes to existing /thuc-don (no fake route)
// =========================================================
const ctaHref = '/thuc-don'
</script>

<template>
    <!--
        Phase 5 Correction §3 + §66: hide section gracefully when no
        real promotion data exists. Backend has no combo_promotion
        field yet, so this currently never renders. Backend enhancement
        will activate this section without code changes here.
    -->
    <UiSection
        v-if="hasCampaign"
        id="campaign"
        bg="chili"
        padding="hero"
        aria-labelledby="campaign-title"
    >
        <!-- Decorative chili shapes — editorial poster accents -->
        <div class="campaign-bg" aria-hidden="true">
            <span class="chili-blob chili-blob-1"></span>
            <span class="chili-blob chili-blob-2"></span>
            <span class="chili-blob chili-blob-3"></span>
        </div>

        <UiContainer>
            <div class="campaign-poster">

                <!-- ===== LEFT — copy ===== -->
                <div class="campaign-copy">
                    <UiEyebrow tone="gold">Spicy campaign</UiEyebrow>

                    <h2
                        id="campaign-title"
                        class="campaign-title"
                    >{{ campaignTitle }}</h2>

                    <p
                        v-if="campaignSubtitle"
                        class="campaign-subtitle"
                    >{{ campaignSubtitle }}</p>

                    <p
                        v-if="campaignDesc"
                        class="campaign-desc"
                    >{{ campaignDesc }}</p>

                    <div class="campaign-actions">
                        <UiButton
                            as="router-link"
                            :to="ctaHref"
                            variant="white"
                            size="lg"
                            class="campaign-cta"
                        >
                            Xem thực đơn
                            <svg width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </UiButton>
                    </div>
                </div>

                <!-- ===== RIGHT — oversized food visual ===== -->
                <div class="campaign-visual">
                    <div class="visual-frame">
                        <img
                            v-if="visibleImgSrc"
                            :src="visibleImgSrc"
                            :alt="campaignTitle"
                            class="campaign-img"
                            loading="lazy"
                            decoding="async"
                            width="900"
                            height="900"
                            @error="onImgError"
                        />
                        <div
                            v-else
                            class="visual-fallback"
                            aria-hidden="true"
                        >
                            <span class="visual-fallback-label">
                                {{ FALLBACK_LABEL }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </UiContainer>
    </UiSection>
</template>

<style scoped>
/* =========================================================
   SPICY CAMPAIGN POSTER — Phase 5 (corrected)
   Poster composition, ONE-shot reveal, then STATIC.
   Real backend promotion data only — no fake claims.
   ========================================================= */

:deep(.ui-section) {
    overflow: hidden;
    position: relative;
}

/* ===== Decorative background ===== */
.campaign-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}

.chili-blob {
    position: absolute;
    border-radius: 50%;
}

.chili-blob-1 {
    top: -10%;
    right: -8%;
    width: 360px;
    height: 360px;
    background: rgba(255, 255, 255, 0.06);
}
.chili-blob-2 {
    bottom: -15%;
    left: -10%;
    width: 320px;
    height: 320px;
    background: rgba(255, 176, 0, 0.10);
}
.chili-blob-3 {
    top: 35%;
    left: 30%;
    width: 160px;
    height: 160px;
    background: rgba(255, 255, 255, 0.04);
}

/* ===== Poster grid: copy + visual ===== */
.campaign-poster {
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: var(--space-11);
    align-items: center;
    position: relative;
    z-index: 1;
    min-height: 70vh;
}

/* ===== One-shot reveal sequence (Phase 5 Correction §5)
   Total ~900ms — surface → copy → visual → settle. Static after. */
.campaign-poster > * {
    animation: campaign-reveal 900ms var(--ease-out) both;
}
.campaign-visual { animation-delay: 200ms; }
.campaign-copy   { animation-delay: 80ms; }

@keyframes campaign-reveal {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ===== Copy column ===== */
.campaign-copy {
    max-width: 580px;
    color: var(--color-white);
}

.campaign-title {
    font-family: var(--font-display);
    font-size: clamp(48px, 7vw, 104px);
    font-weight: var(--weight-black);
    line-height: 0.95;
    letter-spacing: -0.04em;
    color: var(--color-white);
    margin: var(--space-4) 0 var(--space-5);
}

.campaign-subtitle {
    font-size: clamp(18px, 1.6vw, 22px);
    font-weight: var(--weight-bold);
    color: var(--color-golden);
    line-height: 1.35;
    margin: 0 0 var(--space-4);
}

.campaign-desc {
    font-size: 17px;
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.85);
    margin: 0 0 var(--space-7);
    max-width: 480px;
}

/* CTA */
.campaign-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-4);
}

.campaign-cta :deep(.ui-btn) {
    padding: 18px 30px;
    font-size: 16px;
    font-weight: var(--weight-extrabold);
    box-shadow: var(--shadow-md);
}

/* ===== Visual column ===== */
.campaign-visual {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.visual-frame {
    position: relative;
    width: 100%;
    max-width: 560px;
    aspect-ratio: 1;
    border-radius: 50%;
    overflow: hidden;
    background: var(--color-dark-red);
    box-shadow: 0 32px 80px rgba(0, 0, 0, 0.45);
    border: 2px solid rgba(255, 255, 255, 0.10);
}

.campaign-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* NO infinite animation (Phase 5 Correction §5) — only one-shot. */
    animation: campaign-img-reveal 700ms var(--ease-out) 300ms both;
}

@keyframes campaign-img-reveal {
    from {
        opacity: 0;
        transform: scale(1.06);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.visual-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(circle at 30% 25%, rgba(255, 220, 130, 0.30) 0%, transparent 60%),
        var(--color-dark-red);
    color: var(--color-golden);
}

.visual-fallback-label {
    font-family: var(--font-display);
    font-size: clamp(28px, 4vw, 56px);
    font-weight: var(--weight-black);
    letter-spacing: -0.02em;
}

/* =========================================================
   Tablet (1024) — stack visual below copy
   ========================================================= */
@media (max-width: 1024px) {
    .campaign-poster {
        grid-template-columns: 1fr;
        gap: var(--space-9);
        min-height: auto;
        text-align: center;
    }
    .campaign-copy {
        max-width: 100%;
        margin: 0 auto;
    }
    .campaign-desc {
        margin-inline: auto;
    }
    .campaign-actions {
        justify-content: center;
    }
    .visual-frame {
        max-width: 420px;
        margin: 0 auto;
    }
}

/* =========================================================
   Mobile (640)
   ========================================================= */
@media (max-width: 640px) {
    .campaign-title {
        font-size: clamp(38px, 11vw, 56px);
        letter-spacing: -0.03em;
        margin-bottom: var(--space-4);
    }
    .campaign-subtitle {
        font-size: 16px;
    }
    .campaign-desc {
        font-size: 15px;
        margin-bottom: var(--space-5);
    }
    .campaign-actions {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }
    .campaign-actions :deep(.ui-btn) {
        width: 100%;
        justify-content: center;
    }
    .visual-frame {
        max-width: 320px;
    }
}

/* =========================================================
   Reduced motion — final state immediately
   ========================================================= */
@media (prefers-reduced-motion: reduce) {
    .campaign-poster > *,
    .campaign-img {
        animation: none !important;
    }
}
</style>
