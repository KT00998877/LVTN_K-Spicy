<script setup lang="ts">
import { computed } from 'vue'
import { imageUrl } from '@/utils/image'

const socialImages = computed(() => [
    imageUrl('/storage/brand/restaurant.jpg'),
    imageUrl('/storage/menu/mi-cay-hai-san.png'),
    imageUrl('/storage/menu/tokbokki-pho-mai.png'),
    imageUrl('/storage/menu/ga-ran-sot-cay.png'),
    imageUrl('/storage/menu/soju-dao.png'),
])
</script>

<template>
    <section class="social-section fade-up">
        <div class="container text-center">
            <h2 class="section-title">Theo dõi chúng tôi trên Instagram</h2>
            <p class="section-subtitle">
                Cập nhật những khoảnh khắc mới nhất và tag <strong>@kspicy.vn</strong> để được feature!
            </p>
        </div>

        <div class="social-grid">
            <a href="#" class="social-item" v-for="(img, index) in socialImages" :key="index">
                <img :src="img" alt="Instagram Post" loading="lazy" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/400x400/333/fff?text=IG'" />
                <div class="social-overlay">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                        <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                        <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                    </svg>
                </div>
            </a>
        </div>
        
        <div class="social-footer">
            <a href="#" class="btn btn-primary">
                Theo dõi @kspicy.vn
            </a>
        </div>
    </section>
</template>

<style scoped>
.social-section {
    padding: 100px 0 0;
    background-color: var(--color-white);
}

.section-title {
    font-size: 32px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin-bottom: 12px;
    letter-spacing: -0.5px;
}

.section-subtitle {
    font-size: 16px;
    color: var(--color-gray-500);
    margin-bottom: 48px;
}

.social-grid {
    display: flex;
    flex-wrap: nowrap;
    overflow: hidden;
}

.social-item {
    position: relative;
    flex: 1 1 20%;
    aspect-ratio: 1;
    overflow: hidden;
}

.social-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.social-overlay {
    position: absolute;
    inset: 0;
    background-color: rgba(215, 25, 32, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-white);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.social-item:hover img {
    transform: scale(1.1);
}

.social-item:hover .social-overlay {
    opacity: 1;
}

.social-footer {
    display: flex;
    justify-content: center;
    padding: 60px 0;
    background-color: var(--color-cream);
}

@media (max-width: 992px) {
    .social-item:nth-child(5) { display: none; }
    .social-item { flex: 1 1 25%; }
}

@media (max-width: 768px) {
    .social-section { padding-top: 80px; }
    .social-item:nth-child(4) { display: none; }
    .social-item { flex: 1 1 33.333%; }
}

@media (max-width: 480px) {
    .social-item:nth-child(3) { display: none; }
    .social-item { flex: 1 1 50%; }
}
</style>
