<script setup lang="ts">
import type { CategoryItem } from '@/types/home'

defineProps<{
    total: number
    categories: CategoryItem[]
}>()

defineEmits<{
    (e: 'category', slug: string): void
}>()
</script>

<template>
    <div class="menu-footer-section">
        <!-- Divider with icon -->
        <div class="section-divider">
            <span class="divider-line"></span>
            <span class="divider-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
                </svg>
            </span>
            <span class="divider-line"></span>
        </div>
        
        <!-- Footer Content -->
        <div class="footer-content">
            <p class="footer-message">
                <span class="message-highlight">{{ total }}+</span> món ngon đang chờ bạn khám phá
            </p>
            
            <!-- Quick Category Links -->
            <div v-if="categories.length > 1" class="quick-categories">
                <span class="quick-label">Chuyển nhanh:</span>
                <div class="quick-links">
                    <button
                        v-for="cat in categories.slice(0, 6)"
                        :key="cat.id"
                        type="button"
                        class="quick-link"
                        @click="$emit('category', cat.slug)"
                    >
                        {{ cat.name }}
                    </button>
                </div>
            </div>
            
            <!-- Restaurant Info -->
            <div class="restaurant-info">
                <div class="info-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <span>Hotline: 0901 234 567</span>
                </div>
                <div class="info-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Mở cửa: 10:00 - 22:00</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.menu-footer-section {
    margin-top: 64px;
    padding-top: 48px;
}

/* Divider */
.section-divider {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
}

.divider-line {
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--color-gray-200), transparent);
}

.divider-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-chili-red);
    color: var(--color-white);
    border-radius: 50%;
    box-shadow: 0 4px 16px rgba(215, 25, 32, 0.3);
}

/* Footer Content */
.footer-content {
    text-align: center;
}

.footer-message {
    font-size: 18px;
    color: var(--color-gray-600);
    margin: 0 0 24px;
    line-height: 1.6;
}

.message-highlight {
    color: var(--color-chili-red);
    font-weight: 800;
    font-size: 24px;
}

/* Quick Categories */
.quick-categories {
    margin-bottom: 32px;
}

.quick-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-400);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 12px;
}

.quick-links {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
}

.quick-link {
    padding: 8px 16px;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-full);
    color: var(--color-charcoal-black);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.quick-link:hover {
    background: var(--color-charcoal-black);
    border-color: var(--color-charcoal-black);
    color: var(--color-white);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Restaurant Info */
.restaurant-info {
    display: flex;
    justify-content: center;
    gap: 32px;
    padding: 24px;
    background: var(--color-white);
    border-radius: var(--radius-xl);
    border: 1px solid var(--color-gray-100);
}

.info-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-gray-600);
    font-size: 14px;
}

.info-item svg {
    color: var(--color-chili-red);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 640px) {
    .menu-footer-section {
        margin-top: 48px;
        padding-top: 32px;
    }
    
    .section-divider {
        margin-bottom: 24px;
    }
    
    .divider-icon {
        width: 40px;
        height: 40px;
    }
    
    .divider-icon svg {
        width: 20px;
        height: 20px;
    }
    
    .footer-message {
        font-size: 16px;
    }
    
    .message-highlight {
        font-size: 20px;
    }
    
    .quick-link {
        padding: 6px 12px;
        font-size: 13px;
    }
    
    .restaurant-info {
        flex-direction: column;
        gap: 12px;
        padding: 20px;
    }
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
    .quick-link {
        transition: none;
    }
    
    .quick-link:hover {
        transform: none;
    }
}
</style>
