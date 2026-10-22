<script setup lang="ts">
import { imageUrl } from '@/utils/image'
</script>

<template>
    <section class="menu-hero">
        <div class="container hero-grid">
            <div class="hero-content fade-up">
                <span class="eyebrow eyebrow-dark">Thực đơn K-SPICY</span>

                <h1 class="hero-title">
                    Thực đơn
                    <span class="hero-title-accent">K-SPICY</span>
                </h1>

                <p class="hero-subtitle">
                    Hơn <strong>50+ món ăn</strong> đa dạng mang đậm tinh túy ẩm thực
                    đường phố Hàn Quốc.
                </p>

                <ul class="hero-features" aria-label="Đặc trưng">
                    <li>
                        <span class="dot dot-1" aria-hidden="true"></span>
                        7 cấp độ cay
                    </li>
                    <li>
                        <span class="dot dot-2" aria-hidden="true"></span>
                        Mì cay trứ danh
                    </li>
                    <li>
                        <span class="dot dot-3" aria-hidden="true"></span>
                        Tokbokki chuẩn vị
                    </li>
                </ul>
            </div>

            <div class="hero-visual fade-up">
                <div class="visual-frame">
                    <img
                        :src="imageUrl('/storage/brand/hero-bg.jpg')"
                        alt="Món ăn Hàn Quốc"
                        class="hero-image"
                    />
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.menu-hero {
    background: var(--color-brand-dark);
    color: var(--color-white);
    padding: calc(72px + var(--space-9)) 0 var(--space-11);
    overflow: hidden;
}

.hero-grid {
    display: grid;
    grid-template-columns: 5fr 6fr;
    gap: var(--space-9);
    align-items: center;
}

.hero-content {
    max-width: 540px;
}

.eyebrow-dark {
    color: var(--color-golden);
}

.eyebrow-dark::before {
    background: var(--color-golden);
}

.hero-title {
    font-size: clamp(40px, 5vw, 56px);
    font-weight: 900;
    margin: var(--space-3) 0 var(--space-4);
    line-height: 1.05;
    letter-spacing: -1.5px;
    color: var(--color-white);
}

.hero-title-accent {
    display: block;
    color: var(--color-brand-highlight);
    font-size: clamp(48px, 6vw, 72px);
    letter-spacing: -2px;
}

.hero-subtitle {
    font-size: 17px;
    color: var(--color-gray-300);
    line-height: 1.6;
    margin-bottom: var(--space-7);
    max-width: 460px;
}

.hero-subtitle strong {
    color: var(--color-golden);
    font-weight: 700;
}

.hero-features {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
}

.hero-features li {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-full);
    color: var(--color-gray-200);
    font-size: 14px;
    font-weight: 500;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.dot-1 { background: var(--color-spicy-1); }
.dot-2 { background: var(--color-spicy-3); }
.dot-3 { background: var(--color-chili-red); }

/* Visual */
.hero-visual {
    display: flex;
    justify-content: center;
}

.visual-frame {
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
}

.hero-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@media (max-width: 900px) {
    .hero-grid {
        grid-template-columns: 1fr;
        gap: var(--space-7);
    }
}

@media (max-width: 640px) {
    .menu-hero {
        padding: calc(64px + var(--space-7)) 0 var(--space-9);
    }
}
</style>
