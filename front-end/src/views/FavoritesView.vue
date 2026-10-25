<script setup lang="ts">
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useFavoritesStore } from '@/stores/favorites'
import { useCartStore } from '@/stores/cart'
import { useToast } from '@/composables/useStaffUtils'
import type { MenuItemCard } from '@/types/home'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import MenuGrid from '@/components/menu/MenuGrid.vue'
import FoodDetailModal from '@/components/menu/FoodDetailModal.vue'

const router = useRouter()
const favoritesStore = useFavoritesStore()
const cartStore = useCartStore()
const toast = useToast()
const { items, loading, error, pendingIds } = storeToRefs(favoritesStore)
const { modalVisible, modalMenuItem, modalItem, loadingItem } = storeToRefs(cartStore)

onMounted(() => favoritesStore.load(true))

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

async function handleToggleFavorite(item: MenuItemCard) {
    try {
        await favoritesStore.toggle(item)
        toast.success('Đã bỏ khỏi món yêu thích')
    } catch {
        toast.error(favoritesStore.error ?? 'Không thể cập nhật món yêu thích.')
    }
}

async function handleModalAddCombo(payload: {
    main: { menu_item_id: number; quantity: number; spicy_level: number; note: string; topping_ids: number[] }
    suggested_item_id: number
}) {
    await cartStore.addCombo(payload.main, payload.suggested_item_id)
}

async function handleModalSubmit(payload: {
    menu_item_id: number
    quantity: number
    spicy_level: number
    note: string
    topping_ids: number[]
}) {
    await cartStore.addItem(payload)
}
</script>

<template>
    <div class="favorites-page">
        <NavbarHome />
        <main class="favorites-main">
            <header class="favorites-heading">
                <p class="favorites-eyebrow">BỘ SƯU TẬP CỦA BẠN</p>
                <h1>Món yêu thích</h1>
                <p>Lưu lại những món bạn muốn gọi lần sau.</p>
            </header>

            <section class="favorites-content" aria-labelledby="favorites-title">
                <h2 id="favorites-title" class="sr-only">Danh sách món yêu thích</h2>

                <div v-if="error" class="favorites-state favorites-state--error" role="alert">
                    <p>{{ error }}</p>
                    <button type="button" @click="favoritesStore.load(true)">Thử lại</button>
                </div>

                <MenuGrid
                    v-else-if="loading || items.length"
                    :items="items"
                    :loading="loading"
                    :favorite-ids="favoritesStore.favoriteIds"
                    :favorite-loading-ids="pendingIds"
                    @reset="router.push('/thuc-don')"
                    @add-to-cart="handleAddToCart"
                    @toggle-favorite="handleToggleFavorite"
                />

                <div v-else class="favorites-state" role="status">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" />
                    </svg>
                    <h2>Chưa có món yêu thích</h2>
                    <p>Chạm biểu tượng trái tim trên món ăn để lưu vào đây.</p>
                    <button type="button" @click="router.push('/thuc-don')">Khám phá thực đơn</button>
                </div>
            </section>
        </main>
        <FooterHome />

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
.favorites-page { min-height: 100vh; background: var(--color-cream); }
.favorites-main { padding-top: var(--navbar-height); min-height: 70vh; }
.favorites-heading {
    padding: var(--space-10) var(--space-5) var(--space-8);
    text-align: center;
    color: var(--color-charcoal-black);
}
.favorites-eyebrow { margin: 0 0 var(--space-3); color: var(--color-chili-red); font-size: 13px; font-weight: 800; letter-spacing: 0.12em; }
.favorites-heading h1 { margin: 0; font-size: clamp(32px, 5vw, 52px); line-height: 1.1; }
.favorites-heading > p:last-child { margin: var(--space-4) 0 0; color: var(--color-gray-600); font-size: 16px; }
.favorites-content { max-width: 1180px; margin: 0 auto; padding: 0 var(--space-5) var(--space-10); }
.favorites-state {
    max-width: 620px;
    margin: var(--space-8) auto;
    padding: var(--space-10) var(--space-6);
    text-align: center;
    color: var(--color-charcoal-black);
    background: var(--color-white);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
}
.favorites-state svg { color: var(--color-chili-red); }
.favorites-state h2 { margin: var(--space-4) 0 var(--space-2); }
.favorites-state p { color: var(--color-gray-600); }
.favorites-state button {
    min-height: 44px;
    margin-top: var(--space-4);
    padding: 0 var(--space-5);
    border: 0;
    border-radius: var(--radius-full);
    color: var(--color-white);
    background: var(--color-chili-red);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
.favorites-state button:hover { background: var(--color-dark-red); }
.favorites-state button:active { transform: scale(0.98); }
.favorites-state button:focus-visible { outline: 3px solid var(--color-chili-red); outline-offset: 3px; }
.favorites-state--error { color: var(--status-error-text); background: var(--color-error-bg); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 768px) {
    .favorites-main { padding-top: var(--navbar-height-mobile); }
    .favorites-heading { padding-block: var(--space-8) var(--space-6); }
    .favorites-content { padding-inline: var(--space-4); }
}
@media (prefers-reduced-motion: reduce) {
    .favorites-state button:active { transform: none; }
}
</style>
