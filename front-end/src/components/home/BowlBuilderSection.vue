<script setup lang="ts">
/**
 * BowlBuilderSection — "Tô mì của riêng bạn"
 *
 * Replaces the previous text + 4-stats block.
 *
 * Layout: 3-step horizontal diagram.
 *  1. Chọn sợi mì  (pick your noodle base)
 *  2. Chọn cấp độ cay  (pick your spice — links to /thuc-don?spicy=N)
 *  3. Chọn topping  (pick your toppings — opens cart modal on click)
 *
 * Reuses: UiSection, UiContainer, UiEyebrow, UiButton, UiReveal.
 */
import { useRouter } from 'vue-router'
import UiSection from '@/components/ui/UiSection.vue'
import UiContainer from '@/components/ui/UiContainer.vue'
import UiEyebrow from '@/components/ui/UiEyebrow.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiReveal from '@/components/ui/UiReveal.vue'

const router = useRouter()

interface Step {
    num: number
    title: string
    desc: string
    cta: string
    to: string
    icon: string
}

const STEPS: Step[] = [
    {
        num: 1,
        title: 'Chọn sợi mì',
        desc: 'Mì gạo, mì vàng, mì Udon — mỗi loại một cảm giác dai ngon khác nhau.',
        cta: 'Xem các loại mì',
        to: '/thuc-don',
        icon: `<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12c2-3 5-5 8-5s6 2 8 5"/><path d="M4 16c2-3 5-5 8-5s6 2 8 5"/><path d="M4 8c2-3 5-5 8-5s6 2 8 5"/></svg>`,
    },
    {
        num: 2,
        title: 'Chọn cấp độ cay',
        desc: 'Từ nhẹ nhàng đến cực hạn — bảy cấp độ cho mỗi khẩu vị.',
        cta: 'Chọn cấp cay',
        to: '/thuc-don',
        icon: `<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c2 3 5 5 5 9a5 5 0 1 1-10 0c0-4 3-6 5-9z"/><path d="M12 7v6M10 11h4"/></svg>`,
    },
    {
        num: 3,
        title: 'Chọn topping',
        desc: 'Hải sản, thịt, rau, phô mai — tươi mỗi ngày, thêm tùy thích.',
        cta: 'Xem topping',
        to: '/thuc-don',
        icon: `<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1.2"/><circle cx="15" cy="10" r="1.2"/><circle cx="12" cy="15" r="1.2"/><path d="M12 3v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4"/></svg>`,
    },
]

function go(to: string) {
    router.push(to)
}
</script>

<template>
    <UiSection id="bowl-builder" bg="cream-dark">
        <UiContainer>
            <UiReveal>
                <header class="head">
                    <UiEyebrow>Tự chọn topping</UiEyebrow>
                    <h2 class="title">Tô mì của riêng bạn.</h2>
                    <p class="desc">
                        Mì cay chuẩn vị Hàn Quốc với topping hải sản, thịt, rau và phô mai.
                        Ba bước đơn giản — cay vừa, topping vừa, tô vừa ý.
                    </p>
                </header>
            </UiReveal>

            <ol class="steps" aria-label="Các bước tạo tô mì">
                <UiReveal
                    v-for="(step, idx) in STEPS"
                    :key="step.num"
                    :delay="idx * 120"
                    as="div"
                >
                    <li class="step">
                        <div class="step-num" aria-hidden="true">{{ step.num }}</div>
                        <div class="step-icon" aria-hidden="true" v-html="step.icon"></div>
                        <h3 class="step-title">{{ step.title }}</h3>
                        <p class="step-desc">{{ step.desc }}</p>
                        <UiButton variant="outline" size="sm" @click="go(step.to)">
                            {{ step.cta }}
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </UiButton>
                    </li>
                </UiReveal>
            </ol>

            <UiReveal :delay="400">
                <div class="bottom">
                    <UiButton variant="primary" size="lg" as="router-link" to="/thuc-don">
                        Bắt đầu chọn món
                    </UiButton>
                </div>
            </UiReveal>
        </UiContainer>
    </UiSection>
</template>

<style scoped>
.head {
    margin-bottom: var(--space-10);
    max-width: 720px;
}

.title {
    font-size: clamp(28px, 3.5vw, 44px);
    font-weight: 800;
    color: var(--color-charcoal-black);
    letter-spacing: -0.5px;
    margin-top: var(--space-3);
    line-height: 1.15;
}

.desc {
    margin-top: var(--space-4);
    font-size: 16px;
    color: var(--color-gray-600);
    line-height: 1.65;
    max-width: 580px;
}

/* ===== 3-step diagram ===== */
.steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-5);
    position: relative;
}

.step {
    position: relative;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    padding: var(--space-7);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    transition:
        transform var(--dur-base) var(--ease-out),
        box-shadow var(--dur-base) var(--ease-out),
        border-color var(--dur-base) var(--ease-out);
}

.step:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: transparent;
}

/* Step card becomes a focus target when the inner CTA receives focus. */
.step:focus-within {
    border-color: var(--color-chili-red);
    box-shadow: var(--shadow-md);
}

.step:focus-within .step-num {
    background: var(--color-dark-red);
}

/* Connecting line between steps (desktop only). */
.step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 56px;
    right: -28px;
    width: 16px;
    height: 2px;
    background: var(--color-chili-red);
    opacity: 0.3;
    z-index: 1;
}

.step-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--color-chili-red);
    color: var(--color-white);
    font-weight: 800;
    font-size: 14px;
    font-variant-numeric: tabular-nums;
}

.step-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    background: var(--color-cream);
    border-radius: var(--radius-md);
    color: var(--color-chili-red);
    margin-top: var(--space-2);
}

.step-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    letter-spacing: -0.3px;
    line-height: 1.25;
}

.step-desc {
    font-size: 14px;
    color: var(--color-gray-600);
    line-height: 1.6;
    flex: 1;
}

.step :deep(.ui-btn) {
    align-self: flex-start;
    margin-top: var(--space-2);
}

.bottom {
    display: flex;
    justify-content: center;
    margin-top: var(--space-9);
}

/* ===== Tablet ===== */
@media (max-width: 900px) {
    .steps { grid-template-columns: 1fr; gap: var(--space-4); }
    .step:not(:last-child)::after { display: none; }
}

/* ===== Mobile ===== */
@media (max-width: 640px) {
    .step { padding: var(--space-5); }
    .step :deep(.ui-btn) { width: 100%; }
}
</style>
