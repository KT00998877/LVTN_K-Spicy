<template>
    <section v-if="promotions.length || vouchers.length" class="available-promotions" aria-label="Khuyến mãi khả dụng">
        <header class="head">
            <h3>Khuyến mãi khả dụng</h3>
            <p class="hint">Áp dụng tự động khi đủ điều kiện. Nhập mã voucher ở bước thanh toán.</p>
        </header>

        <ul v-if="promotions.length" class="list">
            <li v-for="p in promotions" :key="p.id">
                <PromotionBadge :kind="p.kind" :badge="p.badge" />
                <div class="info">
                    <strong>{{ p.name }}</strong>
                    <small v-if="p.description">{{ p.description }}</small>
                    <small v-if="p.ends_at" class="muted">Đến {{ formatDate(p.ends_at) }}</small>
                </div>
            </li>
        </ul>

        <ul v-if="vouchers.length" class="list vouchers">
            <li v-for="v in vouchers" :key="v.id">
                <span class="voucher-code">{{ v.code }}</span>
                <div class="info">
                    <strong>{{ v.name }}</strong>
                    <small v-if="v.min_order_subtotal > 0" class="muted">
                        Đơn ≥ {{ formatMoney(v.min_order_subtotal) }}
                    </small>
                    <small v-else class="muted">Không yêu cầu tối thiểu</small>
                </div>
                <span class="badge">{{ v.badge }}</span>
            </li>
        </ul>
    </section>
</template>

<script setup lang="ts">
import PromotionBadge from './PromotionBadge.vue'
import type { PublicCampaignPromotion, PublicCampaignVoucher } from '@/types/campaign'

interface Props {
    promotions?: PublicCampaignPromotion[]
    vouchers?: PublicCampaignVoucher[]
}
withDefaults(defineProps<Props>(), { promotions: () => [], vouchers: () => [] })

function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}
</script>

<style scoped>
.available-promotions {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.head h3 {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 800;
}
.hint {
    margin: 0;
    font-size: 12px;
    color: #6b5f54;
}
.list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.list li {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 10px;
    align-items: center;
    padding: 8px 10px;
    background: rgba(217, 25, 32, 0.04);
    border-radius: 8px;
}
.list.vouchers li {
    grid-template-columns: 80px 1fr auto;
    background: rgba(15, 118, 110, 0.05);
}
.info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.info strong {
    font-size: 13px;
}
.info small {
    font-size: 11px;
}
.muted {
    color: #6b5f54;
}
.voucher-code {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: #0f766e;
    color: #fff;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-align: center;
}
.badge {
    color: var(--chili, #d71920);
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
</style>
