<template>
    <aside v-if="open" class="drawer" role="dialog" aria-label="Chi tiết chiến dịch">
        <header class="drawer-head">
            <div>
                <span class="eyebrow">CHIẾN DỊCH</span>
                <h3>{{ campaign?.name ?? '—' }}</h3>
                <small v-if="campaign" class="muted">
                    {{ campaign.starts_at ? formatDate(campaign.starts_at) : '—' }} →
                    {{ campaign.ends_at ? formatDate(campaign.ends_at) : '—' }}
                </small>
            </div>
            <button type="button" class="icon-btn" aria-label="Đóng" @click="$emit('close')">×</button>
        </header>

        <p v-if="loading" class="state">Đang tải chi tiết…</p>
        <p v-else-if="error" class="alert error">{{ error }}</p>

        <template v-else-if="campaign">
            <p v-if="campaign.description" class="description">{{ campaign.description }}</p>

            <div v-if="stats" class="stats-grid">
                <div class="stat">
                    <span class="label">Khuyến mãi con</span>
                    <strong>{{ stats.promotions_count }}</strong>
                </div>
                <div class="stat">
                    <span class="label">Đang chạy</span>
                    <strong>{{ stats.promotions_active }}</strong>
                </div>
                <div class="stat">
                    <span class="label">Tổng lượt dùng</span>
                    <strong>{{ stats.total_uses }}</strong>
                </div>
                <div class="stat accent">
                    <span class="label">Tổng giảm</span>
                    <strong>{{ formatMoney(stats.total_discount) }}</strong>
                </div>
            </div>

            <h4>Khuyến mãi thuộc chiến dịch</h4>
            <ul v-if="campaign.promotions?.length" class="promo-list">
                <li v-for="p in campaign.promotions" :key="p.id">
                    <span class="badge">{{ p.badge }}</span>
                    <div>
                        <strong>{{ p.name }}</strong>
                        <small class="muted">{{ p.kind_label }}</small>
                    </div>
                    <span v-if="p.is_active" class="dot on" />
                    <span v-else class="dot off" />
                </li>
            </ul>
            <p v-else class="muted">Chưa gắn khuyến mãi nào.</p>
        </template>
    </aside>
</template>

<script setup lang="ts">
import type { CampaignStats, ManagerCampaignDetail } from '@/types/campaign'

interface Props {
    open: boolean
    campaign: ManagerCampaignDetail | null
    stats: CampaignStats | null
    loading?: boolean
    error?: string
}
withDefaults(defineProps<Props>(), { loading: false, error: '' })
defineEmits<{ close: [] }>()

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}
function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
</script>

<style scoped>
.drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: min(420px, calc(100vw - 32px));
    background: #fff;
    border-left: 1px solid rgba(26, 14, 6, 0.08);
    box-shadow: -8px 0 30px rgba(26, 14, 6, 0.08);
    z-index: 80;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    overflow-y: auto;
    color: #1a1a1a;
}
.drawer-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
    padding-bottom: 10px;
}
.eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
    margin-bottom: 4px;
}
.drawer-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}
.icon-btn {
    background: transparent;
    border: 0;
    font-size: 22px;
    line-height: 1;
    color: #6b5f54;
    cursor: pointer;
    width: 30px;
    height: 30px;
    border-radius: 8px;
}
.icon-btn:hover {
    background: rgba(26, 14, 6, 0.06);
}
.description {
    margin: 0;
    font-size: 13px;
    color: #1a1a1a;
    line-height: 1.5;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.stat {
    background: rgba(26, 14, 6, 0.04);
    border-radius: 8px;
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.stat .label {
    font-size: 11px;
    color: #6b5f54;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.stat strong {
    font-size: 16px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.stat.accent {
    background: linear-gradient(135deg, var(--chili, #d71920), #ff574c);
    color: #fff;
}
.stat.accent .label {
    color: rgba(255, 255, 255, 0.85);
}
.stat.accent strong {
    color: #fff;
}
h4 {
    margin: 8px 0 4px;
    font-size: 13px;
    text-transform: uppercase;
    color: #6b5f54;
    letter-spacing: 0.6px;
}
.promo-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.promo-list li {
    display: grid;
    grid-template-columns: 80px 1fr 10px;
    gap: 8px;
    align-items: center;
    padding: 8px 10px;
    background: rgba(26, 14, 6, 0.03);
    border-radius: 8px;
}
.promo-list .badge {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: rgba(217, 25, 32, 0.1);
    color: var(--chili, #d71920);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-align: center;
}
.promo-list strong {
    display: block;
    font-size: 13px;
}
.promo-list small {
    font-size: 11px;
}
.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.dot.on {
    background: #16a34a;
}
.dot.off {
    background: #94a3b8;
}
.muted {
    color: #6b5f54;
}
.state {
    background: #fff;
    border: 1px dashed rgba(26, 14, 6, 0.14);
    border-radius: 12px;
    padding: 14px;
    text-align: center;
    color: #6b5f54;
    font-size: 13px;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--chili, #d71920);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
}
</style>
