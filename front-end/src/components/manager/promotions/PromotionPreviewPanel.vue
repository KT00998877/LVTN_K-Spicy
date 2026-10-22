<template>
    <aside class="preview-panel" aria-label="Xem trước khuyến mãi">
        <h3>Xem trước cart mẫu</h3>
        <p class="hint">
            Nhập giả lập giỏ hàng để chạy rule engine và xem khuyến mãi nào được áp.
        </p>

        <form @submit.prevent="run">
            <label>
                Kênh bán
                <select v-model="form.channel" :disabled="store.promoLoadingPreview">
                    <option value="ONLINE">Online</option>
                    <option value="DINE_IN">Tại bàn</option>
                    <option value="TAKEAWAY">Mang đi</option>
                </select>
            </label>
            <label>
                Tạm tính đơn (VND)
                <input
                    v-model.number="form.subtotal"
                    type="number"
                    min="0"
                    step="1000"
                    :disabled="store.promoLoadingPreview"
                />
            </label>

            <div class="items-section">
                <span class="field-label">Món trong giỏ</span>
                <div v-for="(it, i) in form.items" :key="i" class="item-row">
                    <input
                        v-model.number="it.menu_item_id"
                        type="number"
                        min="1"
                        placeholder="ID món"
                        :disabled="store.promoLoadingPreview"
                    />
                    <input
                        v-model.number="it.quantity"
                        type="number"
                        min="1"
                        placeholder="SL"
                        :disabled="store.promoLoadingPreview"
                    />
                    <input
                        v-model.number="it.unit_price"
                        type="number"
                        min="0"
                        step="1000"
                        placeholder="Đơn giá"
                        :disabled="store.promoLoadingPreview"
                    />
                    <button
                        type="button"
                        class="icon-btn small danger"
                        :disabled="store.promoLoadingPreview"
                        @click="removeItem(i)"
                    >
                        ×
                    </button>
                </div>
                <button
                    type="button"
                    class="btn ghost"
                    :disabled="store.promoLoadingPreview"
                    @click="addItem"
                >
                    + Thêm món
                </button>
            </div>

            <button type="submit" class="btn primary block" :disabled="store.promoLoadingPreview">
                {{ store.promoLoadingPreview ? 'Đang tính…' : 'Chạy thử' }}
            </button>
        </form>

        <p v-if="store.promoPreviewError" class="alert error" role="alert">
            {{ store.promoPreviewError }}
        </p>

        <div v-if="store.promoPreview" class="result">
            <p v-if="!store.promoPreview.has_discount" class="muted">
                Không có khuyến mãi nào đủ điều kiện cho cart này.
            </p>
            <template v-else>
                <p class="discount-line">
                    <strong>Tổng giảm:</strong>
                    <span class="amount">{{ formatMoney(store.promoPreview.total_discount) }}</span>
                </p>
                <ul>
                    <li v-for="a in store.promoPreview.applied" :key="a.promotion_id">
                        <span class="kind">#{{ a.promotion_id }}</span>
                        <span class="slug">{{ a.slug }}</span>
                        <span class="amount">{{ a.discount_label }}</span>
                    </li>
                </ul>
                <p class="new-subtotal">
                    Sau giảm: <strong>{{ formatMoney(store.promoPreview.new_subtotal) }}</strong>
                </p>
            </template>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { reactive } from 'vue'
import { useManagerPromotionsStore } from '@/stores/managerPromotions'
import type { PreviewCartItem } from '@/types/promotion'

const store = useManagerPromotionsStore()

const form = reactive({
    channel: 'ONLINE' as 'ONLINE' | 'DINE_IN' | 'TAKEAWAY',
    subtotal: 150000,
    items: [
        { menu_item_id: 1, quantity: 1, unit_price: 50000 },
        { menu_item_id: 2, quantity: 1, unit_price: 100000 },
    ] as PreviewCartItem[],
})

function addItem(): void {
    form.items.push({ menu_item_id: 0, quantity: 1, unit_price: 0 })
}
function removeItem(i: number): void {
    form.items.splice(i, 1)
}

function run(): void {
    void store.runPromotionPreview({
        channel: form.channel,
        subtotal: form.subtotal,
        items: form.items.filter((it) => it.menu_item_id > 0),
    })
}

function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
</script>

<style scoped>
.preview-panel {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    position: sticky;
    top: 12px;
}
.preview-panel h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}
.hint {
    margin: 0 0 4px;
    font-size: 12px;
    color: #6b5f54;
}
.preview-panel label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
}
.preview-panel input,
.preview-panel select {
    padding: 8px 10px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.preview-panel input:focus,
.preview-panel select:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 4px;
}
.items-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.item-row {
    display: grid;
    grid-template-columns: 1fr 60px 1fr 28px;
    gap: 6px;
}
.icon-btn {
    background: transparent;
    border: 0;
    font-size: 18px;
    line-height: 1;
    color: #6b5f54;
    cursor: pointer;
    width: 28px;
    height: 28px;
    border-radius: 6px;
}
.icon-btn.danger {
    color: var(--chili, #d71920);
}
.icon-btn.small {
    width: 26px;
    height: 26px;
    font-size: 16px;
}
.btn {
    cursor: pointer;
    padding: 9px 16px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
}
.btn.block {
    width: 100%;
}
.btn.primary {
    background: var(--chili, #d71920);
    color: #fff;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--chili, #d71920);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
    margin: 0;
}
.result {
    border-top: 1px dashed rgba(26, 14, 6, 0.12);
    padding-top: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.discount-line {
    margin: 0;
    font-size: 13px;
}
.discount-line .amount {
    margin-left: 6px;
    color: var(--chili, #d71920);
    font-weight: 800;
    font-size: 16px;
}
.result ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.result li {
    display: grid;
    grid-template-columns: 36px 1fr auto;
    gap: 6px;
    align-items: center;
    font-size: 12px;
    padding: 6px 8px;
    background: rgba(217, 25, 32, 0.04);
    border-radius: 6px;
}
.result .kind {
    color: #6b5f54;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
}
.result .slug {
    font-weight: 600;
}
.result .amount {
    color: var(--chili, #d71920);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.new-subtotal {
    margin: 4px 0 0;
    font-size: 12px;
}
.muted {
    margin: 0;
    color: #6b5f54;
    font-size: 12px;
}
</style>
