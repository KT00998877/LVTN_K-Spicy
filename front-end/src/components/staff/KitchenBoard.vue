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

import { computed, onMounted, onUnmounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useKitchenStore, ShortageError } from '@/stores/staffKitchen'
import { useToast } from '@/composables/useStaffUtils'
import { useAppDialog } from '@/composables/useAppDialog'
import type { OrderItemStatus } from '@/types/staff'
import { RouterLink, useRouter } from 'vue-router'
import type { KitchenItem, KitchenShortage } from '@/api/staff'
import { staffKitchen } from '@/api/staff'
import ShortageDialog from '@/components/staff/ShortageDialog.vue'

const props = defineProps<{
    status: OrderItemStatus
    title: string
}>()

const kitchenStore = useKitchenStore()
const { pendingItems, preparingItems, readyItems, loading, error, updatingIds } = storeToRefs(kitchenStore)
const toast = useToast()
const appDialog = useAppDialog()
const router = useRouter()
const route = useNotificationOrderTarget(() => {
    const groups = new Map<number, { id: number; status: string }>()
    for (const entry of [...pendingItems.value, ...preparingItems.value, ...readyItems.value]) {
        if (!groups.has(entry.order.id)) groups.set(entry.order.id, { id: entry.order.id, status: entry.item.status })
    }
    return [...groups.values()]
}, order => {
    const paths: Record<string, string> = { PENDING: 'queue', PREPARING: 'preparing', READY: 'ready' }
    if (order.status !== props.status) void router.replace({ path: `/staff/kitchen/${paths[order.status]}`, query: { order_id: order.id } })
})
const clock = ref(Date.now())

const notifyingOrders = ref<Set<number>>(new Set())
let clockTimer: ReturnType<typeof setInterval> | undefined

// ====== Cảnh báo thiếu nguyên liệu (bếp tự quyết) ======
const shortageOpen = ref(false)
const shortageBusy = ref(false)
const shortageList = ref<KitchenShortage[]>([])
const shortageTarget = ref<{ orderId: number; itemId: number; dishName: string; orderCode: string } | null>(null)

onMounted(() => {
    clockTimer = setInterval(() => { clock.value = Date.now() }, 1000)
    void refresh()

})

onUnmounted(() => {
    if (clockTimer) clearInterval(clockTimer)

})

const items = computed(() => {
    if (props.status === 'PENDING') return pendingItems.value
    if (props.status === 'PREPARING') return preparingItems.value
    if (props.status === 'READY') return readyItems.value
    return []
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

async function onNotifyShipper(group: { order: KitchenItem['order']; entries: KitchenItem[] }) {
    if (!allItemsReady(group)) {
        toast.warning('Chưa hoàn thành tất cả món. Không thể thông báo shipper.')
        return
    }
    if (notifyingOrders.value.has(group.order.id)) return
    notifyingOrders.value.add(group.order.id)
    try {
        await staffKitchen.notifyShipper(group.order.id)
        toast.success('Đã thông báo shipper. Đơn sẵn sàng bàn giao.')
        await refresh()
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        toast.error(e?.response?.data?.message || 'Không thể thông báo shipper.')
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
    return [
        { type: 'DINE_IN', label: 'Tại bàn', icon: '🪑' },
        { type: 'TAKEAWAY', label: 'Mang đi', icon: '📦' },
        { type: 'ONLINE', label: 'Trực tuyến', icon: '🛵' },
    ].filter(section => {
        if (section.type === 'ONLINE') return sorted.some(g => g.order.order_type === 'ONLINE')
        return true
    }).map(section => ({
        ...section,
        orders: sorted.filter(g => g.order.order_type === section.type),
        count: sorted.filter(g => g.order.order_type === section.type).length
    }))
})

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

async function onUndo(entry: KitchenItem): Promise<void> {
    try {
        await kitchenStore.updateItemStatus(entry.order.id, entry.item.id, 'PENDING')
        toast.success('Đã hoàn tác, món trở về chờ nấu')
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Không thể hoàn tác')
    }
}

function spicyLabel(level: number): string {
    return level === 0 ? 'Không cay' : `Cấp ${level}`
}

async function onStart(entry: { order: { id: number; code: string }; item: { id: number; menu_item_name: string } }): Promise<void> {
    try {
        await kitchenStore.updateItemStatus(entry.order.id, entry.item.id, 'PREPARING')
        toast.success('Bắt đầu chế biến')
    } catch (err) {
        // Thiếu nguyên liệu → mở dialog để bếp quyết định nấu tiếp hay hủy món.
        if (err instanceof ShortageError) {
            shortageTarget.value = {
                orderId: entry.order.id,
                itemId: entry.item.id,
                dishName: entry.item.menu_item_name,
                orderCode: entry.order.code,
            }
            shortageList.value = err.shortages
            shortageOpen.value = true
            return
        }
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    }
}

/** Bếp chọn "Tiếp tục nấu": trừ tới 0, tạo yêu cầu nhập kho, vẫn nấu món này. */
async function onShortageContinue(): Promise<void> {
    const target = shortageTarget.value
    if (!target) return
    shortageBusy.value = true
    try {
        await kitchenStore.updateItemStatus(target.orderId, target.itemId, 'PREPARING', undefined, true)
        shortageOpen.value = false
        toast.success('Đã tiếp tục nấu. Đã gửi yêu cầu nhập kho cho quản lý.')
        shortageTarget.value = null
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Không bắt đầu được món')
    } finally {
        shortageBusy.value = false
    }
}

/** Bếp chọn "Hủy món": hủy món với lý do hết nguyên liệu, quản lý sẽ ẩn món. */
async function onShortageCancel(): Promise<void> {
    const target = shortageTarget.value
    if (!target) return
    shortageBusy.value = true
    try {
        await kitchenStore.updateItemStatus(target.orderId, target.itemId, 'CANCELLED', 'Hết nguyên liệu')
        shortageOpen.value = false
        toast.warning('Đã hủy món. Quản lý đã được thông báo ẩn món khỏi thực đơn.')
        shortageTarget.value = null
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Không hủy được món')
    } finally {
        shortageBusy.value = false
    }
}

async function onReady(entry: { order: { id: number }; item: { id: number } }): Promise<void> {
    try {
        await kitchenStore.updateItemStatus(entry.order.id, entry.item.id, 'READY')
        toast.success('Đã hoàn thành món này')
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    }
}

async function onCancel(entry: { order: { id: number }; item: { id: number; menu_item_name: string } }): Promise<void> {
    if (!await appDialog.confirm({ title: 'Hủy món này?', message: `Món “${entry.item.menu_item_name}” sẽ bị hủy khỏi đơn.`, confirmLabel: 'Hủy món', tone: 'danger' })) return
    const reason = (await appDialog.prompt({ title: 'Lý do hủy món', message: 'Ghi lại lý do để nhân viên phục vụ nắm được tình hình.', label: 'Lý do', initialValue: 'Hết nguyên liệu', confirmLabel: 'Gửi lý do' }))?.trim()
    if (!reason) return
    try {
        await kitchenStore.updateItemStatus(entry.order.id, entry.item.id, 'CANCELLED', reason)
        toast.warning('Đã hủy món')
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
        <nav class="board-tabs" aria-label="Trạng thái bếp">
            <RouterLink to="/staff/kitchen/queue" class="tab" :class="{ active: status === 'PENDING' }">
                <span class="tab-label">Chờ nấu</span>
                <span class="tab-count pending">{{ pendingItems.length }}</span>
            </RouterLink>
            <RouterLink to="/staff/kitchen/preparing" class="tab" :class="{ active: status === 'PREPARING' }">
                <span class="tab-label">Đang nấu</span>
                <span class="tab-count preparing">{{ preparingItems.length }}</span>
            </RouterLink>
            <RouterLink to="/staff/kitchen/ready" class="tab" :class="{ active: status === 'READY' }">
                <span class="tab-label">Chờ bưng</span>
                <span class="tab-count ready">{{ readyItems.length }}</span>
            </RouterLink>
        </nav>

        <!-- Error/Loading State -->
        <div v-if="error" class="alert alert-error" role="alert">
            {{ error }}
        </div>
        <div v-if="loading && !kitchenStore.totalActiveItems" class="loading-state" role="status">
            Đang tải danh sách món...
        </div>

        <!-- Main Content Grid -->
        <div class="service-columns">
            <!-- Each column = one order type -->
            <section v-for="section in sections" :key="section.type" class="order-section" :data-type="section.type">
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
                                <span class="time-label">?? ch?</span>
                                <span>{{ formatElapsed(group.order.placed_at) }}</span>
                            </div>
                        </div>

                        <!-- Order Meta -->
                        <div class="order-meta">
                            <span v-if="group.order.invoice_code" class="meta-item">
                                HĐ: {{ group.order.invoice_code }}
                            </span>
                            <span class="meta-item">
                                ??t l?c {{ group.order.placed_at ? new Date(group.order.placed_at).toLocaleTimeString('vi-VN') : '?' }}
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
                                        <span v-if="entry.item.spicy_level !== null && entry.item.spicy_level !== undefined"
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

                                <!-- Actions -->
                                <div class="item-actions" v-if="['PENDING', 'PREPARING'].includes(status)">
                                    <!-- PENDING: Start -->
                                    <button v-if="status === 'PENDING'" class="action-btn start"
                                        :disabled="updatingIds.includes(entry.item.id)" @click="onStart(entry)">
                                        Bắt đầu nấu
                                    </button>

                                    <!-- PREPARING: Complete / Undo -->
                                    <template v-if="status === 'PREPARING'">
                                        <button class="action-btn undo" :disabled="updatingIds.includes(entry.item.id)"
                                            @click="onUndo(entry)">
                                            ↩ Hoàn tác
                                        </button>
                                        <button class="action-btn complete" :disabled="updatingIds.includes(entry.item.id)"
                                            @click="onReady(entry)">
                                            ✓ Xong
                                        </button>
                                    </template>

                                    <!-- Cancel -->
                                    <button v-if="['PENDING', 'PREPARING'].includes(status)"
                                        class="action-btn cancel"
                                        :title="`H?y m?n ${entry.item.menu_item_name}`"
                                        :aria-label="`H?y m?n ${entry.item.menu_item_name}`"
                                        :disabled="updatingIds.includes(entry.item.id)" @click="onCancel(entry)">
                                        ✕
                                    </button>
                                </div>
                            </div>
                        </div>

                        <footer v-if="status !== 'READY'" class="order-status">
                            {{ status === 'PENDING' ? 'Ch? ch? bi?n' : '?ang ch? bi?n' }}
                        </footer>

                        <!-- Ready State Indicator -->
                        <div v-if="status === 'READY'" class="ready-indicator">
                            <span class="ready-text">
                                {{ group.order.order_type === 'DINE_IN' ? 'Chờ phục vụ mang ra' : 'Món đã hoàn thành' }}
                            </span>
                        </div>

                        <!-- Notify Shipper (ONLINE/TAKEAWAY) -->
                        <div v-if="status === 'READY' && isDeliveryOrder(group) && group.entries[0] === group.entries[0]"
                            class="shipper-action">
                            <span class="shipper-label">Tất cả món đã xong</span>
                            <button class="action-btn notify" :disabled="notifyingOrders.has(group.order.id) || !allItemsReady(group)"
                                @click="onNotifyShipper(group)">
                                {{ notifyingOrders.has(group.order.id) ? 'Đang thông báo...' : '📨 Thông báo shipper' }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <!-- Cảnh báo thiếu nguyên liệu: bếp quyết nấu tiếp hay hủy món -->
        <ShortageDialog
            :open="shortageOpen"
            :dish-name="shortageTarget?.dishName ?? ''"
            :order-code="shortageTarget?.orderCode ?? ''"
            :shortages="shortageList"
            :busy="shortageBusy"
            @continue="onShortageContinue"
            @cancel="onShortageCancel"
        />
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
}

.tab {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 24px;
    background: transparent;
    border-radius: 8px;
    text-decoration: none;
    color: #8b919e;
    font-size: 16px;
    font-weight: 600;
    transition: all 150ms ease;
}

.tab:hover {
    background: var(--color-operations-raised);
    color: #c0c4ce;
}

.tab.active {
    background: #2d3342;
    color: #ffffff;
}

.tab-count {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    min-width: 36px;
    text-align: center;
}

.tab-count.pending {
    background: #fef3c7;
    color: #92400e;
}

.tab-count.preparing {
    background: #ffedd5;
    color: #c2410c;
}

.tab-count.ready {
    background: #dcfce7;
    color: #166534;
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
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

/* Order Section */
.order-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
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
    padding-bottom: 16px;
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
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-card {
    background: #141620;
    border-radius: 12px;
    padding: 16px;
    border: 2px solid var(--color-operations-raised);
    transition: border-color 150ms ease;
}

.order-card.overdue {
    border-color: #ef4444;
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.15);
}

/* Order Header */
.order-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    padding-bottom: 12px;
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
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
}

.order-code {
    font-size: 14px;
    color: var(--kds-muted);
    font-weight: 500;
}

.order-time {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    flex-shrink: 0;
    font-size: 32px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    padding: 6px 12px;
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
    gap: 16px;
    margin-bottom: 16px;
}

.meta-item {
    font-size: 13px;
    color: var(--kds-muted);
}

/* Items List */
.items-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.item-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
    gap: 12px;
    padding: 12px;
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
    font-size: 20px;
    font-weight: 800;
    color: #f0f0f0;
    background: var(--color-operations-raised);
    padding: 6px 8px;
    border-radius: 8px;
    min-width: 40px;
    text-align: center;
}

.item-details {
    min-width: 0;
    overflow-wrap: anywhere;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.item-name {
    line-height: 1.45;
    font-size: 18px;
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
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 13px;
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
    padding: 10px 12px;
    background: rgba(251, 191, 36, 0.1);
    border-left: 3px solid #fbbf24;
    border-radius: 0 6px 6px 0;
    font-size: 14px;
    color: #fcd34d;
}

/* Cảnh báo thiếu nguyên liệu — phải nổi bật nhưng không tranh chấp với nút hành động */
.shortage-hint {
    margin: 6px 0 0;
    padding: 8px 12px;
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
    gap: 8px;
}

.action-btn {
    min-height: 44px;
    padding: 12px 16px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 150ms ease;
    white-space: nowrap;
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.action-btn.start {
    flex: 1;
    background: var(--kds-action);
    color: var(--color-white);
}

.action-btn.start:hover:not(:disabled) {
    background: var(--kds-action-hover);
}

.action-btn.complete {
    background: #22c55e;
    color: #ffffff;
}

.action-btn.complete:hover:not(:disabled) {
    background: #16a34a;
}

.action-btn.undo {
    background: #374151;
    color: #d1d5db;
}

.action-btn.undo:hover:not(:disabled) {
    background: #4b5563;
}

.action-btn.cancel {
    flex: 0 0 44px;
    min-width: 44px;
    background: transparent;
    color: var(--kds-muted);
    border: 1px solid #374151;
    padding: 12px;
}

.action-btn.cancel:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border-color: #ef4444;
}

.action-btn.notify {
    background: #8b5cf6;
    color: #ffffff;
    padding: 12px 20px;
}

.action-btn.notify:hover:not(:disabled) {
    background: #7c3aed;
}

/* Ready State */
.ready-indicator {
    margin-top: 16px;
    padding: 14px;
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
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #2a2f3c;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.shipper-label {
    font-size: 14px;
    color: var(--kds-muted);
}

/* Responsive: use the board width after sidebar and layout padding. */
@container kitchen (min-width: 740px) {
    .service-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@container kitchen (min-width: 1140px) {
    .service-columns { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .service-columns:has(> :last-child:nth-child(2)) {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@container kitchen (max-width: 739px) {
    .board-header { flex-direction: column; align-items: stretch; padding: 16px; }
    .header-left { flex-wrap: wrap; gap: 12px; min-width: 0; }
    .board-title { font-size: 22px; overflow-wrap: anywhere; }
    .board-tabs { flex-direction: column; }
    .tab { justify-content: space-between; padding: 12px; }
    .order-time { font-size: 28px; padding: 6px 8px; }
    .shipper-action { flex-direction: column; align-items: stretch; }
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

.order-status {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--color-operations-raised);
    color: var(--kds-muted);
    font-size: 14px;
    font-weight: 600;
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
