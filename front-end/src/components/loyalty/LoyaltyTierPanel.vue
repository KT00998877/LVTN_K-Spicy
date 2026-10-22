<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { fetchLoyaltyTiers, fetchLoyaltyMe, type LoyaltyTierPublic } from '@/api/loyalty'
import { useAuthStore } from '@/stores/auth'
import TierBadge from './TierBadge.vue'

/**
 * LoyaltyTierPanel — hiển thị hạng hiện tại + 4 hạng + tiến trình thăng hạng.
 *
 * Dùng trong AccountView > Tab "Hạng thành viên".
 */

function formatVnd(v: number): string {
    return new Intl.NumberFormat('vi-VN').format(v) + 'đ'
}

const authStore = useAuthStore()
const { user } = storeToRefs(authStore)

const tierTiers = ref<LoyaltyTierPublic[]>([])
const tierCurrent = ref<LoyaltyTierPublic | null>(null)
const tierNext = ref<(LoyaltyTierPublic & { min_spent: number }) | null>(null)
const tierProgress = ref<{ amount_to_next: number; percent: number } | null>(null)
const tierLoading = ref(false)
const tierError = ref<string | null>(null)
const tierReady = ref(false)
let loadRevision = 0

async function load(): Promise<void> {
    const revision = ++loadRevision
    const sessionRevision = authStore.sessionRevision
    const userId = user.value?.id
    const isCurrent = () => revision === loadRevision
        && authStore.sessionRevision === sessionRevision && user.value?.id === userId
    tierLoading.value = !tierReady.value
    tierError.value = null
    try {
        const [publicList, me] = await Promise.all([
            fetchLoyaltyTiers(),
            fetchLoyaltyMe().catch(() => null),
        ])
        if (!isCurrent()) return
        tierTiers.value = publicList
        if (me) {
            tierCurrent.value = me.current
            tierNext.value = (me.next as LoyaltyTierPublic & { min_spent: number }) ?? null
            tierProgress.value = me.progress
                ? { amount_to_next: me.progress.amount_to_next, percent: me.progress.percent }
                : null
        }
        tierReady.value = true
    } catch (e: { response?: { data?: { message?: string } } } | any) {
        if (!isCurrent()) return
        tierError.value = e?.response?.data?.message ?? 'Không tải được hạng thành viên.'
    } finally {
        if (isCurrent()) tierLoading.value = false
    }
}

// Refresh khi user đăng nhập, tab được kích hoạt, hoặc total_spent có thể đã đổi.
onMounted(() => { load() })
watch(() => [user.value?.total_spent, user.value?.tier?.id], () => load())

const isMaxTier = computed(() => !tierNext.value)
</script>

<template>
    <section class="loyalty-panel">
        <header class="loyalty-panel__head">
            <h2 class="loyalty-panel__title">Hạng thành viên</h2>
            <p class="loyalty-panel__sub">
                Hạng được nâng tự động khi tổng chi tiêu tích lũy đạt ngưỡng — kèm ưu đãi riêng cho từng hạng.
            </p>
        </header>

        <div v-if="tierLoading" class="loyalty-panel__skeleton" role="status" aria-busy="true">
            <div class="sk sk-card" v-for="i in 4" :key="i"></div>
        </div>

        <p v-if="tierError" class="alert alert-error" role="alert">
            {{ tierError }}
        </p>

        <template v-if="tierReady">
            <!-- Current tier highlight -->
            <div v-if="tierCurrent" class="current-tier rank-surface" :class="`rank--${tierCurrent.code.toLowerCase()}`" :style="{ '--accent': tierCurrent.color }">
                <div class="ct-left">
                    <span class="ct-icon" aria-hidden="true">{{ tierCurrent.icon }}</span>
                    <div>
                        <div class="ct-label">Hạng hiện tại của bạn</div>
                        <div class="ct-name">{{ tierCurrent.name }}</div>
                    </div>
                </div>
                <div class="ct-right">
                    <div class="ct-stat">
                        <span class="ct-stat-value">{{ formatVnd(user?.total_spent ?? 0) }}</span>
                        <span class="ct-stat-label">Tổng chi tiêu</span>
                    </div>
                    <div class="ct-stat">
                        <span class="ct-stat-value">{{ user?.loyalty_points ?? 0 }}</span>
                        <span class="ct-stat-label">Điểm thưởng</span>
                    </div>
                    <TierBadge :tier="tierCurrent" size="md" :show-name="true" />
                </div>
            </div>

            <!-- Progress to next -->
            <div v-if="tierProgress && tierNext" class="progress-card">
                <div class="progress-card__head">
                    <span>
                        <strong>Còn {{ formatVnd(tierProgress.amount_to_next) }}</strong>
                        nữa để lên <strong>{{ tierNext.name }}</strong>
                    </span>
                    <span class="progress-card__pct">{{ Math.floor(tierProgress.percent) }}%</span>
                </div>
                <div class="progress-bar" role="progressbar" :aria-valuenow="Math.floor(tierProgress.percent)" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill" :style="{ width: Math.min(100, Math.max(0, tierProgress.percent)) + '%' }"></div>
                </div>
            </div>

            <div v-else-if="isMaxTier" class="max-badge">
                <span aria-hidden="true">🏆</span>
                Bạn đã đạt <strong>hạng cao nhất</strong>! Cảm ơn vì đã đồng hành cùng K-SPICY.
            </div>

            <!-- 4 tiers grid -->
            <h3 class="loyalty-panel__subheading">Quyền lợi theo hạng</h3>
            <ol class="tier-grid" aria-label="Danh sách hạng thành viên">
                <li v-for="t in tierTiers" :key="t.id" class="tier-card rank-surface"
                    :class="{ 'tier-card--current': tierCurrent && tierCurrent.id === t.id, [`rank--${t.code.toLowerCase()}`]: true }"
                    :style="{ '--accent': t.color }">
                    <span v-if="tierCurrent && tierCurrent.id === t.id" class="tier-card__current-label">Hạng hiện tại</span>
                    <div class="tier-card__head">
                        <span class="tier-card__icon" aria-hidden="true">{{ t.icon }}</span>
                        <div>
                            <div class="tier-card__name">{{ t.name }}</div>
                            <div class="tier-card__threshold">Từ {{ formatVnd(t.min_spent) }} chi tiêu</div>
                        </div>
                    </div>
                    <p v-if="t.description" class="tier-card__desc">{{ t.description }}</p>
                    <ul class="tier-card__perks">
                        <li v-if="t.point_multiplier && t.point_multiplier !== 1">
                            <span class="perk-bullet" aria-hidden="true">×</span>
                            Tích điểm <strong>x{{ t.point_multiplier.toFixed(1) }}</strong>
                        </li>
                        <li v-if="t.free_ship_threshold !== null">
                            <span class="perk-bullet" aria-hidden="true">🚚</span>
                            <template v-if="t.free_ship_threshold === 0">
                                <strong>Miễn phí ship</strong> mọi đơn
                            </template>
                            <template v-else>
                                Miễn phí ship đơn từ <strong>{{ formatVnd(t.free_ship_threshold) }}</strong>
                            </template>
                        </li>
                        <li v-if="t.extra_discount_pct && t.extra_discount_pct > 0">
                            <span class="perk-bullet" aria-hidden="true">%</span>
                            Giảm thêm <strong>{{ t.extra_discount_pct }}%</strong> trọn đời
                        </li>
                    </ul>
                </li>
            </ol>
        </template>
    </section>
</template>

<style scoped>
.loyalty-panel { display: flex; flex-direction: column; gap: 24px; }
.loyalty-panel__head { display: flex; flex-direction: column; gap: 4px; }
.loyalty-panel__title { font-size: 20px; font-weight: 800; color: var(--color-brand-ink); margin: 0; }
.loyalty-panel__sub { font-size: 14px; color: var(--color-text-muted); margin: 0; }
.loyalty-panel__subheading { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin: 8px 0 0; }
.loyalty-panel__skeleton { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.sk { border-radius: 12px; background: linear-gradient(90deg, #f3f4f6 0%, #f9fafb 50%, #f3f4f6 100%); background-size: 200% 100%; animation: shimmer 1.5s linear infinite; }
.sk-card { height: 180px; }
@keyframes shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }

/* Current tier */
.current-tier {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    padding: 24px;
    border-radius: 16px;

    border: 2px solid var(--color-brand-action);
    flex-wrap: wrap;
}
.ct-left { display: flex; align-items: center; gap: 16px; }
.ct-icon { font-size: 48px; }
.ct-label { font-size: 12px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.ct-name { font-size: 24px; font-weight: 800; color: var(--color-brand-ink); line-height: 1.2; }
.ct-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.ct-stat { display: flex; flex-direction: column; align-items: flex-end; }
.ct-stat-value { font-size: 20px; font-weight: 800; color: var(--color-brand-ink); font-variant-numeric: tabular-nums; }
.ct-stat-label { font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; }

/* Progress card */
.progress-card {
    padding: 20px 24px;
    border-radius: 14px;
    background: #FFF;
    border: 1px solid var(--color-border-strong);
}
.progress-card__head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.progress-card__pct { font-size: 16px; font-weight: 800; color: #D71920; font-variant-numeric: tabular-nums; }
.progress-bar {
    height: 10px;
    background: #F3F4F6;
    border-radius: 999px;
    overflow: hidden;
}
.progress-bar__fill {
    height: 100%;
    background: linear-gradient(90deg, #FFB000 0%, #D71920 100%);
    border-radius: 999px;
    transition: width 0.5s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.max-badge {
    padding: 16px 20px;
    border-radius: 12px;
    background: linear-gradient(135deg, #FFE7B0 0%, #FFC94D 100%);
    color: #6B4E00;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 15px;
}

/* Tier grid */
.tier-grid {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: 16px;
}
.tier-card {
    padding: 20px;
    border-radius: 14px;
    border: 1px solid var(--color-border-strong);
    border-top: 5px solid var(--accent, var(--color-brand-action));
    transition: transform 250ms cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 250ms cubic-bezier(0.25, 0.8, 0.25, 1);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.tier-card:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
.tier-card--current {

    border-color: var(--color-brand-action);
    box-shadow: 0 0 0 1px var(--color-brand-action), var(--shadow-md);
}
.tier-card__head { display: flex; align-items: center; gap: 12px; }
.tier-card__icon { font-size: 32px; }
.tier-card__name { font-size: 18px; font-weight: 800; color: var(--color-brand-ink); line-height: 1.2; }
.tier-card__threshold { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; font-weight: 600; }
.tier-card__desc { font-size: 15px; color: var(--color-text-muted); margin: 0; line-height: 1.5; }
.tier-card__perks {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tier-card__perks li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    color: var(--color-text-muted);
}
.perk-bullet {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    background: color-mix(in srgb, var(--accent) 12%, transparent);
    color: var(--accent, #D71920);
    font-weight: 800;
    font-size: 11px;
}

@media (max-width: 640px) {
    .current-tier { padding: 20px; gap: 16px; }
    .ct-icon { font-size: 40px; }
    .ct-name { font-size: 20px; }
    .ct-stat-value { font-size: 16px; }
    .tier-card__icon { font-size: 28px; }
}

.tier-card__current-label {
    align-self: flex-start;
    padding: 6px 12px;
    border-radius: var(--radius-pill, 999px);
    background: var(--color-brand-action);
    color: var(--color-white);
    font-size: 12px;
    font-weight: 800;
}
.tier-card__perks li { align-items: flex-start; line-height: 1.6; }
.perk-bullet { flex-shrink: 0; }
.ct-left, .ct-right, .tier-card__head > div { min-width: 0; }
.ct-stat-value, .tier-card__threshold { overflow-wrap: anywhere; }
.progress-card__head { gap: 12px; flex-wrap: wrap; line-height: 1.6; }
@media (max-width: 640px) {
    .ct-right { width: 100%; justify-content: space-between; }
    .ct-stat { align-items: flex-start; }
}

.current-tier > *, .tier-card > * { position: relative; z-index: 1; }
.current-tier .ct-name, .current-tier .ct-stat-value,
.tier-card .tier-card__name { color: var(--rank-text); }
.current-tier .ct-label, .current-tier .ct-stat-label,
.tier-card .tier-card__threshold, .tier-card .tier-card__desc,
.tier-card .tier-card__perks li { color: var(--rank-muted); }
.current-tier, .tier-card { border-color: var(--rank-ornament); }
.tier-card--current { box-shadow: 0 0 0 2px var(--rank-ornament), var(--shadow-md); }
.tier-card__current-label { background: var(--rank-ornament); color: var(--rank-background); }
.perk-bullet { background: color-mix(in srgb, var(--rank-ornament) 18%, transparent); color: var(--rank-text); }

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .tier-card:hover { transform: none; }
    .progress-bar__fill { transition: none; }
    .sk { animation: none; }
}
</style>
