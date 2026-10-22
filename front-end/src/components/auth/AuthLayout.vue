<script setup lang="ts">
defineProps<{
    /** Câu chào bên trái (hero) */
    title: string
    subtitle: string
    bullets?: string[]
}>()
</script>

<template>
    <div class="au-page">
        <!-- LEFT: hero -->
        <section class="au-hero">
            <div class="au-hero-content">
                <router-link to="/" class="au-hero-brand" style="text-decoration: none; color: #fff;">
                    <img src="/logo/logo.png" class="au-hero-brand-mark" width="80" height="80" alt="K-SPICY" />
                    <span>Mì Cay Hàn Quốc</span>
                </router-link>
                <h1 class="au-hero-title">{{ title }}</h1>
                <p class="au-hero-sub">{{ subtitle }}</p>

                <ul v-if="bullets && bullets.length" class="au-hero-bullets">
                    <li v-for="b in bullets" :key="b">{{ b }}</li>
                </ul>
            </div>

            <div class="au-hero-footer">
                © 2026 Mì Cay Hàn Quốc — Korean Spice House
            </div>
        </section>

        <!-- RIGHT: form -->
        <section class="au-form-side">
            <div class="au-form-wrap">
                <slot />
            </div>
        </section>
    </div>
</template>
