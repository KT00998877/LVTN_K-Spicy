<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { watch, onMounted, computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'

import { useMenuStore } from '@/stores/menu'
import { useCartStore } from '@/stores/cart'
import { useAuthStore } from '@/stores/auth'
import { useFavoritesStore } from '@/stores/favorites'
import { useToast } from '@/composables/useStaffUtils'
import { parseFiltersFromQuery, serializeFiltersToQuery } from '@/api/menu'
import type { MenuItemCard } from '@/types/home'

import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import MenuHero from '@/components/menu/MenuHero.vue'
import MenuSearchBar from '@/components/menu/MenuSearchBar.vue'
import MenuFilters from '@/components/menu/MenuFilters.vue'
import MenuGrid from '@/components/menu/MenuGrid.vue'
import MenuPagination from '@/components/menu/MenuPagination.vue'
import UiButton from '@/components/ui/UiButton.vue'
import FoodDetailModal from '@/components/menu/FoodDetailModal.vue'

const route = useRoute()
const router = useRouter()
const menuStore = useMenuStore()
const cartStore = useCartStore()
const authStore = useAuthStore()
const favoritesStore = useFavoritesStore()
const toast = useToast()

const { items, loading, loadingMore, loadMoreError, hasMore, error, filters, totalCount, activeFilterCount, categories } = storeToRefs(menuStore)
const { modalVisible, modalMenuItem, modalItem, loadingItem } = storeToRefs(cartStore)

const queryFilters = computed(() => parseFiltersFromQuery(route.query))

// Display count text
const resultText = computed(() => {
    if (loading.value) return 'Đang tải...'
    if (error.value) return ''
    if (totalCount.value === 0) return 'Không có món nào'
    return `${totalCount.value} món ngon`
})

/**
 * Khởi tạo từ URL (đầu tiên) — sau đó watcher sẽ tự sync khi URL đổi.
 */
onMounted(async () => {
    await menuStore.initFromQuery(queryFilters.value)
    // Fetch cart để biết badge count
    await cartStore.fetchCart()
    if (authStore.isAuthenticated) await favoritesStore.load()
})

watch(() => authStore.isAuthenticated, async authenticated => {
    if (authenticated) await favoritesStore.load(true)
})

async function handleToggleFavorite(item: MenuItemCard) {
    if (!authStore.isAuthenticated) {
        await router.push({ name: 'login', query: { redirect: route.fullPath } })
        return
    }
    try {
        const added = await favoritesStore.toggle(item)
        toast.success(added ? 'Đã thêm vào món yêu thích' : 'Đã bỏ khỏi món yêu thích')
    } catch {
        toast.error(favoritesStore.error ?? 'Không thể cập nhật món yêu thích.')
    }
}

/**
 * Sync filter ↔ URL (khi user đổi filter, URL tự update).
 */
watch(filters, (newFilters) => {
    const query = serializeFiltersToQuery(newFilters)
    router.replace({ query }).catch(() => {
        // ignore duplicated navigation
    })
}, { deep: true })

/**
 * Khi URL đổi (back/forward, share link) → cập nhật store.
 */
watch(queryFilters, async (newQuery) => {
    // So sánh shallow để tránh loop vô hạn
    if (JSON.stringify(newQuery) === JSON.stringify(filters.value)) return
    await menuStore.setFilters(newQuery)
}, { deep: true })

/**
 * Mở modal khi click "Thêm" trên 1 món.
 */
function handleAddToCart(item: MenuItemCard) {
    if (item.is_available === false) return
    cartStore.openAddModal({
        id: item.id,
        name: item.name,
        price: item.price,
        price_formatted: item.price_formatted,
        default_spicy: item.spicy_level,
        image_url: item.image_url,
        suggestions: item.suggestions ?? [],
    })
}

/**
 * Quick-add combo: món chính (theo tùy chỉnh trong modal) + món gợi ý đi kèm.
 */
async function handleModalAddCombo(payload: {
    main: { menu_item_id: number; quantity: number; spicy_level: number; note: string; topping_ids: number[] }
    suggested_item_id: number
}) {
    await cartStore.addCombo(payload.main, payload.suggested_item_id)
}

/**
 * Submit từ modal (thêm mới hoặc cập nhật).
 */
async function handleModalSubmit(payload: {
    menu_item_id: number
    quantity: number
    spicy_level: number
    note: string
    topping_ids: number[]
}) {
    if (modalItem.value) {
        await cartStore.updateItem(modalItem.value.id, {
            quantity: payload.quantity,
            spicy_level: payload.spicy_level,
            note: payload.note,
            topping_ids: payload.topping_ids,
        })
    } else {
        await cartStore.addItem({
            menu_item_id: payload.menu_item_id,
            quantity: payload.quantity,
            spicy_level: payload.spicy_level,
            note: payload.note,
            topping_ids: payload.topping_ids,
        })
    }
}

/**
 * Khi modal đóng → cập nhật cart count trên navbar.
 */
watch(modalVisible, async (val) => {
    if (!val) {
        await cartStore.fetchCart(true)
    }
})
useRealtimeRefresh(() => Promise.all([menuStore.loadItems(true), menuStore.loadCategories(true)]), () => !menuStore.loading && !menuStore.loadingMore, 15000)
</script>

<template>
    <div class="menu-page">
        <NavbarHome />

        <main class="menu-main">
            <!-- Hero Section -->
            <MenuHero />

            <!-- Search Section -->
            <MenuSearchBar />

            <!-- Main Content -->
            <div class="menu-content">
                <div class="menu-container">
                    <!-- Mobile Category Navigation (Sticky) -->
                    <MenuFilters />

                    <!-- Menu Section -->
                    <section class="menu-section" aria-label="Danh sách món">
                        <!-- Results Header -->
                        <header class="results-header">
                            <div class="results-count">
                                <h2 class="results-title">
                                    <span v-if="loading" class="loading-indicator">
                                        <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                        </svg>
                                        Đang tải...
                                    </span>
                                    <span v-else class="count-highlight">{{ resultText }}</span>
                                </h2>
                                <p v-if="activeFilterCount && !loading" class="active-filters-hint">
                                    {{ activeFilterCount }} bộ lọc đang áp dụng
                                </p>
                            </div>

                            <!-- Active Filter Pills -->
                            <div v-if="activeFilterCount && !loading" class="active-filters">
                                <button
                                    v-if="filters.category"
                                    type="button"
                                    class="filter-pill"
                                    @click="menuStore.setFilter('category', null)"
                                >
                                    <span>{{ categories.find(c => c.slug === filters.category)?.name || 'Danh mục' }}</span>
                                    <span class="pill-x" aria-hidden="true">✕</span>
                                </button>
                                <button
                                    v-if="filters.size"
                                    type="button"
                                    class="filter-pill"
                                    @click="menuStore.setFilter('size', null)"
                                >
                                    <span>{{ filters.size === 'SMALL' ? 'Nhỏ' : 'Lớn' }}</span>
                                    <span class="pill-x" aria-hidden="true">✕</span>
                                </button>
                                <button
                                    v-if="filters.search"
                                    type="button"
                                    class="filter-pill"
                                    @click="menuStore.setFilter('search', '')"
                                >
                                    <span>"{{ filters.search }}"</span>
                                    <span class="pill-x" aria-hidden="true">✕</span>
                                </button>
                            </div>
                        </header>

                        <!-- Error State -->
                        <div v-if="error" class="error-banner" role="alert">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            {{ error }}
                        </div>

                        <!-- Menu Grid -->
                        <MenuGrid
                            :items="items"
                            :loading="loading"
                            :favorite-ids="favoritesStore.favoriteIds"
                            :favorite-loading-ids="favoritesStore.pendingIds"
                            @reset="menuStore.resetFilters()"
                            @add-to-cart="handleAddToCart"
                            @toggle-favorite="handleToggleFavorite"
                        />

                        <!-- Pagination / Quick Categories -->
                        <div v-if="items.length" class="load-more">
                            <p role="status">Đang hiển thị {{ items.length }} / {{ totalCount }} món</p>
                            <p v-if="loadMoreError" role="alert">{{ loadMoreError }}</p>
                            <UiButton
                                v-if="hasMore"
                                :disabled="loading || loadingMore"
                                :aria-busy="loadingMore"
                                @click="menuStore.loadMore()"
                            >
                                {{ loadingMore ? 'Đang tải...' : loadMoreError ? 'Thử lại' : 'Xem thêm' }}
                            </UiButton>
                        </div>
                        <MenuPagination
                            :total="totalCount"
                            :categories="categories"
                            @category="(slug) => menuStore.setFilter('category', slug)"
                        />
                    </section>
                </div>
            </div>
        </main>

        <FooterHome />

        <!-- Food Detail Modal -->
        <FoodDetailModal
            v-model:visible="modalVisible"
            :menu-item="modalMenuItem"
            :cart-item="modalItem"
            :loading="loadingItem"
            @submit="handleModalSubmit"
            @add-combo="handleModalAddCombo"
        />
    </div>
</template>

<style scoped>
.load-more {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-3);
    margin-top: var(--space-6);
    color: var(--color-gray-600);
}
.load-more p { margin: 0; }

.menu-page {
    min-height: 100vh;
    background: var(--color-cream);
}

.menu-main {
    padding-top: var(--navbar-height);
}
@media (max-width: 768px) {
    .menu-main {
        padding-top: var(--navbar-height-mobile);
    }
}

.menu-content {
    padding: 24px 0 80px;
}

.menu-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 20px;
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 32px;
}

/* ===== RESULTS HEADER ===== */
.results-header {
    margin-bottom: 24px;
}

.results-count {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 12px;
}

.results-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--color-charcoal-black);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.count-highlight {
    color: var(--color-chili-red);
    font-size: 24px;
}

.loading-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-gray-500);
    font-size: 16px;
}

.spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.active-filters-hint {
    font-size: 14px;
    color: var(--color-gray-500);
    margin: 0;
}

/* Filter Pills */
.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: var(--space-3);
}

.filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 600;
    color: var(--color-charcoal-black);
    cursor: pointer;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        border-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out);
}

.filter-pill:hover {
    background: var(--color-chili-red);
    border-color: var(--color-chili-red);
    color: var(--color-white);
}

.pill-x {
    font-size: 10px;
    line-height: 1;
    opacity: 0.7;
}

.filter-pill:hover .pill-x {
    opacity: 1;
}

/* ===== ERROR BANNER ===== */
.error-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    background: var(--color-error-bg);
    color: var(--color-error);
    border-radius: var(--radius-lg);
    margin-bottom: 24px;
    font-weight: 500;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .menu-container {
        grid-template-columns: 1fr;
        gap: 0;
    }
    
    .menu-content {
        padding: 0 0 64px;
    }
}

@media (max-width: 640px) {
    .menu-content {
        padding: 0 0 48px;
    }
    
    .menu-container {
        padding: 0;
    }
    
    .results-header {
        padding: 0 16px;
        margin-bottom: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .spinner {
        animation: none;
    }
}
</style>
