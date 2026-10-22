<script setup lang="ts">
import { ref } from 'vue'

const reviews = [
    {
        id: 1,
        name: 'Hoàng Minh',
        date: '2 ngày trước',
        rating: 5,
        text: 'Nước dùng cực kỳ đậm đà, mì dai ngon. Mình ăn cấp độ 3 là vừa phải, rất kích thích vị giác. Không gian quán đẹp và phục vụ chu đáo.',
        avatar: 'https://i.pravatar.cc/150?u=1'
    },
    {
        id: 2,
        name: 'Thu Trang',
        date: '1 tuần trước',
        rating: 5,
        text: 'Lần đầu thử cấp độ 5 và thực sự bùng nổ! Rất cay nhưng không thể ngừng ăn. Topping hải sản tươi rói, phô mai béo ngậy. Sẽ quay lại nhiều lần nữa.',
        avatar: 'https://i.pravatar.cc/150?u=2'
    },
    {
        id: 3,
        name: 'Tuấn Tú',
        date: '2 tuần trước',
        rating: 4,
        text: 'Quán không gian trẻ trung, nhân viên nhiệt tình dắt xe. Đồ ăn ra nhanh. Điểm trừ nhỏ là cuối tuần khá đông nên cần đặt bàn trước.',
        avatar: 'https://i.pravatar.cc/150?u=3'
    }
]

const currentIndex = ref(0)

function next() {
    currentIndex.value = (currentIndex.value + 1) % reviews.length
}

function prev() {
    currentIndex.value = (currentIndex.value - 1 + reviews.length) % reviews.length
}
</script>

<template>
    <section class="review-section section-padding fade-up">
        <div class="container">
            <div class="section-header text-center">
                <h2 class="section-title">Khách hàng nói gì về chúng tôi?</h2>
                <p class="section-subtitle">Hơn 10,000+ thực khách đã trải nghiệm và hài lòng.</p>
            </div>

            <div class="review-slider">
                <button class="nav-btn prev-btn" @click="prev" aria-label="Previous">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
                
                <div class="review-track-container">
                    <div class="review-track" :style="{ transform: `translateX(-${currentIndex * 100}%)` }">
                        <div v-for="review in reviews" :key="review.id" class="review-slide">
                            <div class="review-card">
                                <div class="quote-icon">"</div>
                                <div class="review-stars">
                                    <span v-for="i in review.rating" :key="i">⭐</span>
                                </div>
                                <p class="review-text">{{ review.text }}</p>
                                <div class="reviewer-info">
                                    <img :src="review.avatar" :alt="review.name" class="reviewer-avatar" />
                                    <div>
                                        <div class="reviewer-name">{{ review.name }}</div>
                                        <div class="review-date">{{ review.date }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button class="nav-btn next-btn" @click="next" aria-label="Next">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </div>
            
            <div class="slider-dots">
                <button 
                    v-for="(r, idx) in reviews" 
                    :key="idx" 
                    class="dot" 
                    :class="{ active: currentIndex === idx }"
                    @click="currentIndex = idx"
                    aria-label="Go to slide"
                ></button>
            </div>
        </div>
    </section>
</template>

<style scoped>
.review-section {
    padding: 100px 0;
    background-color: var(--color-cream);
}

.section-header {
    margin-bottom: 56px;
}

.section-title {
    font-size: 36px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin-bottom: 16px;
    letter-spacing: -0.5px;
}

.section-subtitle {
    font-size: 18px;
    color: var(--color-gray-500);
}

.review-slider {
    display: flex;
    align-items: center;
    gap: 24px;
    max-width: 900px;
    margin: 0 auto;
}

.review-track-container {
    overflow: hidden;
    flex: 1;
}

.review-track {
    display: flex;
    transition: transform 0.5s cubic-bezier(0.25, 1, 0.5, 1);
}

.review-slide {
    min-width: 100%;
    padding: 20px;
}

.review-card {
    background-color: var(--color-white);
    padding: 48px;
    border-radius: var(--radius-2xl);
    box-shadow: var(--shadow-md);
    position: relative;
    text-align: center;
}

.quote-icon {
    position: absolute;
    top: 24px;
    left: 48px;
    font-size: 80px;
    color: var(--color-cream);
    font-family: serif;
    line-height: 1;
    z-index: 0;
}

.review-stars {
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
}

.review-text {
    font-size: 18px;
    line-height: 1.8;
    color: var(--color-charcoal-black);
    margin-bottom: 32px;
    position: relative;
    z-index: 1;
    font-style: italic;
}

.reviewer-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
}

.reviewer-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    object-fit: cover;
}

.reviewer-name {
    font-weight: 700;
    font-size: 16px;
    color: var(--color-charcoal-black);
}

.review-date {
    font-size: 13px;
    color: var(--color-gray-500);
}

.nav-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background-color: var(--color-white);
    border: 1px solid var(--color-gray-300);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--color-charcoal-black);
    transition: all 0.2s;
    flex-shrink: 0;
}

.nav-btn:hover {
    background-color: var(--color-chili-red);
    color: var(--color-white);
    border-color: var(--color-chili-red);
}

.slider-dots {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 32px;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: var(--color-gray-300);
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.dot.active {
    background-color: var(--color-chili-red);
    transform: scale(1.3);
}

@media (max-width: 768px) {
    .review-section { padding: 80px 0; }
    .section-title { font-size: 28px; }
    .review-card { padding: 32px 24px; }
    .nav-btn { display: none; }
    .quote-icon { left: 24px; }
}
</style>
