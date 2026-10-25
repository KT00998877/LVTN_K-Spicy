<script setup lang="ts">
import { defineAsyncComponent, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useHomeStore } from '@/stores/home'

import NavbarHome from '@/components/home/NavbarHome.vue'
import HeroSection from '@/components/home/HeroSection.vue'
import UiLazySection from '@/components/ui/UiLazySection.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import HomeLoading from '@/components/home/HomeLoading.vue'
import PromoBanner from '@/components/customer/PromoBanner.vue'

const CategoriesSection = defineAsyncComponent(() => import('@/components/home/CategoriesSection.vue'))
const FeaturedSection = defineAsyncComponent(() => import('@/components/home/FeaturedSection.vue'))
const SpicyLevelSection = defineAsyncComponent(() => import('@/components/home/SpicyLevelSection.vue'))
const ComboPromotionSection = defineAsyncComponent(() => import('@/components/home/ComboPromotionSection.vue'))
const MenuDiscoverySection = defineAsyncComponent(() => import('@/components/home/MenuDiscoverySection.vue'))
const RestaurantStorySection = defineAsyncComponent(() => import('@/components/home/RestaurantStorySection.vue'))
const ReservationCtaSection = defineAsyncComponent(() => import('@/components/home/ReservationCtaSection.vue'))

import '@/components/home/home.css'

const store = useHomeStore()
const { loading, error, isReady } = storeToRefs(store)

onMounted(() => {
    store.load()
})
</script>

<template>
    <NavbarHome />
    <main>
        <HeroSection />
        <HomeLoading
            :loading="loading && !isReady"
            :error="error"
            @retry="store.refresh()"
        />
        <UiLazySection><CategoriesSection /></UiLazySection>
        <section class="promo-strip">
            <PromoBanner />
        </section>
        <UiLazySection><FeaturedSection /></UiLazySection>
        <UiLazySection><ComboPromotionSection /></UiLazySection>
        <UiLazySection><SpicyLevelSection /></UiLazySection>
        <UiLazySection><MenuDiscoverySection /></UiLazySection>
        <UiLazySection><RestaurantStorySection /></UiLazySection>
        <UiLazySection><ReservationCtaSection /></UiLazySection>
    </main>
    <FooterHome />
</template>

<style scoped>
main {
    min-height: 100vh;
}

/* Banner ưu đãi: dải nền kem để nổi lên nhưng không cạnh tranh với hero. */
.promo-strip {
    max-width: 1240px;
    margin: 0 auto;
    padding: 28px 20px 8px;
}
</style>
