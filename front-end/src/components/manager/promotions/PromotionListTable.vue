<template>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tên &amp; phạm vi</th>
                    <th>Loại</th>
                    <th>Ưu tiên</th>
                    <th>Thời gian</th>
                    <th>Sử dụng</th>
                    <th>Trạng thái</th>
                    <th class="actions-col">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in rows" :key="p.id" :class="{ dim: !p.is_active }">
                    <td>
                        <div class="cell-primary">
                            <strong>{{ p.name }}</strong>
                            <small v-if="p.description">{{ p.description }}</small>
                            <div class="meta-row">
                                <span v-if="p.kind === 'PERCENT_OFF' || p.kind === 'FIXED_OFF'" class="meta-chip">
                                    Phạm vi: {{ scopeLabel(p) }}
                                </span>
                                <span v-if="p.campaign" class="meta-chip campaign">{{ p.campaign.name }}</span>
                                <span v-if="p.stacking_mode === 'STACK'" class="meta-chip stacking">
                                    Cộng dồn
                                </span>
                                <span v-else-if="p.stacking_mode === 'EXCLUSIVE'" class="meta-chip stacking warn">
                                    Độc quyền
                                </span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="kind-pill" :class="`kind-${p.kind.toLowerCase()}`">
                            {{ p.kind_label }}
                        </span>
                        <div class="badge">{{ p.badge }}</div>
                    </td>
                    <td class="num">{{ p.priority }}</td>
                    <td>
                        <div v-if="p.starts_at || p.ends_at" class="muted small">
                            <span v-if="p.starts_at">{{ formatDate(p.starts_at) }}</span>
                            <span v-if="p.starts_at && p.ends_at"> → </span>
                            <span v-if="p.ends_at">{{ formatDate(p.ends_at) }}</span>
                        </div>
                        <div v-else class="muted">Không giới hạn</div>
                        <small v-if="p.is_within_window === false && p.is_active" class="warn">
                            Ngoài thời gian
                        </small>
                    </td>
                    <td>
                        <div class="quota">
                            <div class="quota-bar">
                                <div class="quota-fill" :style="{ width: usagePercent(p) + '%' }" />
                            </div>
                            <small>{{ p.used_count }} / {{ p.usage_limit ?? '∞' }}</small>
                        </div>
                    </td>
                    <td>
                        <button
                            class="status-toggle"
                            :class="{ on: p.is_active }"
                            :aria-label="p.is_active ? 'Đang kích hoạt, bấm để tắt' : 'Đang tắt, bấm để bật'"
                            :disabled="saving"
                            @click="$emit('toggle', p)"
                        >
                            <span class="dot" />
                            {{ p.is_active ? 'Kích hoạt' : 'Tạm ngưng' }}
                        </button>
                    </td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <button class="btn xs ghost" @click="$emit('edit', p)">Sửa</button>
                            <button class="btn xs ghost" @click="$emit('usages', p)">Lịch sử</button>
                            <button
                                class="btn xs ghost danger"
                                :disabled="saving"
                                @click="$emit('delete', p)"
                            >
                                Xoá
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup lang="ts">
import type { ManagerPromotion } from '@/types/promotion'

interface Props {
    rows: ManagerPromotion[]
    saving?: boolean
}
withDefaults(defineProps<Props>(), { saving: false })
defineEmits<{
    edit: [ManagerPromotion]
    delete: [ManagerPromotion]
    toggle: [ManagerPromotion]
    usages: [ManagerPromotion]
}>()

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}

function usagePercent(p: ManagerPromotion): number {
    if (!p.usage_limit) return 0
    return Math.min(100, Math.round((p.used_count / p.usage_limit) * 100))
}

function scopeLabel(p: ManagerPromotion): string {
    if (p.kind === 'BXGY') {
        const ids = (p.config.get_item_ids ?? []).length
        return `${ids} món tặng`
    }
    if (p.kind === 'COMBO') {
        return `${p.config.bundle_items.length} món trong combo`
    }
    const cfg = p.config
    if (cfg.applies_to === 'ALL') return 'Toàn menu'
    if (cfg.applies_to === 'CATEGORY') return `${p.items.length} danh mục`
    if (cfg.applies_to === 'MENU_ITEM') return `${p.items.length} món`
    return '—'
}
</script>

<style scoped>
.table-wrap {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 12px;
    overflow: auto;
}
table {
    width: 100%;
    min-width: 980px;
    border-collapse: collapse;
    font-size: 13px;
}
th,
td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
    vertical-align: middle;
}
th {
    background: rgba(26, 14, 6, 0.03);
    color: #6b5f54;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    font-weight: 700;
}
tbody tr:hover {
    background: rgba(215, 25, 32, 0.03);
}
tbody tr.dim {
    opacity: 0.55;
}
tbody tr:last-child td {
    border-bottom: 0;
}
.cell-primary {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 320px;
}
.cell-primary strong {
    font-size: 14px;
}
.cell-primary small {
    color: #6b5f54;
    font-size: 12px;
}
.meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 2px;
}
.meta-chip {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    background: rgba(26, 14, 6, 0.06);
    color: #1a1a1a;
}
.meta-chip.campaign {
    background: rgba(217, 25, 32, 0.1);
    color: var(--chili, #d71920);
}
.meta-chip.stacking {
    background: rgba(34, 139, 80, 0.12);
    color: #166534;
}
.meta-chip.stacking.warn {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
}
.kind-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 11px;
}
.kind-pill.kind-percent_off {
    background: rgba(217, 119, 6, 0.12);
    color: #b45309;
}
.kind-pill.kind-fixed_off {
    background: rgba(15, 118, 110, 0.12);
    color: #0f766e;
}
.kind-pill.kind-bxgy {
    background: rgba(124, 58, 237, 0.12);
    color: #6d28d9;
}
.kind-pill.kind-combo {
    background: rgba(37, 99, 235, 0.12);
    color: #1d4ed8;
}
.badge {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 11px;
    color: var(--chili, #d71920);
    margin-top: 4px;
}
.muted {
    color: #6b5f54;
    font-size: 12px;
}
.muted.small {
    font-size: 11px;
}
.warn {
    color: var(--chili, #d71920);
    font-size: 11px;
    font-weight: 600;
}
.num {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}
.quota {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 110px;
}
.quota-bar {
    height: 6px;
    background: rgba(26, 14, 6, 0.08);
    border-radius: 999px;
    overflow: hidden;
}
.quota-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--chili, #d71920), #ff574c);
    border-radius: 999px;
    transition: width 0.2s ease;
}
.quota small {
    color: #6b5f54;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}
.status-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid transparent;
    background: rgba(26, 14, 6, 0.06);
    color: #6b5f54;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s, color 0.12s;
}
.status-toggle .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
}
.status-toggle.on {
    background: rgba(34, 139, 80, 0.12);
    color: #166534;
}
.status-toggle.on .dot {
    background: #16a34a;
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
}
.status-toggle:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.actions-col {
    text-align: right;
    width: 220px;
}
.row-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}
.btn {
    cursor: pointer;
    padding: 9px 16px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
    transition: background 0.12s, transform 0.06s;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn.xs {
    padding: 6px 10px;
    font-size: 12px;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
.btn.ghost:hover {
    background: rgba(26, 14, 6, 0.05);
}
.btn.ghost.danger {
    color: var(--chili, #d71920);
    border-color: rgba(215, 25, 32, 0.25);
}
.btn.ghost.danger:hover {
    background: rgba(215, 25, 32, 0.08);
}
</style>
