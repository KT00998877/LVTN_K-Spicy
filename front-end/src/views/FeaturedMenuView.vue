<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useFavoritesStore } from '@/stores/favorites'
import { useHomeStore } from '@/stores/home'
import { useToast } from '@/composables/useStaffUtils'
import { imageUrl } from '@/utils/image'
import type { MenuItemCard } from '@/types/home'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import FoodDetailModal from '@/components/menu/FoodDetailModal.vue'
import UiButton from '@/components/ui/UiButton.vue'
import '@/assets/featured-menu.css'

const FALLBACK_IMAGE = '/storage/menu/mi-cay-hai-san.png'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const cartStore = useCartStore()
const favoritesStore = useFavoritesStore()
const homeStore = useHomeStore()
const toast = useToast()

const { modalVisible, modalMenuItem, modalItem, loadingItem } = storeToRefs(cartStore)

const featuredItems = computed<MenuItemCard[]>(() => [
    ...homeStore.featured.filter(item => item.is_available !== false),
    ...homeStore.featured.filter(item => item.is_available === false),
])
const leadItem = computed(() => featuredItems.value[0] ?? null)
const supportingItems = computed(() => featuredItems.value.slice(1))
const isLoading = computed(() => homeStore.loading && featuredItems.value.length === 0)
const hasError = computed(() => !isLoading.value && !!homeStore.error && featuredItems.value.length === 0)

onMounted(async () => {
    await homeStore.load()
    if (authStore.isAuthenticated) await favoritesStore.load()
})

watch(() => authStore.isAuthenticated, async authenticated => {
    if (authenticated) await favoritesStore.load(true)
})

function itemImage(item: MenuItemCard): string {
    return imageUrl(item.image_url) || FALLBACK_IMAGE
}

function handleImageError(event: Event) {
    const image = event.target as HTMLImageElement
    if (image.dataset.fallback) return
    image.dataset.fallback = 'true'
    image.src = FALLBACK_IMAGE
}

function isFavorite(item: MenuItemCard): boolean {
    return favoritesStore.favoriteIds.includes(item.id)
}

function isFavoritePending(item: MenuItemCard): boolean {
    return favoritesStore.pendingIds.includes(item.id)
}

function spicyLabel(item: MenuItemCard): string {
    return item.spicy_level > 0 ? `Cấp cay ${item.spicy_level}` : 'Không cay'
}

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

async function openItem(item: MenuItemCard) {
    if (item.is_available === false) return
    if (!authStore.isAuthenticated) {
        await router.push({ name: 'login', query: { redirect: route.fullPath } })
        return
    }

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

async function handleModalSubmit(payload: {
    menu_item_id: number
    quantity: number
    spicy_level: number
    note: string
    topping_ids: number[]
}) {
    await cartStore.addItem(payload)
}

async function handleModalAddCombo(payload: {
    main: { menu_item_id: number; quantity: number; spicy_level: number; note: string; topping_ids: number[] }
    suggested_item_id: number
}) {
    await cartStore.addCombo(payload.main, payload.suggested_item_id)
}
</script>

<template>
    <div class="feature-menu-page">
        <NavbarHome />

        <main id="main-content" class="feature-menu-main">
            <section class="feature-menu-cover" aria-labelledby="feature-menu-title">
                <div class="feature-menu-shell">
                    <header class="feature-menu-masthead">
                        <p class="feature-menu-kicker">BẾP TRƯỞNG TUYỂN CHỌN</p>
                        <h1 id="feature-menu-title">
                            <span>Món</span>
                            <span>đặc sắc</span>
                        </h1>
                        <p class="feature-menu-intro">
                            Những hương vị đại diện cho K‑SPICY, được chọn trực tiếp từ thực đơn hiện tại.
                        </p>
                    </header>

                    <div v-if="isLoading" class="feature-menu-loading" aria-busy="true" aria-label="Đang tải món đặc sắc">
                        <div class="feature-menu-loading-image"></div>
                        <div class="feature-menu-loading-copy">
                            <span></span><span></span><span></span>
                        </div>
                    </div>

                    <div v-else-if="hasError" class="feature-menu-state feature-menu-state--error" role="alert">
                        <p>{{ homeStore.error }}</p>
                        <UiButton variant="primary" @click="homeStore.load(true)">Thử tải lại</UiButton>
                    </div>

                    <div v-else-if="!leadItem" class="feature-menu-state" role="status">
                        <span class="feature-menu-state-number" aria-hidden="true">00</span>
                        <h2>Bếp đang chọn món mới</h2>
                        <p>Hiện chưa có món nào được đánh dấu đặc sắc. Bạn vẫn có thể khám phá toàn bộ thực đơn.</p>
                        <UiButton as="router-link" to="/thuc-don" variant="primary">Xem thực đơn</UiButton>
                    </div>

                    <article v-else class="feature-menu-lead">
                        <div class="feature-menu-lead-media">
                            <img
                                :src="itemImage(leadItem)"
                                :alt="`Món ${leadItem.name}`"
                                width="1440"
                                height="630"
                                fetchpriority="high"
                                decoding="async"
                                @error="handleImageError"
                            />
                            <span class="feature-menu-lead-number" aria-hidden="true">01</span>
                            <span v-if="leadItem.is_available === false" class="feature-menu-sold-out">Tạm hết</span>
                            <button
                                type="button"
                                class="feature-menu-favorite feature-menu-favorite--lead"
                                :class="{ 'is-active': isFavorite(leadItem) }"
                                :aria-label="isFavorite(leadItem) ? `Bỏ ${leadItem.name} khỏi món yêu thích` : `Thêm ${leadItem.name} vào món yêu thích`"
                                :aria-pressed="isFavorite(leadItem)"
                                :aria-busy="isFavoritePending(leadItem)"
                                :disabled="isFavoritePending(leadItem)"
                                @click="handleToggleFavorite(leadItem)"
                            >
                                <svg width="22" height="22" viewBox="0 0 24 24" :fill="isFavorite(leadItem) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" />
                                </svg>
                            </button>
                        </div>

                        <div class="feature-menu-lead-caption">
                            <div class="feature-menu-lead-heading">
                                <p>{{ leadItem.category_name }}</p>
                                <h2>{{ leadItem.name }}</h2>
                            </div>
                            <p class="feature-menu-lead-description">
                                {{ leadItem.description || 'Một lựa chọn tiêu biểu được bếp trưởng đề xuất trong thực đơn K‑SPICY.' }}
                            </p>
                            <div class="feature-menu-lead-order">
                                <div class="feature-menu-meta" aria-label="Thông tin món">
                                    <span>{{ spicyLabel(leadItem) }}</span>
                                    <span v-if="leadItem.preparation_minutes">{{ leadItem.preparation_minutes }} phút</span>
                                </div>
                                <strong>{{ leadItem.price_formatted }}</strong>
                                <UiButton
                                    variant="primary"
                                    size="lg"
                                    :disabled="leadItem.is_available === false"
                                    @click="openItem(leadItem)"
                                >
                                    {{ leadItem.is_available === false ? 'Tạm hết' : 'Chọn món' }}
                                </UiButton>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section v-if="supportingItems.length" class="feature-menu-index" aria-labelledby="feature-menu-index-title">
                <div class="feature-menu-shell">
                    <header class="feature-menu-index-head">
                        <div>
                            <p>THE CHEF'S EDIT</p>
                            <h2 id="feature-menu-index-title">Danh sách tuyển chọn</h2>
                        </div>
                        <span>{{ String(featuredItems.length).padStart(2, '0') }} món</span>
                    </header>

                    <ol class="feature-menu-list" start="2">
                        <li v-for="(item, index) in supportingItems" :key="item.id" class="feature-menu-row">
                            <span class="feature-menu-row-number" aria-hidden="true">
                                {{ String(index + 2).padStart(2, '0') }}
                            </span>

                            <button
                                type="button"
                                class="feature-menu-row-image"
                                :aria-label="item.is_available === false ? `${item.name} đang tạm hết` : `Chọn món ${item.name}`"
                                :disabled="item.is_available === false"
                                @click="openItem(item)"
                            >
                                <img
                                    :src="itemImage(item)"
                                    :alt="`Món ${item.name}`"
                                    width="360"
                                    height="240"
                                    loading="lazy"
                                    decoding="async"
                                    @error="handleImageError"
                                />
                                <span v-if="item.is_available === false" class="feature-menu-sold-out">Tạm hết</span>
                            </button>

                            <div class="feature-menu-row-copy">
                                <p>{{ item.category_name }}</p>
                                <h3>{{ item.name }}</h3>
                                <p>{{ item.description || 'Một món được bếp trưởng K‑SPICY tuyển chọn.' }}</p>
                                <div class="feature-menu-meta">
                                    <span>{{ spicyLabel(item) }}</span>
                                    <span v-if="item.preparation_minutes">{{ item.preparation_minutes }} phút</span>
                                </div>
                            </div>

                            <div class="feature-menu-row-actions">
                                <strong>{{ item.price_formatted }}</strong>
                                <div>
                                    <button
                                        type="button"
                                        class="feature-menu-favorite"
                                        :class="{ 'is-active': isFavorite(item) }"
                                        :aria-label="isFavorite(item) ? `Bỏ ${item.name} khỏi món yêu thích` : `Thêm ${item.name} vào món yêu thích`"
                                        :aria-pressed="isFavorite(item)"
                                        :aria-busy="isFavoritePending(item)"
                                        :disabled="isFavoritePending(item)"
                                        @click="handleToggleFavorite(item)"
                                    >
                                        <svg width="20" height="20" viewBox="0 0 24 24" :fill="isFavorite(item) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" />
                                        </svg>
                                    </button>
                                    <UiButton
                                        variant="outline"
                                        :disabled="item.is_available === false"
                                        @click="openItem(item)"
                                    >
                                        {{ item.is_available === false ? 'Tạm hết' : 'Chọn món' }}
                                    </UiButton>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>

            <section v-if="leadItem" class="feature-menu-outro" aria-label="Khám phá thêm">
                <div class="feature-menu-shell feature-menu-outro-inner">
                    <p>Muốn tự chọn theo khẩu vị?</p>
                    <UiButton as="router-link" to="/thuc-don" variant="white" size="lg">
                        Xem toàn bộ thực đơn
                    </UiButton>
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
