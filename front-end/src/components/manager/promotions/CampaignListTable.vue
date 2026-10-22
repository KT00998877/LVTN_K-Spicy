<template>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tên &amp; thời gian</th>
                    <th>Slug</th>
                    <th>Khuyến mãi con</th>
                    <th>Trạng thái</th>
                    <th class="actions-col">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in rows" :key="c.id" :class="{ dim: !c.is_active }">
                    <td>
                        <div class="cell-primary">
                            <strong>{{ c.name }}</strong>
                            <small v-if="c.description">{{ c.description }}</small>
                            <small v-if="c.starts_at || c.ends_at" class="muted">
                                {{ c.starts_at ? formatDate(c.starts_at) : '—' }} →
                                {{ c.ends_at ? formatDate(c.ends_at) : '—' }}
                            </small>
                        </div>
                    </td>
                    <td>
                        <code class="slug">/{{ c.slug }}</code>
                    </td>
                    <td>
                        <strong>{{ c.promotions_count }}</strong>
                        <small class="muted"> chương trình</small>
                    </td>
                    <td>
                        <button
                            class="status-toggle"
                            :class="{ on: c.is_active }"
                            :disabled="saving"
                            @click="$emit('toggle', c)"
                        >
                            <span class="dot" />
                            {{ c.is_active ? 'Kích hoạt' : 'Tạm ngưng' }}
                        </button>
                        <div v-if="c.is_live" class="live-tag">ĐANG CHẠY</div>
                        <div v-else-if="c.is_within_window && !c.is_active" class="muted small">
                            Đã tắt
                        </div>
                    </td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <button class="btn xs ghost" @click="$emit('view', c)">Xem</button>
                            <button class="btn xs ghost" @click="$emit('edit', c)">Sửa</button>
                            <button
                                class="btn xs ghost danger"
                                :disabled="saving"
                                @click="$emit('delete', c)"
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
import type { ManagerCampaign } from '@/types/campaign'

interface Props {
    rows: ManagerCampaign[]
    saving?: boolean
}
withDefaults(defineProps<Props>(), { saving: false })
defineEmits<{
    view: [ManagerCampaign]
    edit: [ManagerCampaign]
    delete: [ManagerCampaign]
    toggle: [ManagerCampaign]
}>()

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
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
    min-width: 760px;
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
    gap: 3px;
    max-width: 320px;
}
.cell-primary strong {
    font-size: 14px;
}
.cell-primary small {
    color: #6b5f54;
    font-size: 12px;
}
.slug {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: rgba(26, 14, 6, 0.05);
    color: #1a1a1a;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
}
.muted {
    color: #6b5f54;
    font-size: 11px;
}
.muted.small {
    font-size: 10px;
}
.status-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid transparent;
    background: rgba(26, 14, 6, 0.06);
    color: #6b5f54;
    cursor: pointer;
    font-family: inherit;
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
.live-tag {
    margin-top: 4px;
    font-size: 10px;
    font-weight: 700;
    color: var(--chili, #d71920);
    letter-spacing: 0.5px;
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
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
