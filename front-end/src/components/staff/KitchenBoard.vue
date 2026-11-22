<script setup lang="ts">
import { useStaffLiveRefresh } from '@/composables/useStaffLiveRefresh'
import { useNotificationOrderTarget } from '@/composables/useNotificationOrderTarget'
/**
 * KitchenBoard — Redesigned for operational speed and clarity.
 *
 * Design principles:
 * - High contrast dark background for long shifts
 * - Large readable typography
 * - Obvious status colors with clear visual hierarchy
 * - Minimal decoration, maximum information density
 * - Touch-friendly large action buttons
 * - Clear overdue highlighting without chaos
 */

import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useKitchenStore } from '@/stores/staffKitchen'
import { useToast } from '@/composables/useStaffUtils'
import { useRouter } from 'vue-router'
import type { KitchenItem } from '@/api/staff'
import { staffKitchen } from '@/api/staff'

const props = defineProps<{
    status: 'ACTIVE' | 'READY'
    title: string
}>()

const kitchenStore = useKitchenStore()
const { pendingItems, preparingItems, readyItems, loading, error, updatingIds } = storeToRefs(kitchenStore)
const toast = useToast()
const router = useRouter()
const activeOrderType = ref<KitchenItem['order']['order_type']>('DINE_IN')
const initialTabSelected = ref(false)
const tabChosenManually = ref(false)
const route = useNotificationOrderTarget(() => {
    const groups = new Map<number, { id: number; status: string; order_type: KitchenItem['order']['order_type'] }>()
    for (const entry of [...pendingItems.value, ...preparingItems.value, ...readyItems.value]) {
        if (!groups.has(entry.order.id)) groups.set(entry.order.id, { id: entry.order.id, status: entry.item.status, order_type: entry.order.order_type })
    }
    return [...groups.values()]
}, order => {
    activeOrderType.value = order.order_type
    const destination = order.status === 'READY' ? 'kitchen-ready' : 'kitchen-queue'
    if ((order.status === 'READY') !== (props.status === 'READY')) {
        void router.replace({ name: destination, query: { order_id: order.id } })
    }
})
const clock = ref(Date.now())

const notifyingOrders = ref<Set<number>>(new Set())
let clockTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
    clockTimer = setInterval(() => { clock.value = Date.now() }, 1000)
    void refresh().finally(() => {
        if (!tabChosenManually.value) selectTabWithOrders()
        initialTabSelected.value = true
    })

})

onUnmounted(() => {
    if (clockTimer) clearInterval(clockTimer)

})

const items = computed(() => {
    return props.status === 'READY' ? readyItems.value : [...pendingItems.value, ...preparingItems.value]
})

async function refresh(): Promise<void> {
    await kitchenStore.fetchAll()
}

function allItemsReady(group: { entries: KitchenItem[] }): boolean {
    return group.entries.every(e => e.item.status === 'READY')
}

function isDeliveryOrder(group: { order: KitchenItem['order'] }): boolean {
    return group.order.order_type === 'ONLINE' || group.order.order_type === 'TAKEAWAY'
}

function readyMessage(order: KitchenItem['order']): string {
    if (order.order_type === 'DINE_IN') return 'Phục vụ nhận thông báo theo từng món'
    if (order.status === 'READY_NOTIFY_SHIPPER') {
        if (order.order_type === 'TAKEAWAY') return 'Sẵn sàng bàn giao'
        return order.shipper_id ? 'Đã thông báo shipper' : 'Sẵn sàng giao · Chờ phân công shipper'
    }
    if (order.status === 'READY') {
        return order.order_type === 'ONLINE' ? 'Chưa thông báo shipper' : 'Chờ báo bàn giao'
    }
    return order.order_type === 'ONLINE' ? 'Sẽ báo shipper khi tất cả món hoàn thành' : 'Chờ các món còn lại'
}

async function onNotifyShipper(group: { order: KitchenItem['order']; entries: KitchenItem[] }) {
    if (!allItemsReady(group)) {
        toast.warning('Chưa hoàn thành tất cả món. Không thể thông báo shipper.')
        return
    }
    if (notifyingOrders.value.has(group.order.id)) return
    notifyingOrders.value.add(group.order.id)
    try {
        await staffKitchen.notifyShipper(group.order.id)
        toast.success(readyMessage({ ...group.order, status: 'READY_NOTIFY_SHIPPER' }))
        await refresh()
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        toast.error(e?.response?.data?.message || 'Không thể báo đơn sẵn sàng bàn giao.')
    } finally {
        notifyingOrders.value.delete(group.order.id)
    }
}

const sections = computed(() => {
    const groups = new Map<number, { order: KitchenItem['order']; entries: KitchenItem[] }>()
    for (const entry of [...items.value].sort((a, b) => a.item.id - b.item.id)) {
        if (!groups.has(entry.order.id)) groups.set(entry.order.id, { order: entry.order, entries: [] })
        groups.get(entry.order.id)!.entries.push(entry)
    }
    const sorted = [...groups.values()].sort(
        (a, b) => (a.order.placed_at ? Date.parse(a.order.placed_at) : 0) - (b.order.placed_at ? Date.parse(b.order.placed_at) : 0) || a.order.id - b.order.id
    )
    const orderTypes: { type: KitchenItem['order']['order_type']; label: string; icon: string }[] = [
        { type: 'DINE_IN', label: 'Tại bàn', icon: '🪑' },
        { type: 'TAKEAWAY', label: 'Mang đi', icon: '📦' },
        { type: 'ONLINE', label: 'Trực tuyến', icon: '🛵' },
    ]
    return orderTypes.map(section => ({
        ...section,
        orders: sorted.filter(g => g.order.order_type === section.type),
        count: sorted.filter(g => g.order.order_type === section.type).length
    }))
})

function selectTabWithOrders(): void {
    const linkedOrderId = Number(route.query.order_id)
    const linkedOrder = linkedOrderId ? items.value.find(entry => entry.order.id === linkedOrderId) : undefined
    if (linkedOrder) {
        activeOrderType.value = linkedOrder.order.order_type
        return
    }

    const available = sections.value.filter(section => section.count > 0)
    available.sort((a, b) => {
        const aPending = a.orders.some(order => order.entries.some(entry => entry.item.status === 'PENDING'))
        const bPending = b.orders.some(order => order.entries.some(entry => entry.item.status === 'PENDING'))
        if (aPending !== bPending) return Number(bPending) - Number(aPending)
        const oldest = (section: typeof a) => {
            const placedAt = section.orders[0]?.order.placed_at
            return placedAt ? Date.parse(placedAt) : Infinity
        }
        return oldest(a) - oldest(b)
    })
    activeOrderType.value = available[0]?.type ?? 'DINE_IN'
}

function chooseTab(type: KitchenItem['order']['order_type']): void {
    tabChosenManually.value = true
    activeOrderType.value = type
}

watch([sections, updatingIds, () => notifyingOrders.value.size], () => {
    if (!initialTabSelected.value || updatingIds.value.length > 0 || notifyingOrders.value.size > 0) return
    if (sections.value.find(section => section.type === activeOrderType.value)?.count) return
    selectTabWithOrders()
}, { flush: 'post' })

// Compute elapsed time in seconds for overdue detection
function getElapsedSeconds(dateStr: string | null): number {
    if (!dateStr) return 0
    return Math.floor((clock.value - new Date(dateStr).getTime()) / 1000)
}

function formatElapsed(dateStr: string | null): string {
    if (!dateStr) return '—'
    const totalSec = getElapsedSeconds(dateStr)
    const min = Math.floor(totalSec / 60)
    const sec = totalSec % 60
    return `${min}:${sec.toString().padStart(2, '0')}`
}

function getElapsedClass(dateStr: string | null): string {
    if (!dateStr) return ''
    const min = Math.floor(getElapsedSeconds(dateStr) / 60)
    if (min >= 15) return 'time-critical'
    if (min >= 10) return 'time-warning'
    return 'time-normal'
}

function spicyLabel(level: number): string {
    return level === 0 ? 'Không cay' : `Cấp ${level}`
}

async function onReady(entry: { order: { id: number }; item: { id: number } }): Promise<void> {
    try {
        await kitchenStore.updateItemStatus(entry.order.id, entry.item.id, 'READY', undefined, true)
        toast.success('Đã hoàn thành món này')
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    }
}

useStaffLiveRefresh(() => kitchenStore.fetchAll(true), () => !loading.value && updatingIds.value.length === 0 && notifyingOrders.value.size === 0)
</script>

<template>
    <div class="kitchen-board">
        <!-- Header -->
        <header class="board-header">
            <div class="header-left">
                <h1 class="board-title">{{ title }}</h1>
                <div class="header-stats">
                    <span class="stat">
                        <span class="stat-value">{{ items.length }}</span>
                        <span class="stat-label">món</span>
                    </span>
                </div>
            </div>
        </header>

        <!-- Tab Navigation -->
        <nav class="board-tabs" aria-label="Loại đơn hàng">
            <button v-for="section in sections" :key="section.type" type="button" class="tab"
                :class="{ active: activeOrderType === section.type }" :aria-pressed="activeOrderType === section.type"
                @click="chooseTab(section.type)">
                <span class="tab-label">{{ section.icon }} {{ section.label }}</span>
                <span class="tab-count" :class="{ 'has-orders': section.count > 0 }">{{ section.count }} đơn</span>
            </button>
        </nav>

        <!-- Error/Loading State -->
        <div v-if="error" class="alert alert-error" role="alert">
            {{ error }}
        </div>
        <div v-if="loading && !kitchenStore.totalActiveItems" class="loading-state" role="status">
            Đang tải danh sách món...
        </div>

        <!-- Orders for the selected type -->
        <div class="service-columns">
            <section v-for="section in sections.filter(section => section.type === activeOrderType)" :key="section.type" class="order-section" :data-type="section.type">
                <header class="section-heading">
                    <div class="section-title-row">
                        <span class="section-icon">{{ section.icon }}</span>
                        <h2 class="section-title">{{ section.label }}</h2>
                    </div>
                    <span class="section-badge">{{ section.count }} đơn</span>
                </header>

                <!-- Empty state -->
                <div v-if="!section.orders.length" class="section-empty">
                    <span class="empty-icon">✓</span>
                    <p class="empty-text">Không có đơn {{ section.label.toLowerCase() }}</p>
                </div>

                <!-- Order cards -->
                <div v-else class="orders-list">
                    <article v-for="group in section.orders" :key="group.order.id" class="order-card" :id="`staff-order-${group.order.id}`" tabindex="-1"
                        :class="{ linked: Number(route.query.order_id) === group.order.id, overdue: getElapsedSeconds(group.order.placed_at) > 900 }">
                        <!-- Order Header -->
                        <div class="order-header">
                            <div class="order-info">
                                <span class="order-number">
                                    {{ group.order.table_code ? 'Bàn ' + group.order.table_code : section.label }}
                                </span>
                                <span class="order-code">#{{ group.order.code }}</span>
                            </div>
                            <div class="order-time" :class="getElapsedClass(group.order.placed_at)">
                                <span class="time-label">Đã chờ</span>
                                <span>{{ formatElapsed(group.order.placed_at) }}</span>
                            </div>
                        </div>

                        <!-- Order Meta -->
                        <div class="order-meta">
                            <span v-if="group.order.invoice_code" class="meta-item">
                                HĐ: {{ group.order.invoice_code }}
                            </span>
                            <span class="meta-item">
                                Đặt lúc {{ group.order.placed_at ? new Date(group.order.placed_at).toLocaleTimeString('vi-VN') : '?' }}
                            </span>
                        </div>

                        <!-- Items List -->
                        <div class="items-list">
                            <div v-for="entry in group.entries" :key="entry.item.id" class="item-row"
                                :data-status="entry.item.status">
                                <!-- Quantity Badge -->
                                <span class="item-qty">{{ entry.item.quantity }}x</span>

                                <!-- Item Details -->
                                <div class="item-details">
                                    <span class="item-name">{{ entry.item.menu_item_name }}</span>
                                    <div class="item-modifiers">
                                        <span v-if="entry.item.category_slug === 'mi-cay-han-quoc' && entry.item.spicy_level !== null && entry.item.spicy_level !== undefined"
                                            class="modifier spicy-badge">
                                            {{ spicyLabel(entry.item.spicy_level) }}
                                        </span>
                                        <span v-for="t in entry.item.toppings" :key="t.id" class="modifier topping-badge">
                                            + {{ t.name }}
                                        </span>
                                    </div>
                                    <p v-if="entry.item.note" class="item-note">
                                        📝 {{ entry.item.note }}
                                    </p>
                                    <!-- Thiếu nguyên liệu: bếp thấy trước khi bấm "Bắt đầu nấu" -->
                                    <p v-if="entry.item.shortages?.length" class="shortage-hint">
                                        ⚠ Thiếu
                                        {{ entry.item.shortages.map(s => `${s.ingredient} (${s.shortage}${s.unit})`).join(', ') }}
                                    </p>
                                </div>

                                <div v-if="status === 'ACTIVE'" class="item-actions">
                                    <button type="button" class="action-btn complete"
                                        :aria-label="`Xác nhận hoàn thành ${entry.item.menu_item_name}`"
                                        :disabled="updatingIds.includes(entry.item.id)" @click="onReady(entry)">
                                        {{ updatingIds.includes(entry.item.id) ? 'Đang xác nhận…' : 'Xác nhận hoàn thành' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Ready State Indicator -->
                        <div v-if="status === 'READY'" class="ready-indicator">
                            <span class="ready-text">
                                {{ readyMessage(group.order) }}
                            </span>
                        </div>

                        <!-- Đơn đã hoàn thành trước khi áp dụng thông báo tự động -->
                        <div v-if="status === 'READY' && isDeliveryOrder(group) && group.order.status === 'READY'"
                            class="shipper-action">
                            <span class="shipper-label">Tất cả món đã xong</span>
                            <button type="button" class="action-btn notify" :disabled="notifyingOrders.has(group.order.id) || !allItemsReady(group)"
                                @click="onNotifyShipper(group)">
                                {{ notifyingOrders.has(group.order.id) ? 'Đang thông báo…' : group.order.order_type === 'ONLINE' ? 'Thông báo shipper' : 'Báo sẵn sàng bàn giao' }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>
        </div>

    </div>
</template>

<style scoped>
.order-card.linked { outline: 3px solid var(--color-brand-action); outline-offset: 3px; }
/* ================================================
   KITCHEN BOARD - Operational UI
   High contrast, large typography, clear status
   ================================================ */

.kitchen-board {
    --kds-action: var(--color-brand-action);
    --kds-action-hover: var(--color-brand-action-hover);
    --kds-muted: #aeb5c4;
    container: kitchen / inline-size;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 24px;
    min-height: 100vh;
    background: var(--color-operations-background);
    color: #f0f0f0;
    padding: 24px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Header */
.board-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 16px 24px;
    background: var(--color-operations-surface);
    border-radius: 12px;
    border: 1px solid #2a2f3c;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 24px;
}

.board-title {
    font-size: 28px;
    font-weight: 800;
    color: #ffffff;
    margin: 0;
    letter-spacing: -0.5px;
}

.header-stats {
    display: flex;
    gap: 16px;
}

.stat {
    display: flex;
    align-items: baseline;
    gap: 6px;
    padding: 8px 16px;
    background: var(--color-operations-raised);
    border-radius: 8px;
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #ff7a00;
}

.stat-label {
    font-size: 14px;
    color: #8b919e;
}

/* Tab Navigation */
.board-tabs {
    display: flex;
    gap: 8px;
    padding: 8px;
    background: var(--color-operations-surface);
    border-radius: 12px;
    border: 1px solid #2a2f3c;
    overflow-x: auto;
}

.tab {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 24px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    color: #8b919e;
    font-size: 16px;
    font-weight: 600;
    transition: all 150ms ease;
    cursor: pointer;
    white-space: nowrap;
    flex: 1;
}

.tab:hover {
    background: var(--color-operations-raised);
    color: #c0c4ce;
}

.tab.active {
    background: #2d3342;
    color: #ffffff;
    border-color: #505c70;
}

.tab-count {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    min-width: 36px;
    text-align: center;
    background: #4b5563;
    color: #f0f0f0;
}

.tab-count.has-orders {
    background: #c91920;
    color: #ffffff;
}

/* Alert States */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 500;
}

.alert-error {
    background: #450a0a;
    color: #fecaca;
    border: 1px solid #7f1d1d;
}

.loading-state {
    padding: 40px;
    text-align: center;
    color: #8b919e;
    font-size: 16px;
}

/* Main Grid */
.service-columns {
    width: 100%;
    min-width: 0;
}

/* Order Section */
.order-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    padding: 12px;
    background: var(--color-operations-surface);
    border-radius: 12px;
    border: 1px solid #2a2f3c;
    border-top: 4px solid #3b82f6;
}

.order-section[data-type='TAKEAWAY'] {
    border-top-color: #f97316;
}

.order-section[data-type='ONLINE'] {
    border-top-color: #8b5cf6;
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 10px;
    border-bottom: 1px solid #2a2f3c;
}

.section-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-icon {
    font-size: 24px;
}

.section-title {
    font-size: 20px;
    font-weight: 700;
    color: #ffffff;
    margin: 0;
}

.section-badge {
    padding: 6px 14px;
    background: var(--color-operations-raised);
    color: #8b919e;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

/* Empty State */
.section-empty {
    min-height: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 24px;
    background: #141620;
    border-radius: 8px;
    border: 1px dashed #2a2f3c;
}

.empty-icon {
    font-size: 32px;
    color: #4ade80;
}

.empty-text {
    font-size: 15px;
    color: var(--kds-muted);
    margin: 0;
}

/* Order Cards */
.orders-list {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}

@container kitchen (min-width: 620px) {
    .orders-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@container kitchen (min-width: 940px) {
    .orders-list { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@container kitchen (min-width: 1250px) {
    .orders-list { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}

@container kitchen (min-width: 1550px) {
    .orders-list { grid-template-columns: repeat(5, minmax(0, 1fr)); }
}

.order-card {
    min-width: 0;
    background: #141620;
    border-radius: 10px;
    padding: 12px;
    border: 1px solid var(--color-operations-raised);
    transition: border-color 150ms ease;
}

.order-card.overdue {
    border-color: #b45309;
    box-shadow: inset 3px 0 #f59e0b;
}

/* Order Header */
.order-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--color-operations-raised);
}

.order-info {
    min-width: 0;
    overflow-wrap: anywhere;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-number {
    font-size: 17px;
    font-weight: 800;
    color: #ffffff;
}

.order-code {
    font-size: 12px;
    color: var(--kds-muted);
    font-weight: 500;
}

.order-time {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    flex-shrink: 0;
    font-size: 22px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    padding: 5px 8px;
    border-radius: 8px;
}

.order-time.time-normal {
    color: #22c55e;
    background: rgba(34, 197, 94, 0.1);
}

.order-time.time-warning {
    color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
}

.order-time.time-critical {
    color: #ef4444;
    background: rgba(239, 68, 68, 0.1);
    animation: pulse 1s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Order Meta */
.order-meta {
    flex-wrap: wrap;
    overflow-wrap: anywhere;
    display: flex;
    gap: 4px 10px;
    margin-bottom: 10px;
}

.meta-item {
    font-size: 12px;
    color: var(--kds-muted);
}

/* Items List */
.items-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.item-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    gap: 8px;
    padding: 8px;
    background: var(--color-operations-surface);
    border-radius: 8px;
    border-left: 4px solid #4b5563;
}

.item-row[data-status='PENDING'] {
    border-left-color: #f59e0b;
}

.item-row[data-status='PREPARING'] {
    border-left-color: #f97316;
}

.item-row[data-status='READY'] {
    border-left-color: #22c55e;
}

.item-qty {
    font-size: 15px;
    font-weight: 800;
    color: #f0f0f0;
    background: var(--color-operations-raised);
    padding: 5px 7px;
    border-radius: 8px;
    min-width: 32px;
    text-align: center;
}

.item-details {
    min-width: 0;
    overflow-wrap: anywhere;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.item-name {
    line-height: 1.45;
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
}

.item-modifiers {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.modifier {
    max-width: 100%;
    flex: 0 0 auto;
    overflow-wrap: anywhere;
    padding: 3px 7px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.spicy-badge {
    background: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
}

.topping-badge {
    background: var(--color-operations-raised);
    color: #c0c4ce;
}

.item-note {
    margin: 0;
    padding: 6px 8px;
    background: rgba(251, 191, 36, 0.1);
    border-left: 3px solid #fbbf24;
    border-radius: 0 6px 6px 0;
    font-size: 12px;
    color: #fcd34d;
}

/* Cảnh báo thiếu nguyên liệu — phải nổi bật nhưng không tranh chấp với nút hành động */
.shortage-hint {
    margin: 6px 0 0;
    padding: 6px 8px;
    background: rgba(228, 38, 61, 0.12);
    border-left: 3px solid var(--chili-red, #e4263d);
    border-radius: 0 6px 6px 0;
    font-size: 13px;
    font-weight: 600;
    color: #fda4af;
}

/* Item Actions */
.item-actions {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.action-btn {
    min-height: 44px;
    padding: 8px 10px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 150ms ease;
    white-space: nowrap;
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.action-btn.complete {
    background: #22c55e;
    color: #ffffff;
}

.action-btn.complete:hover:not(:disabled) {
    background: #16a34a;
}

.action-btn.notify {
    background: #8b5cf6;
    color: #ffffff;
    padding: 8px 12px;
}

.action-btn.notify:hover:not(:disabled) {
    background: #7c3aed;
}

/* Ready State */
.ready-indicator {
    margin-top: 10px;
    padding: 9px;
    background: rgba(34, 197, 94, 0.1);
    border-radius: 8px;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.ready-text {
    font-size: 15px;
    font-weight: 700;
    color: #4ade80;
}

/* Shipper Action */
.shipper-action {
    margin-top: 9px;
    padding-top: 9px;
    border-top: 1px dashed #2a2f3c;
    display: flex;
    align-items: stretch;
    justify-content: space-between;
    gap: 12px;
    flex-direction: column;
}

.shipper-label {
    font-size: 14px;
    color: var(--kds-muted);
}

@container kitchen (max-width: 739px) {
    .board-header { flex-direction: column; align-items: stretch; padding: 16px; }
    .header-left { flex-wrap: wrap; gap: 12px; min-width: 0; }
    .board-title { font-size: 22px; overflow-wrap: anywhere; }
    .tab { flex: 0 0 auto; padding: 12px; }
    .order-time { font-size: 22px; padding: 5px 8px; }
}

@media (max-width: 600px) {
    .kitchen-board { padding: 12px; gap: 16px; }
    .order-section { padding: 8px; }
    .order-card { padding: 10px; }
    .item-row { padding: 10px; gap: 8px; }
}

.time-label {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
}

.action-btn:focus-visible,
.tab:focus-visible {
    outline: 3px solid var(--kds-action);
    outline-offset: 3px;
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .order-card.overdue,
    .order-time.time-critical {
        animation: none;
    }


}
</style>
