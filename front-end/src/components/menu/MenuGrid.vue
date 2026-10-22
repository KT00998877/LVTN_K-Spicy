<script setup lang="ts">
import type { MenuItemCard } from '@/types/home'
import MenuCard from './MenuCard.vue'

defineProps<{
    items: MenuItemCard[]
    loading: boolean
}>()

const emit = defineEmits<{
    reset: []
    addToCart: [item: MenuItemCard]
}>()

function handleReset() {
    emit('reset')
}
</script>

<template>
    <div class="menu-grid-wrapper">
        <!-- Loading Skeleton -->
        <div v-if="loading && items.length === 0" class="menu-grid" aria-busy="true" aria-label="Đang tải danh sách món">
            <div v-for="i in 9" :key="i" class="skeleton-card">
                <div class="skeleton-image"></div>
                <div class="skeleton-body">
                    <div class="skeleton-line skeleton-tag"></div>
                    <div class="skeleton-line skeleton-title"></div>
                    <div class="skeleton-line skeleton-desc"></div>
                    <div class="skeleton-footer">
                        <div class="skeleton-line skeleton-price"></div>
                        <div class="skeleton-btn"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else-if="!loading && items.length === 0" class="empty-state" role="status">
            <div class="empty-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <h3 class="empty-title">Không tìm thấy món phù hợp</h3>
            <p class="empty-message">
                Thử bỏ bớt bộ lọc hoặc tìm với từ khóa khác để khám phá thêm món ngon.
            </p>
            <button class="empty-btn" type="button" @click="handleReset">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10"></polyline>
                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                </svg>
                Xóa bộ lọc
            </button>
        </div>

        <!-- Grid -->
        <div v-else class="menu-grid">
            <MenuCard
                v-for="item in items"
                :key="item.id"
                :item="item"
                @add-to-cart="emit('addToCart', item)"
            />
        </div>
    </div>
</template>

<style scoped>
.menu-grid-wrapper {
    min-height: 400px;
}

/* ===== MENU GRID ===== */
.menu-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

/* ===== SKELETON LOADING ===== */
.skeleton-card {
    background: var(--color-white);
    border-radius: var(--radius-xl);
    overflow: hidden;
    border: 1px solid var(--color-gray-100);
}

.skeleton-image {
    height: 200px;
    background: linear-gradient(
        90deg,
        var(--color-gray-100) 0%,
        var(--color-gray-50) 50%,
        var(--color-gray-100) 100%
    );
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
}

.skeleton-body {
    padding: 18px;
}

.skeleton-line {
    background: linear-gradient(
        90deg,
        var(--color-gray-100) 0%,
        var(--color-gray-50) 50%,
        var(--color-gray-100) 100%
    );
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
    border-radius: 6px;
}

.skeleton-tag {
    width: 80px;
    height: 12px;
    margin-bottom: 12px;
}

.skeleton-title {
    width: 90%;
    height: 20px;
    margin-bottom: 8px;
}

.skeleton-desc {
    width: 100%;
    height: 14px;
    margin-bottom: 16px;
}

.skeleton-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 14px;
    border-top: 1px solid var(--color-gray-100);
}

.skeleton-price {
    width: 80px;
    height: 24px;
}

.skeleton-btn {
    width: 44px;
    height: 44px;
    background: linear-gradient(
        90deg,
        var(--color-gray-200) 0%,
        var(--color-gray-100) 50%,
        var(--color-gray-200) 100%
    );
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
    border-radius: var(--radius-lg);
}

@keyframes shimmer {
    0% {
        background-position: -200% 0;
    }
    100% {
        background-position: 200% 0;
    }
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 80px 40px;
    background: var(--color-cream);
    border-radius: var(--radius-xl);
    border: 2px dashed var(--color-gray-200);
}

.empty-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-white);
    border-radius: 50%;
    color: var(--color-gray-300);
}

.empty-title {
    color: var(--color-charcoal-black);
    font-size: 22px;
    font-weight: 800;
    margin: 0 0 12px;
}

.empty-message {
    color: var(--color-gray-500);
    margin: 0 0 28px;
    font-size: 15px;
    line-height: 1.6;
    max-width: 360px;
    margin-left: auto;
    margin-right: auto;
}

.empty-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    background: var(--color-charcoal-black);
    color: var(--color-white);
    border: none;
    border-radius: var(--radius-full);
    cursor: pointer;
    font-weight: 600;
    font-size: 15px;
    transition: all 0.25s ease;
}

.empty-btn:hover {
    background: var(--color-chili-red);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(215, 25, 32, 0.3);
}

.empty-btn:active {
    transform: translateY(0);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
}

@media (max-width: 640px) {
    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    
    .empty-state {
        padding: 60px 24px;
    }
    
    .empty-icon {
        width: 80px;
        height: 80px;
    }
    
    .empty-title {
        font-size: 20px;
    }
}

@media (max-width: 480px) {
    .menu-grid {
        grid-template-columns: 1fr;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .skeleton-image,
    .skeleton-line,
    .skeleton-btn {
        animation: none;
        background: var(--color-gray-100);
    }
    
    .empty-btn:hover {
        transform: none;
    }
}
</style>
