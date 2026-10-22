<script setup lang="ts">
import { computed } from 'vue'
import { imageUrl } from '@/utils/image'

// Demo images
const images = computed(() => [
    imageUrl('/storage/brand/restaurant.jpg'),
    imageUrl('/storage/menu/mi-cay-hai-san.png'),
    imageUrl('/storage/menu/kimbap-truyen-thong.png'),
    imageUrl('/storage/menu/ga-ran-han-quoc.png'),
    imageUrl('/storage/tables/tables.png'),
])
</script>

<template>
    <section class="gallery-section section-padding fade-up">
        <div class="container">
            <div class="gallery-layout">
                <div class="gallery-content">
                    <h2 class="section-title">Không chỉ là một bữa ăn</h2>
                    <p class="section-desc">
                        Không gian hiện đại, trẻ trung và ấm cúng – nơi bạn có thể thưởng thức món ngon cùng bạn bè và gia đình. Trải nghiệm dịch vụ chu đáo trong từng chi tiết.
                    </p>
                    <router-link to="/khong-gian" class="btn btn-outline">Khám phá nhà hàng</router-link>
                </div>
                
                <div class="gallery-masonry">
                    <div class="masonry-item item-1">
                        <img :src="images[0]" alt="Không gian nhà hàng" loading="lazy" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/600x400?text=Không+gian'" />
                    </div>
                    <div class="masonry-item item-2">
                        <img :src="images[1]" alt="Bàn ăn" loading="lazy" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/400x600?text=Món+ngon'" />
                    </div>
                    <div class="masonry-item item-3">
                        <img :src="images[2]" alt="Món mì cay" loading="lazy" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/400x400?text=Phục+vụ'" />
                    </div>
                    <div class="masonry-item item-4">
                        <img :src="images[3]" alt="Khách hàng" loading="lazy" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/600x600?text=Check-in'" />
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.gallery-section {
    padding: 120px 0;
    background-color: var(--color-white);
}

.gallery-layout {
    display: grid;
    grid-template-columns: 35% 1fr;
    gap: 64px;
    align-items: center;
}

.section-title {
    font-size: 40px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin-bottom: 24px;
    letter-spacing: -1px;
    line-height: 1.1;
}

.section-desc {
    font-size: 16px;
    color: var(--color-gray-500);
    line-height: 1.7;
    margin-bottom: 40px;
}

.gallery-masonry {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    grid-template-rows: repeat(12, 1fr);
    gap: 16px;
    height: 600px;
}

.masonry-item {
    border-radius: var(--radius-2xl);
    overflow: hidden;
    position: relative;
    box-shadow: var(--shadow-md);
    transition: transform 0.3s ease;
}

.masonry-item:hover {
    transform: translateY(-8px);
    box-shadow: var(--shadow-xl);
    z-index: 2;
}

.masonry-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.masonry-item:hover img {
    transform: scale(1.05);
}

.item-1 { grid-column: 1 / 8; grid-row: 1 / 7; }
.item-2 { grid-column: 8 / 13; grid-row: 1 / 8; }
.item-3 { grid-column: 1 / 6; grid-row: 7 / 13; }
.item-4 { grid-column: 6 / 13; grid-row: 8 / 13; }

@media (max-width: 1024px) {
    .gallery-layout {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    .gallery-content {
        text-align: center;
        max-width: 600px;
        margin: 0 auto;
    }
    .gallery-masonry {
        height: 500px;
    }
}

@media (max-width: 768px) {
    .gallery-section { padding: 80px 0; }
    .section-title { font-size: 32px; }
    .gallery-masonry {
        height: auto;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto;
    }
    .item-1, .item-2, .item-3, .item-4 {
        grid-column: span 1;
        grid-row: span 1;
        aspect-ratio: 1;
    }
    .item-1 { grid-column: 1 / -1; aspect-ratio: 2 / 1; }
}
</style>
