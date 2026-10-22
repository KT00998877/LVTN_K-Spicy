<template>
    <section v-if="items.length" class="campaign-banner" aria-label="Khuyến mãi đang chạy">
        <div class="container">
            <div class="banner-head">
                <span class="eyebrow">🎉 TIN KHUYẾN MÃI</span>
                <h2>Đang có ưu đãi hấp dẫn</h2>
            </div>
            <div class="grid">
                <RouterLink
                    v-for="c in items"
                    :key="c.id"
                    :to="`/tin-vui/tin-khuyen-mai/${c.slug}`"
                    class="card"
                >
                    <div class="cover">
                        <img v-if="c.cover_image_url" :src="c.cover_image_url" :alt="c.name" loading="lazy" />
                        <div v-else class="cover-fallback">
                            <span>{{ initials(c.name) }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <h3>{{ c.name }}</h3>
                        <p v-if="c.description" class="muted">{{ truncate(c.description, 100) }}</p>
                        <small v-if="c.ends_at" class="muted small">
                            Đến {{ formatDate(c.ends_at) }}
                        </small>
                    </div>
                </RouterLink>
            </div>
            <RouterLink to="/tin-vui/tin-khuyen-mai" class="see-all">
                Xem tất cả chiến dịch →
            </RouterLink>
        </div>
    </section>
</template>

<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { PublicCampaign } from '@/types/campaign'

interface Props {
    items: PublicCampaign[]
}
defineProps<Props>()

function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('')
}

function truncate(s: string, n: number): string {
    return s.length <= n ? s : s.slice(0, n).trimEnd() + '…'
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}
</script>

<style scoped>
.campaign-banner {
    padding: 36px 0;
    background: linear-gradient(135deg, rgba(217, 25, 32, 0.04), rgba(255, 87, 76, 0.02));
}
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.banner-head .eyebrow {
    display: inline-block;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
    margin-bottom: 6px;
}
.banner-head h2 {
    margin: 0;
    font-size: 26px;
    font-weight: 800;
    color: var(--charcoal, #1a1a1a);
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}
.card {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 14px;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    transition: transform 0.16s ease, box-shadow 0.16s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(26, 14, 6, 0.08);
}
.cover {
    aspect-ratio: 16 / 9;
    background: rgba(26, 14, 6, 0.05);
    overflow: hidden;
}
.cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.cover-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--chili, #d71920), #ff574c);
    color: #fff;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: -1px;
}
.card-body {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.card-body h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: var(--charcoal, #1a1a1a);
}
.muted {
    margin: 0;
    color: #6b5f54;
    font-size: 13px;
    line-height: 1.4;
}
.muted.small {
    font-size: 11px;
}
.see-all {
    align-self: flex-start;
    color: var(--chili, #d71920);
    font-weight: 700;
    text-decoration: none;
    font-size: 14px;
}
.see-all:hover {
    text-decoration: underline;
}
</style>
