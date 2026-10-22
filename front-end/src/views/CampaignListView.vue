<template>
    <NavbarHome />
    <main class="campaign-list">
        <header class="page-hero">
            <span>MÌ CAY HÀN QUỐC</span>
            <h1>Tin khuyến mãi</h1>
            <p>Tổng hợp các chương trình giảm giá, combo và ưu đãi đang chạy tại nhà hàng.</p>
        </header>

        <p v-if="store.loading" class="state" role="status">Đang tải chiến dịch…</p>
        <p v-else-if="store.error" class="state error" role="alert">
            {{ store.error }}
            <button class="page-button ghost" @click="reload">Thử lại</button>
        </p>

        <template v-else>
            <p v-if="!store.rows.length" class="state empty">
                <strong>Hiện chưa có chiến dịch nào đang chạy.</strong>
                <small>Hãy quay lại sau hoặc đăng ký nhận tin để không bỏ lỡ ưu đãi.</small>
            </p>

            <section v-else class="campaign-grid">
                <RouterLink
                    v-for="c in store.rows"
                    :key="c.id"
                    :to="`/tin-vui/tin-khuyen-mai/${c.slug}`"
                    class="card"
                >
                    <div class="cover">
                        <img v-if="c.cover_image_url" :src="c.cover_image_url" :alt="c.name" loading="lazy" />
                        <div v-else class="cover-fallback" :style="{ background: gradientFor(c.slug) }">
                            <span>{{ initials(c.name) }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <h2>{{ c.name }}</h2>
                        <p v-if="c.description" class="muted">{{ c.description }}</p>
                        <small v-if="c.starts_at || c.ends_at" class="muted small">
                            {{ c.starts_at ? formatDate(c.starts_at) : '—' }} →
                            {{ c.ends_at ? formatDate(c.ends_at) : '—' }}
                        </small>
                    </div>
                </RouterLink>
            </section>

            <div v-if="store.meta.last_page > 1" class="pagination">
                <button
                    class="page-button ghost"
                    :disabled="store.meta.current_page <= 1"
                    @click="store.loadList({ page: store.meta.current_page - 1 })"
                >
                    Trước
                </button>
                <span>Trang {{ store.meta.current_page }} / {{ store.meta.last_page }}</span>
                <button
                    class="page-button ghost"
                    :disabled="store.meta.current_page >= store.meta.last_page"
                    @click="store.loadList({ page: store.meta.current_page + 1 })"
                >
                    Sau
                </button>
            </div>
        </template>
    </main>
    <FooterHome />
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import { usePublicCampaignsStore } from '@/stores/publicCampaigns'

const store = usePublicCampaignsStore()

function reload(): void {
    void store.loadList()
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('')
}

const PALETTE = ['#d71920', '#0f766e', '#6d28d9', '#1d4ed8', '#b45309', '#0e7490']
function gradientFor(seed: string): string {
    let h = 0
    for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0
    const c1 = PALETTE[h % PALETTE.length]
    const c2 = PALETTE[(h >> 4) % PALETTE.length]
    return `linear-gradient(135deg, ${c1}, ${c2})`
}

onMounted(() => {
    void store.loadList()
})
</script>

<style scoped>
.campaign-list {
    min-height: 65vh;
    background: var(--color-cream);
    color: var(--color-charcoal-black);
    padding-top: var(--navbar-height, 72px);
}
.page-hero {
    padding: 36px 20px 20px;
    max-width: 1200px;
    margin: 0 auto;
    text-align: left;
}
.page-hero span {
    display: inline-block;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1.6px;
    color: var(--chili, #d71920);
    margin-bottom: 8px;
}
.page-hero h1 {
    margin: 0 0 8px;
    font-size: 32px;
    font-weight: 800;
}
.page-hero p {
    margin: 0;
    color: #4b3f36;
    max-width: 640px;
    font-size: 15px;
}
.state {
    text-align: center;
    padding: 60px 20px;
    color: #6b5f54;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
}
.state.empty strong {
    font-size: 16px;
    color: var(--color-charcoal-black);
}
.state.empty small {
    font-size: 13px;
}
.state.error {
    color: var(--chili, #d71920);
}
.campaign-grid {
    max-width: 1200px;
    margin: 0 auto;
    padding: 12px 20px 40px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
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
    transform: translateY(-3px);
    box-shadow: 0 10px 26px rgba(26, 14, 6, 0.1);
}
.cover {
    aspect-ratio: 16 / 9;
    overflow: hidden;
    background: rgba(26, 14, 6, 0.05);
}
.cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.cover-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 44px;
    font-weight: 800;
    letter-spacing: -1px;
}
.card-body {
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.card-body h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
}
.muted {
    margin: 0;
    color: #6b5f54;
    font-size: 13px;
    line-height: 1.5;
}
.muted.small {
    font-size: 11px;
}
.pagination {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px 60px;
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: center;
}
.page-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--chili, #d71920);
    color: #fff;
    border: 0;
    border-radius: 10px;
    padding: 9px 16px;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.page-button.ghost {
    background: transparent;
    border: 1px solid rgba(26, 14, 6, 0.12);
    color: var(--color-charcoal-black);
}
.page-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
