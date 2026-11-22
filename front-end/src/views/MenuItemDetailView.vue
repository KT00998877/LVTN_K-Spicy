<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { fetchMenuItemBySlug } from '@/api/menu'
import FooterHome from '@/components/home/FooterHome.vue'
import NavbarHome from '@/components/home/NavbarHome.vue'
import ReviewSection from '@/components/review/ReviewSection.vue'
import { imageUrl } from '@/utils/image'
import '@/components/home/home.css'

const route = useRoute()
const slug = computed(() => String(route.params.slug ?? ''))
const item = ref<Awaited<ReturnType<typeof fetchMenuItemBySlug>> | null>(null)
const loading = ref(true)
const notFound = ref(false)
const retry = ref(0)

watch([slug, retry], async ([value], _previous, onCleanup) => {
    let active = true
    onCleanup(() => { active = false })
    item.value = null
    notFound.value = false
    loading.value = true
    try {
        const detail = await fetchMenuItemBySlug(value)
        if (active) item.value = detail
    } catch (error) {
        if (active) notFound.value = (error as { response?: { status?: number } })?.response?.status === 404
    } finally {
        if (active) loading.value = false
    }
}, { immediate: true })
</script>

<template>
    <NavbarHome />
    <main class="dish-page hp-container">
        <RouterLink class="dish-back" :to="{ name: 'menu' }">← Thực đơn</RouterLink>
        <p v-if="loading" role="status">Đang tải chi tiết món…</p>
        <template v-else-if="item">
            <div class="dish-intro">
                <img class="dish-photo" :src="imageUrl(item.image_url)" :alt="item.name" />
                <div class="dish-copy">
                    <p class="dish-category">{{ item.category_name }}</p>
                    <h1>{{ item.name }}</h1>
                    <p class="dish-price">{{ item.price_formatted }}</p>
                    <p v-if="!item.is_available" class="dish-availability">{{ item.sold_out_reason || 'Món đang tạm hết' }}</p>
                    <p v-if="item.description" class="dish-description">{{ item.description }}</p>
                </div>
            </div>
            <ReviewSection :key="item.id" :menu-item="item" />
        </template>
        <p v-else-if="notFound" role="status">Không tìm thấy món này.</p>
        <div v-else>
            <p role="alert">Không tải được chi tiết món.</p>
            <button type="button" class="hp-btn hp-btn-outline" @click="retry++">Thử lại</button>
        </div>
    </main>
    <FooterHome />
</template>

<style scoped>
.dish-page { max-width: 1120px; min-height: 55vh; padding-block: 32px 72px; }
.dish-back { display: inline-block; margin-bottom: 24px; color: var(--color-chili-red); font-weight: 700; }
.dish-intro {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: clamp(24px, 5vw, 64px);
    align-items: center;
    margin-bottom: 36px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--color-gray-200);
}
.dish-photo { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: var(--radius-lg); }
.dish-copy h1 { margin: 6px 0 12px; font-size: clamp(28px, 4vw, 44px); line-height: 1.15; }
.dish-category { font-weight: 600; color: var(--color-gray-600); }
.dish-price { color: var(--color-chili-red); font-size: 24px; font-weight: 800; }
.dish-description { margin-top: 16px; line-height: 1.7; white-space: pre-line; }
.dish-availability { margin-top: 8px; font-weight: 600; }
@media (max-width: 700px) {
    .dish-page { padding-block: 20px 48px; }
    .dish-intro { grid-template-columns: 1fr; gap: 20px; margin-bottom: 24px; padding-bottom: 24px; }
}
</style>
