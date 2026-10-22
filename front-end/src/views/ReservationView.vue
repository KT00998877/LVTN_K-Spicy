<script setup lang="ts">
import { imageUrl } from '@/utils/image'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import ReservationForm from '@/components/ReservationForm.vue'
</script>

<template>
    <div class="reservation-page">
        <NavbarHome />
        
        <main class="page-content">
            <div class="reservation-container">
                <div class="reservation-image">
                    <img :src="imageUrl('/storage/brand/restaurant.jpg')" alt="Không gian nhà hàng" />
                    <div class="image-overlay">
                        <h2>K-SPICY</h2>
                        <p>Trải nghiệm ẩm thực Hàn Quốc chuẩn vị trong không gian hiện đại, sang trọng.</p>
                    </div>
                </div>
                
                <div class="reservation-form-wrapper">
                    <div class="form-header">
                        <h1 class="page-title">Đặt bàn</h1>
                        <p class="page-subtitle">Vui lòng cung cấp thông tin để chúng tôi chuẩn bị đón tiếp bạn tốt nhất.</p>
                    </div>
                    
                    <ReservationForm />
                </div>
            </div>
        </main>
        
        <FooterHome />
    </div>
</template>

<style scoped>
.reservation-page {
    background-color: var(--color-white);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.page-content {
    flex: 1;
    padding-top: var(--navbar-height);
    display: flex;
    align-items: stretch;
}

@media (max-width: 768px) {
    .page-content {
        padding-top: var(--navbar-height-mobile);
    }
}

.reservation-container {
    display: flex;
    width: 100%;
    min-height: calc(100vh - 80px);
}

.reservation-image {
    flex: 1;
    position: relative;
    display: none;
}

@media (min-width: 992px) {
    .reservation-image {
        display: block;
    }
}

.reservation-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: absolute;
    inset: 0;
}

.image-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, color-mix(in srgb, var(--color-brand-dark) 90%, transparent), color-mix(in srgb, var(--color-brand-dark) 45%, transparent));
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 60px;
    color: var(--color-white);
}

.image-overlay h2 {
    font-size: 48px;
    font-weight: 800;
    margin-bottom: 16px;
    letter-spacing: -1px;
}

.image-overlay p {
    font-size: 18px;
    line-height: 1.6;
    max-width: 400px;
    opacity: 0.9;
}

.reservation-form-wrapper {
    flex: 1;
    max-width: 700px;
    padding: var(--space-9) var(--space-7);
    display: flex;
    flex-direction: column;
    justify-content: center;
    background-color: var(--color-cream);
}

@media (min-width: 992px) {
    .reservation-form-wrapper {
        padding: var(--space-12);
    }
}

.form-header {
    margin-bottom: var(--space-9);
}

.page-title {
    font-size: clamp(28px, 3.5vw, 36px);
    font-weight: 800;
    color: var(--color-brand-ink);
    margin-bottom: var(--space-3);
    letter-spacing: -0.5px;
    line-height: 1.2;
}

.page-subtitle {
    font-size: 16px;
    color: var(--color-gray-500);
    line-height: 1.6;
    max-width: 480px;
}

.booking-form {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
}

@media (min-width: 576px) {
    .form-row {
        grid-template-columns: 1fr 1fr 1fr;
    }
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

label {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-charcoal-black);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

input, select, textarea {
    width: 100%;
    padding: 16px;
    background-color: var(--color-gray-100);
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    font-family: inherit;
    font-size: 15px;
    color: var(--color-charcoal-black);
    transition: all 0.2s ease;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    background-color: var(--color-white);
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.1);
}

input::placeholder, textarea::placeholder {
    color: var(--color-gray-400);
}

/* Custom Radio Group */
.radio-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

@media (min-width: 576px) {
    .radio-group {
        flex-direction: row;
    }
}

.radio-label {
    flex: 1;
    position: relative;
    cursor: pointer;
}

.radio-label input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.radio-text {
    display: block;
    padding: 14px;
    text-align: center;
    background-color: var(--color-gray-100);
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    font-size: 14px;
    font-weight: 500;
    color: var(--color-gray-600);
    transition: all 0.2s ease;
}

.radio-label:hover .radio-text {
    background-color: var(--color-gray-200);
}

.radio-label input:checked + .radio-text {
    background-color: var(--color-white);
    border-color: var(--color-chili-red);
    color: var(--color-chili-red);
    font-weight: 600;
}

.submit-btn {
    padding: 18px;
    font-size: 16px;
    font-weight: 600;
    margin-top: 16px;
    width: 100%;
}

.submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.message-box {
    margin-top: 16px;
    padding: 16px;
    border-radius: var(--radius-md);
    font-size: 15px;
    line-height: 1.5;
}

.message-box.success {
    background: var(--color-success-bg);
    color: #166534;
    border: 1px solid #bbf7d0;
}

.message-box.error {
    background: var(--color-error-bg);
    color: #991b1b;
    border: 1px solid #fecaca;
}
</style>
