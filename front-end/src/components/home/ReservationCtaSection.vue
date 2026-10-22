<script setup lang="ts">
/**
 * FinalCravingSection — "The Final Craving" (Phase 7)
 *
 * Design goals (Phase 7 §13-§33):
 *  - Conversion climax: "Sau tất cả những gì vừa xem, tôi muốn ăn ngay."
 *  - Spicy red / hot orange surface — strong closing punch, NOT 100vh.
 *  - One strong food image (signature noodle bowl).
 *  - Primary: "Đặt món ngay" → /thuc-don (ORDER > reservation).
 *  - Secondary: "Đặt bàn" → /dat-ban (lower visual weight).
 *  - NO reservation form, NO date picker, NO guest count.
 *  - NO multi-layer hero choreography.
 *  - Restrained motion (3/5).
 *
 * Image:
 *  - Uses /storage/menu/mi-cay-hai-san.png as PRODUCTION fallback
 *    (real business asset). No dedicated Final CTA asset exists yet.
 *
 * Reuses: UiSection bg="chili", UiContainer, UiButton.
 */
import { computed, ref } from 'vue'
import { imageUrl } from '@/utils/image'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiButton from '@/components/ui/UiButton.vue'

const mealImage = computed(() => imageUrl('/storage/menu/mi-cay-hai-san.png'))
const imgFailed = ref(false)
function onImgError() { imgFailed.value = true }
const visibleSrc = computed(() => imgFailed.value ? '' : mealImage.value)
</script>

<template>
    <UiSection
        id="final-craving"
        bg="chili"
        padding="default"
        aria-labelledby="craving-title"
    >
        <!-- Subtle warm glow — editorial, not dramatic -->
        <div class="craving-bg" aria-hidden="true">
            <span class="glow glow-1"></span>
            <span class="glow glow-2"></span>
        </div>

        <UiContainer>
            <div class="craving-grid">

                <!-- ===== LEFT — copy ===== -->
                <div class="craving-copy">
                    <h2 id="craving-title" class="craving-title">
                        Đói rồi<br/>
                        <span class="accent">đúng không?</span>
                    </h2>

                    <p class="craving-sub">
                        Tô mì cay nóng hổi, topping đầy đặn,<br class="br-desk"/>
                        đang chờ bạn tại K-SPICY.
                    </p>

                    <div class="craving-actions">
                        <!-- Primary: ĐẶT MÓN (ORDER > reservation — Phase 7 §21) -->
                        <UiButton
                            as="router-link"
                            to="/thuc-don"
                            variant="white"
                            size="lg"
                            class="btn-primary"
                        >
                            Đặt món ngay
                            <svg width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </UiButton>

                        <!-- Secondary: ĐẶT BÀN (lower weight — Phase 7 §21) -->
                        <UiButton
                            as="router-link"
                            to="/dat-ban"
                            variant="outline-white"
                            size="lg"
                            class="btn-secondary"
                        >
                            Đặt bàn
                        </UiButton>
                    </div>
                </div>

                <!-- ===== RIGHT — food visual ===== -->
                <div class="craving-visual">
                    <div class="visual-frame">
                        <img
                            v-if="visibleSrc"
                            :src="visibleSrc"
                            alt="Tô mì cay K-SPICY"
                            class="craving-img"
                            loading="lazy"
                            decoding="async"
                            width="800"
                            height="800"
                            @error="onImgError"
                        />
                        <div v-else class="visual-fallback" aria-hidden="true">
                            <span class="fallback-mark">K·SPICY</span>
                        </div>
                    </div>
                </div>
            </div>
        </UiContainer>
    </UiSection>
</template>

<style scoped>
/* =========================================================
   THE FINAL CRAVING — Phase 7
   Spicy red surface, single food image, conversion focus.
   ========================================================= */

:deep(.ui-section) {
    overflow: hidden;
    position: relative;
}

/* ===== Warm glow accents ===== */
.craving-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}

.glow {
    position: absolute;
    border-radius: 50%;
}

.glow-1 {
    top: -100px;
    right: -80px;
    width: 440px;
    height: 440px;
    background: radial-gradient(circle, rgba(255, 200, 80, 0.20) 0%, transparent 70%);
}

.glow-2 {
    bottom: -120px;
    left: -60px;
    width: 360px;
    height: 360px;
    background: radial-gradient(circle, rgba(255, 80, 20, 0.18) 0%, transparent 70%);
}

/* ===== Layout ===== */
.craving-grid {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: var(--space-11);
    align-items: center;
    position: relative;
    z-index: 1;
    min-height: 70vh;
    padding: var(--space-10) 0;
}

/* ===== Copy column ===== */
.craving-copy {
    max-width: 580px;
    color: var(--color-white);
}

.craving-title {
    font-family: var(--font-display);
    font-size: clamp(56px, 8vw, 120px);
    font-weight: var(--weight-black);
    line-height: 0.95;
    letter-spacing: -0.04em;
    color: var(--color-white);
    margin: 0 0 var(--space-6);
}

.craving-title .accent {
    color: var(--color-golden);
    font-style: italic;
}

.craving-sub {
    font-size: clamp(16px, 1.5vw, 20px);
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.88);
    margin: 0 0 var(--space-8);
    max-width: 440px;
}

.br-desk { display: inline; }

/* ===== CTA buttons ===== */
.craving-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-4);
}

.btn-primary :deep(.ui-btn) {
    padding: 18px 32px;
    font-size: 16px;
    font-weight: var(--weight-extrabold);
    box-shadow: var(--shadow-md);
    letter-spacing: 0.01em;
}

/* Secondary — ghost on red: transparent bg, white border */
.btn-secondary :deep(.ui-btn) {
    padding: 16px 28px;
    font-size: 16px;
    font-weight: var(--weight-bold);
    background: transparent;
    color: var(--color-white);
    border: 1.5px solid rgba(255, 255, 255, 0.45);
}

/* ===== Visual column ===== */
.craving-visual {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.visual-frame {
    position: relative;
    width: 100%;
    max-width: 480px;
    aspect-ratio: 1;
    border-radius: 50%;
    overflow: hidden;
    background: rgba(0, 0, 0, 0.20);
    box-shadow: 0 32px 80px rgba(0, 0, 0, 0.45);
    border: 2px solid rgba(255, 255, 255, 0.12);
}

.craving-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    animation: craving-reveal 700ms var(--ease-out) 150ms both;
}

@keyframes craving-reveal {
    from { opacity: 0; transform: scale(1.06); }
    to   { opacity: 1; transform: scale(1); }
}

.visual-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(circle at 30% 25%, rgba(255, 220, 130, 0.25) 0%, transparent 60%),
        var(--color-dark-red);
    color: var(--color-golden);
}

.fallback-mark {
    font-family: var(--font-display);
    font-size: clamp(32px, 5vw, 56px);
    font-weight: var(--weight-black);
    letter-spacing: -0.02em;
}

/* =========================================================
   Tablet (1024) — stack visual below copy
   ========================================================= */
@media (max-width: 1023px) {
    .craving-grid {
        grid-template-columns: 1fr;
        gap: var(--space-9);
        min-height: auto;
        text-align: center;
        padding: var(--space-8) 0;
    }
    .craving-copy {
        max-width: 100%;
        margin: 0 auto;
    }
    .craving-sub { margin-inline: auto; }
    .craving-actions {
        justify-content: center;
    }
    .visual-frame { max-width: 360px; margin: 0 auto; }
}

/* =========================================================
   Mobile (640)
   ========================================================= */
@media (max-width: 640px) {
    .craving-title {
        font-size: clamp(42px, 12vw, 64px);
        letter-spacing: -0.03em;
        margin-bottom: var(--space-4);
    }
    .craving-sub {
        font-size: 15px;
        margin-bottom: var(--space-6);
    }
    .br-desk { display: none; }
    .craving-actions {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        gap: var(--space-3);
    }
    .craving-actions :deep(.ui-btn) {
        width: 100%;
        justify-content: center;
        /* Phase 9 §59: primary ≥ 52px touch target. */
        min-height: 52px;
    }
    .btn-primary :deep(.ui-btn) {
        padding: 16px 24px;
        font-size: 15px;
    }
    .btn-secondary :deep(.ui-btn) {
        padding: 16px;
    }
    .visual-frame { max-width: 260px; }
    /* Ensure title not clipped — Vietnamese diacritics safe. */
    .craving-title {
        line-height: 1.0;
        overflow: visible;
    }
}

/* =========================================================
   Reduced motion — instant final state
   ========================================================= */
@media (prefers-reduced-motion: reduce) {
    .craving-img {
        animation: none !important;
    }
}
</style>
