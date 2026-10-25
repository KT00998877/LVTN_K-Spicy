<script setup lang="ts">
/**
 * PromoBanner — banner ưu đãi kiểu ShopeeFood (Phase 1).
 *
 * Mỗi banner hiện tên chiến dịch, nội dung, mã voucher trong khung viền đứt
 * và nút sao chép. Nếu chưa có ảnh banner thì dùng gradient theo màu nhấn
 * (accent_color) — sau này quản lý chỉ cần thay URL ảnh trong Manager.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { claimPromotion, fetchFeaturedPromotions, type FeaturedPromotion } from '@/api/promotions'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const promos = ref<FeaturedPromotion[]>([])
const loading = ref(true)
const activeIndex = ref(0)
const copyState = ref<Record<number, 'idle' | 'copied' | 'failed'>>({})
const claimState = ref<Record<number, 'idle' | 'loading' | 'claimed'>>({})
const claimError = ref('')

const active = computed<FeaturedPromotion | null>(() => promos.value[activeIndex.value] ?? null)

onMounted(async () => {
    try {
        promos.value = await fetchFeaturedPromotions()
    } catch {
        promos.value = []
    } finally {
        loading.value = false
    }
})

async function copyCode(code: string, index: number) {
    try {
        await navigator.clipboard.writeText(code)
        copyState.value = { ...copyState.value, [index]: 'copied' }
    } catch {
        copyState.value = { ...copyState.value, [index]: 'failed' }
    }
    setTimeout(() => {
        copyState.value = { ...copyState.value, [index]: 'idle' }
    }, 2200)
}

async function claim(promo: FeaturedPromotion): Promise<void> {
    if (!authStore.isAuthenticated) {
        await router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
        return
    }
    if (claimState.value[promo.id] === 'loading') return

    claimError.value = ''
    claimState.value = { ...claimState.value, [promo.id]: 'loading' }
    try {
        const claimed = await claimPromotion(promo.id)
        claimState.value = { ...claimState.value, [promo.id]: 'claimed' }
        await copyCode(claimed.voucher_code, activeIndex.value)
    } catch (error) {
        claimState.value = { ...claimState.value, [promo.id]: 'idle' }
        claimError.value = axios.isAxiosError(error)
            ? error.response?.data?.errors?.promotion?.[0] ?? error.response?.data?.message ?? 'Không thể nhận ưu đãi.'
            : 'Không thể nhận ưu đãi.'
    }
}
</script>

<template>
    <section v-if="loading" class="promo-banner promo-banner--skeleton" aria-hidden="true" />

    <section
        v-else-if="active"
        class="promo-banner"
        :style="{ '--promo-accent': active.accent_color || '#E83A14' }"
        aria-label="Ưu đãi đang chạy"
    >
        <div
            class="promo-visual"
            :style="active.banner_image_url
                ? { backgroundImage: `url(${active.banner_image_url})` }
                : undefined"
        >
            <span v-if="active.discount_label" class="promo-discount">{{ active.discount_label }}</span>
        </div>

        <div class="promo-body">
            <p class="promo-kicker">Ưu đãi đặc biệt</p>
            <h3 class="promo-title">{{ active.name }}</h3>
            <p class="promo-message">{{ active.message }}</p>

            <div class="promo-code-row">
                <code class="promo-code">{{ active.voucher_code }}</code>
                <button
                    class="promo-copy"
                    type="button"
                    :aria-label="`Sao chép mã ${active.voucher_code}`"
                    @click="copyCode(active.voucher_code, activeIndex)"
                >
                    {{ copyState[activeIndex] === 'copied' ? 'Đã sao chép' : copyState[activeIndex] === 'failed' ? 'Thử lại' : 'Sao chép mã' }}
                </button>
            </div>

            <button class="promo-cta" type="button" :disabled="claimState[active.id] === 'loading'" @click="claim(active)">
                {{ claimState[active.id] === 'loading' ? 'Đang nhận…' : claimState[active.id] === 'claimed' ? 'Đã nhận mã' : active.cta_text || 'Nhận ưu đãi' }}
            </button>
            <p v-if="claimError" class="promo-error" role="alert">{{ claimError }}</p>
        </div>

        <div v-if="promos.length > 1" class="promo-dots" role="tablist" aria-label="Chuyển ưu đãi">
            <button
                v-for="(promo, index) in promos"
                :key="promo.id"
                type="button"
                role="tab"
                :aria-selected="index === activeIndex"
                :class="{ active: index === activeIndex }"
                :aria-label="`Ưu đãi ${index + 1}: ${promo.name}`"
                @click="activeIndex = index"
            />
        </div>
    </section>
</template>

<style scoped>
.promo-banner {
    position: relative;
    display: grid;
    grid-template-columns: minmax(120px, 34%) 1fr;
    gap: 0;
    overflow: hidden;
    border-radius: 16px;
    background: var(--promo-accent, #e83a14);
    color: #fff;
}

.promo-banner--skeleton {
    min-height: 150px;
    background: linear-gradient(90deg, #f0ece7 25%, #f7f4f1 50%, #f0ece7 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
}
@keyframes shimmer {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
}

.promo-visual {
    position: relative;
    min-height: 150px;
    background-color: rgba(0, 0, 0, 0.14);
    background-size: cover;
    background-position: center;
}
.promo-visual::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, rgba(0, 0, 0, 0.25), transparent 70%);
}

.promo-discount {
    position: absolute;
    top: 12px;
    left: 12px;
    z-index: 1;
    padding: 5px 10px;
    border-radius: 8px;
    background: #fff;
    color: var(--promo-accent, #e83a14);
    font-size: 14px;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
}

.promo-body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 8px;
    padding: 18px 20px;
}

.promo-kicker {
    margin: 0;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    opacity: 0.85;
}

.promo-title {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    line-height: 1.2;
}

.promo-message {
    margin: 0;
    font-size: 14px;
    line-height: 1.45;
    opacity: 0.92;
}

.promo-code-row {
    display: flex;
    align-items: stretch;
    gap: 8px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.promo-code {
    flex: 1 1 160px;
    min-width: 0;
    padding: 9px 12px;
    border: 2px dashed #fff;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-align: center;
    overflow-wrap: anywhere;
}

.promo-copy {
    flex: 0 0 auto;
    min-height: 42px;
    padding: 10px 16px;
    border: 0;
    border-radius: 10px;
    background: #fff;
    color: var(--promo-accent, #e83a14);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}
.promo-copy:hover { filter: brightness(0.95); }

.promo-cta {
    align-self: flex-start;
    min-height: 42px;
    margin-top: 2px;
    padding: 10px 20px;
    border: 0;
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.22);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}
.promo-cta:hover { background: rgba(0, 0, 0, 0.32); }
.promo-cta:disabled { cursor: not-allowed; opacity: .65; }
.promo-error { margin: 0; color: #fff; font-size: 13px; font-weight: 700; }

.promo-dots {
    position: absolute;
    right: 14px;
    bottom: 12px;
    display: flex;
    gap: 6px;
}
.promo-dots button {
    width: 8px;
    height: 8px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.45);
    cursor: pointer;
    transition: width 0.2s ease, background 0.2s ease;
}
.promo-dots button.active { width: 20px; border-radius: 4px; background: #fff; }

button:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }

@media (max-width: 560px) {
    .promo-banner { grid-template-columns: 1fr; }
    .promo-visual { min-height: 110px; }
    .promo-title { font-size: 18px; }
    .promo-body { padding: 16px; }
}
</style>
