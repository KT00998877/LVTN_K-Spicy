<script setup lang="ts">
import { useHomeStore } from '@/stores/home'
import ReservationForm from '@/components/ReservationForm.vue'
const store = useHomeStore()
</script>

<template>
    <section id="reservation" class="reservation">
        <div class="container res-grid">
            <div class="res-info">
                <span class="eyebrow eyebrow-dark">Đặt bàn trước</span>
                <h2 class="res-title">Đặt bàn ngay hôm nay</h2>
                <p class="res-desc">
                    Vui lòng đặt bàn trước ít nhất 1 giờ.
                    Đội ngũ nhà hàng sẽ liên hệ xác nhận trong thời gian sớm nhất.
                </p>
                <ul class="res-contact">
                    <li>
                        <span class="contact-label">Địa chỉ</span>
                        <span>{{ store.restaurant?.address }}</span>
                    </li>
                    <li>
                        <span class="contact-label">Hotline</span>
                        <span>{{ store.restaurant?.phone }}</span>
                    </li>
                    <li>
                        <span class="contact-label">Giờ mở cửa</span>
                        <span>{{ store.restaurant?.open_hours }}</span>
                    </li>
                </ul>
            </div>

            <ReservationForm />
        </div>
    </section>
</template>

<style scoped>
.reservation {
    background: var(--color-charcoal-black);
    color: var(--color-white);
    padding: var(--section-y) 0;
}

.res-grid {
    display: grid;
    grid-template-columns: 1fr 1.4fr;
    gap: var(--space-11);
    align-items: start;
}

.eyebrow-dark {
    color: var(--color-golden);
}

.eyebrow-dark::before {
    background: var(--color-golden);
}

.res-title {
    font-size: clamp(28px, 3.5vw, 40px);
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: var(--space-3) 0 var(--space-4);
    line-height: 1.15;
}

.res-desc {
    color: var(--color-gray-300);
    line-height: 1.65;
    margin-bottom: var(--space-7);
    max-width: 480px;
    font-size: 16px;
}

.res-contact {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.res-contact li {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 14px 16px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-md);
}

.contact-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--color-gray-400);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.res-contact li > span:last-child {
    color: var(--color-white);
    font-size: 14px;
    font-weight: 500;
}

.res-form {
    background: var(--color-cream);
    color: var(--color-charcoal-black);
    padding: var(--space-7);
    border-radius: var(--radius-lg);
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    border: 1px solid var(--color-gray-200);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
}

.form-row-3 {
    grid-template-columns: 1fr 1fr 1fr;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.res-form input,
.res-form textarea {
    padding: 12px 14px;
    border: 1.5px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    font-size: 14px;
    font-family: inherit;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
    width: 100%;
}

.res-form input:focus,
.res-form textarea:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.12);
}

.form-actions {
    display: flex;
    gap: var(--space-3);
    flex-wrap: wrap;
    align-items: center;
    margin-top: var(--space-3);
}

.submit-btn {
    flex: 1;
    min-width: 180px;
}

.form-msg {
    margin: 0;
    padding: 12px 14px;
    border-radius: var(--radius-md);
    font-size: 14px;
    line-height: 1.5;
}

.form-msg.ok  { background: var(--color-success-bg); color: #166534; border: 1px solid #bbf7d0; }
.form-msg.err { background: var(--color-error-bg); color: #991b1b; border: 1px solid #fecaca; }

/* ===== Location picker ===== */
.location-picker {
    border: 0;
    padding: 0;
    margin: 0;
}

.legend-text {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--space-3);
    display: block;
}

.legend-hint {
    display: block;
    font-weight: 400;
    color: var(--color-gray-500);
    text-transform: none;
    letter-spacing: 0;
    font-size: 12px;
    margin-top: 2px;
}

.location-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-2);
}

.location-option {
    position: relative;
    cursor: pointer;
}

.location-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.location-card {
    padding: 14px;
    border: 1.5px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    background: var(--color-white);
    transition: border-color var(--dur-fast) var(--ease-out), background-color var(--dur-fast) var(--ease-out);
    text-align: center;
}

.location-option:hover .location-card {
    border-color: var(--color-chili-red);
}

.location-option input:checked + .location-card {
    border-color: var(--color-chili-red);
    background: rgba(215, 25, 32, 0.05);
}

.location-option input:focus-visible + .location-card {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

.location-name {
    font-weight: 700;
    font-size: 14px;
    margin-bottom: 2px;
    color: var(--color-charcoal-black);
}

.location-desc {
    font-size: 11px;
    color: var(--color-gray-500);
}

.suggestion {
    margin: var(--space-3) 0 0;
    padding: 10px 12px;
    background: rgba(255, 176, 0, 0.1);
    border: 1px solid rgba(255, 176, 0, 0.3);
    border-radius: var(--radius-md);
    font-size: 13px;
    color: #92400e;
}

@media (max-width: 900px) {
    .res-grid {
        grid-template-columns: 1fr;
        gap: var(--space-7);
    }
    .form-row,
    .form-row-3 {
        grid-template-columns: 1fr;
    }
    .location-options {
        grid-template-columns: 1fr;
    }
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
}

@media (max-width: 640px) {
    .reservation {
        padding: var(--section-y-mobile) 0;
    }
    .res-form {
        padding: var(--space-5);
    }
}
</style>