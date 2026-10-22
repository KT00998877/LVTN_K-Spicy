<template>
    <NavbarHome />
    <main class="campaign-detail">
        <header class="page-hero">
            <RouterLink to="/tin-vui/tin-khuyen-mai" class="back">← Quay lại danh sách</RouterLink>
        </header>

        <p v-if="store.detailLoading" class="state" role="status">Đang tải chiến dịch…</p>
        <p v-else-if="store.detailError" class="state error" role="alert">
            {{ store.detailError }}
            <RouterLink to="/tin-vui/tin-khuyen-mai" class="page-button ghost">
                Về danh sách chiến dịch
            </RouterLink>
        </p>

        <template v-else-if="store.detail">
            <section class="hero">
                <div v-if="store.detail.cover_image_url" class="cover">
                    <img :src="store.detail.cover_image_url" :alt="store.detail.name" />
                </div>
                <div v-else class="cover fallback" :style="{ background: gradientFor(store.detail.slug) }">
                    <span>{{ initials(store.detail.name) }}</span>
                </div>

                <div class="hero-body">
                    <span class="eyebrow">🎉 CHIẾN DỊCH ĐANG CHẠY</span>
                    <h1>{{ store.detail.name }}</h1>
                    <p v-if="store.detail.description" class="lead">{{ store.detail.description }}</p>
                    <p v-if="store.detail.starts_at || store.detail.ends_at" class="muted small">
                        {{ store.detail.starts_at ? formatDate(store.detail.starts_at) : '—' }} →
                        {{ store.detail.ends_at ? formatDate(store.detail.ends_at) : '—' }}
                    </p>
                </div>
            </section>

            <AvailablePromotionsSection
                v-if="store.detail.promotions.length || store.detail.vouchers.length"
                :promotions="store.detail.promotions"
                :vouchers="store.detail.vouchers"
            />

            <section v-if="store.detail.promotions.length" class="detail-section">
                <h2>Khuyến mãi tự động</h2>
                <p class="muted">Áp dụng ngay khi giỏ hàng đủ điều kiện — không cần nhập mã.</p>
                <ul class="promo-detail-list">
                    <li v-for="p in store.detail.promotions" :key="p.id">
                        <PromotionBadge :kind="p.kind" :badge="p.badge" />
                        <div>
                            <strong>{{ p.name }}</strong>
                            <small v-if="p.description">{{ p.description }}</small>
                            <small v-if="p.ends_at" class="muted">
                                Đến {{ formatDate(p.ends_at) }}
                            </small>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="store.detail.vouchers.length" class="detail-section">
                <h2>Voucher tặng kèm</h2>
                <p class="muted">Nhập mã ở bước thanh toán để được giảm.</p>
                <ul class="voucher-detail-list">
                    <li v-for="v in store.detail.vouchers" :key="v.id">
                        <span class="voucher-code">{{ v.code }}</span>
                        <div>
                            <strong>{{ v.name }}</strong>
                            <small class="muted">
                                {{ v.min_order_subtotal > 0 ? `Đơn ≥ ${formatMoney(v.min_order_subtotal)}` : 'Không yêu cầu tối thiểu' }}
                            </small>
                            <small v-if="v.ends_at" class="muted small">
                                Đến {{ formatDate(v.ends_at) }}
                            </small>
                        </div>
                        <span class="voucher-badge">{{ v.badge }}</span>
                    </li>
                </ul>
            </section>

            <RouterLink to="/thuc-don" class="page-button cta">Khám phá thực đơn ngay</RouterLink>
        </template>
    </main>
    <FooterHome />
</template>

<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import AvailablePromotionsSection from '@/components/menu/AvailablePromotionsSection.vue'
import PromotionBadge from '@/components/menu/PromotionBadge.vue'
import { usePublicCampaignsStore } from '@/stores/publicCampaigns'

const route = useRoute()
const store = usePublicCampaignsStore()

const slug = (): string => String(route.params.slug ?? '')

watch(
    () => route.params.slug,
    () => {
        if (slug()) void store.loadDetail(slug())
    },
)

onMounted(() => {
    void store.loadDetail(slug())
})

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}
function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('')
}
const PALETTE = ['var(--color-brand-primary)', 'var(--color-brand-dark)', 'var(--color-brand-ink)', 'var(--color-brand-action-hover)']
function gradientFor(seed: string): string {
    let h = 0
    for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0
    const c1 = PALETTE[h % PALETTE.length]
    const c2 = PALETTE[(h >> 4) % PALETTE.length]
    return `linear-gradient(135deg, ${c1}, ${c2})`
}
</script>

<style scoped>
.campaign-detail {
    min-height: 65vh;
    background: var(--color-cream);
    color: var(--color-charcoal-black);
    padding-top: var(--navbar-height, 72px);
}
.page-hero {
    max-width: 1200px;
    margin: 0 auto;
    padding: 28px 20px 0;
}
.back {
    display: inline-block;
    color: var(--chili, #d71920);
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
}
.back:hover {
    text-decoration: underline;
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
.state.error {
    color: var(--chili, #d71920);
}
.hero {
    max-width: 1200px;
    margin: 16px auto 0;
    padding: 0 20px;
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    align-items: center;
}
.cover {
    border-radius: 14px;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    background: rgba(26, 14, 6, 0.05);
}
.cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.cover.fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 64px;
    font-weight: 800;
    letter-spacing: -1px;
}
.hero-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.eyebrow {
    display: inline-block;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
}
.hero-body h1 {
    margin: 0;
    font-size: 30px;
    font-weight: 800;
    line-height: 1.2;
}
.lead {
    margin: 0;
    font-size: 15px;
    color: #4b3f36;
    line-height: 1.5;
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
.detail-section {
    max-width: 1200px;
    margin: 28px auto 0;
    padding: 0 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.detail-section h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
}
.promo-detail-list,
.voucher-detail-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.promo-detail-list li {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 12px;
    align-items: center;
    padding: 10px 12px;
    background: rgba(217, 25, 32, 0.04);
    border-radius: 10px;
}
.promo-detail-list strong,
.voucher-detail-list strong {
    display: block;
    font-size: 14px;
}
.promo-detail-list small,
.voucher-detail-list small {
    display: block;
    font-size: 12px;
}
.voucher-detail-list li {
    display: grid;
    grid-template-columns: 100px 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 10px 12px;
    background: var(--color-brand-soft);
    border-radius: 10px;
}
.voucher-code {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: var(--color-brand-action);
    color: #fff;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}
.voucher-badge {
    font-weight: 800;
    color: var(--chili, #d71920);
    font-variant-numeric: tabular-nums;
}
.cta {
    display: inline-flex;
    align-self: flex-start;
    margin: 32px 20px 60px;
    background: var(--chili, #d71920);
    color: #fff;
    padding: 12px 22px;
    border-radius: var(--radius-button);
    text-decoration: none;
    font-weight: 700;
    transition: transform 0.16s ease;
}
.cta:hover {
    transform: translateY(-1px);
}
.page-button.ghost {
    background: transparent;
    border: 1px solid rgba(26, 14, 6, 0.12);
    color: var(--color-charcoal-black);
    padding: 9px 16px;
    border-radius: var(--radius-button);
    font-weight: 700;
    text-decoration: none;
}
@media (max-width: 720px) {
    .hero {
        grid-template-columns: 1fr;
    }
    .cover {
        aspect-ratio: 16 / 8;
    }
    .hero-body h1 {
        font-size: 24px;
    }
}
</style>
