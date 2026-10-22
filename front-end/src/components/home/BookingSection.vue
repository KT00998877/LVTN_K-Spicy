<script setup lang="ts">
import { computed } from 'vue'
import ReservationForm from '@/components/ReservationForm.vue'
import { imageUrl } from '@/utils/image'

const bgImage = computed(() => imageUrl('/storage/brand/restaurant.jpg'))

</script>

<template>
    <section class="booking-section fade-up">
        <!-- Background Image with Overlay -->
        <div class="booking-bg">
            <img :src="bgImage" alt="Không gian nhà hàng" @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/1920x800/111/333'" />
            <div class="overlay"></div>
        </div>

        <div class="container">
            <div class="booking-container">
                <div class="booking-card">
                    <div class="booking-header">
                        <h2>Đặt bàn trực tuyến</h2>
                        <p>Giữ chỗ ngay hôm nay để thưởng thức bữa ăn tuyệt vời</p>
                    </div>

                    <ReservationForm />
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.booking-section {
    position: relative;
    padding: 120px 0;
    margin-top: 40px;
}

.booking-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
}

.booking-bg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to right, rgba(17,17,17,0.9) 0%, rgba(17,17,17,0.6) 100%);
}

.booking-container {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: flex-start;
}

.booking-card {
    background: var(--color-white);
    padding: 48px;
    border-radius: var(--radius-2xl);
    width: 100%;
    max-width: 550px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.booking-header {
    margin-bottom: 32px;
}

.booking-header h2 {
    font-size: 32px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}

.booking-header p {
    color: var(--color-gray-500);
    font-size: 15px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 32px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-charcoal-black);
}

.form-group input,
.form-group select {
    padding: 14px 16px;
    border: 1px solid var(--color-gray-300);
    border-radius: var(--radius-lg);
    font-size: 15px;
    color: var(--color-charcoal-black);
    background-color: var(--color-white);
    font-family: inherit;
    transition: all 0.2s;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.1);
}

.btn-submit {
    width: 100%;
    padding: 16px;
    font-size: 16px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-red);
}

.booking-note {
    text-align: center;
    color: var(--color-gray-500);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

@media (max-width: 768px) {
    .booking-section {
        padding: 60px 0;
    }
    .booking-container {
        justify-content: center;
    }
    .booking-card {
        padding: 32px 24px;
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>
