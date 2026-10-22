<template>
    <div class="wt-view">

        <!-- ===== Header bar ===== -->
        <div class="wt-header">
            <div class="wt-title-row">
                <h1 class="wt-title">Sơ đồ bàn</h1>
                <div class="wt-header-actions">
                    <span class="wt-clock">{{ currentTime }}</span>
                    <button
                        class="btn-refresh"
                        :disabled="loading"
                        @click="refresh"
                        title="Làm mới"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                        </svg>
                        <span>Làm mới</span>
                    </button>
                </div>
            </div>

            <!-- Stats row — primary scan target -->
            <div class="wt-stats" role="status" aria-label="Tóm tắt trạng thái bàn">
                <div class="stat-chip stat-available">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                    <span class="stat-n">{{ stats.available }}</span>
                    <span class="stat-l">Trống</span>
                </div>
                <div class="stat-chip stat-occupied">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="stat-n">{{ stats.occupied }}</span>
                    <span class="stat-l">Phục vụ</span>
                </div>
                <div class="stat-chip stat-reserved">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="stat-n">{{ stats.reserved }}</span>
                    <span class="stat-l">Đặt trước</span>
                </div>
                <div class="stat-chip stat-waiting">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span class="stat-n">{{ stats.waiting_payment }}</span>
                    <span class="stat-l">Chờ thanh toán</span>
                </div>
                <div class="stat-chip stat-cleaning">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                    <span class="stat-n">{{ stats.cleaning }}</span>
                    <span class="stat-l">Dọn bàn</span>
                </div>
            </div>
        </div>

        <!-- ===== Loading / Error / Empty ===== -->
        <div v-if="loading && tables.length === 0" class="wt-state">
            <div class="wt-spinner"></div>
            <p>Đang tải sơ đồ bàn…</p>
        </div>
        <div v-else-if="error && tables.length === 0" class="wt-state wt-error">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <p>{{ error }}</p>
            <button class="btn-action btn-primary-sm" @click="refresh">Thử lại</button>
        </div>
        <div v-else-if="tables.length === 0 && !loading" class="wt-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
            <p>Chưa có bàn nào trong hệ thống.</p>
        </div>

        <!-- ===== Error banner (has tables, refresh failed) ===== -->
        <div v-if="error && tables.length" class="wt-error-banner" role="alert">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Chưa cập nhật được. {{ error }}
            <button class="link-btn" @click="refresh">Thử lại</button>
        </div>

        <!-- ===== Floor sections ===== -->
        <div v-if="tables.length" class="wt-floors">
            <section
                v-for="floor in floors"
                :key="floor.location"
                class="floor"
                :aria-label="'Khu vực ' + floor.location"
            >
                <!-- Floor header -->
                <div class="floor-hd">
                    <div class="floor-id-badge">{{ floor.location }}</div>
                    <div class="floor-meta">
                        <span>{{ floor.tables.length }} bàn</span>
                        <span class="sep">·</span>
                        <span class="avail-count">{{ countByStatus(floor.tables, 'AVAILABLE') }} trống</span>
                    </div>
                </div>

                <!-- Table grid -->
                <div class="t-grid">
                    <button
                        v-for="t in floor.tables"
                        :key="t.id"
                        type="button"
                        class="t-card"
                        :class="'t-' + t.status.toLowerCase().replace('_', '-')"
                        :aria-label="`Bàn ${t.code}, ${t.capacity} chỗ, ${TABLE_STATUS_LABELS[t.status].label}`"
                        @click="onTableClick(t)"
                    >
                        <!-- Status strip + label -->
                        <div class="t-status-row">
                            <div class="t-status-icon" v-html="TABLE_STATUS_ICONS[t.status]"></div>
                            <span class="t-status-lbl">{{ TABLE_STATUS_LABELS[t.status].label }}</span>
                        </div>

                        <!-- Table code (primary) -->
                        <div class="t-code">{{ t.code }}</div>

                        <!-- Capacity -->
                        <div class="t-cap">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            {{ t.capacity }}
                        </div>

                        <!-- Active session info -->
                        <div v-if="activeSessionFor(t.id)" class="t-session">
                            <div class="t-session-row">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                {{ activeSessionFor(t.id)!.guest_count }} khách
                            </div>
                            <div class="t-session-elapsed">
                                {{ sessionAge(activeSessionFor(t.id)!) }}
                            </div>
                            <div v-if="activeSessionFor(t.id)!.subtotal > 0" class="t-session-total">
                                {{ formatVND(activeSessionFor(t.id)!.subtotal) }}
                            </div>
                        </div>

                        <!-- Reservation note -->
                        <div v-if="t.status === 'RESERVED'" class="t-note">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            {{ t.reserved_time ?? '' }}
                        </div>
                    </button>
                </div>
            </section>
        </div>
    </div>

    <!-- ===== Modal: Mở bàn ===== -->
    <Teleport to="body">
        <div v-if="openDialogTable" class="wt-modal-mask" @click.self="openDialogTable = null" role="dialog" aria-modal="true" :aria-label="`Mở bàn ${openDialogTable.code}`">
            <div class="wt-modal">
                <div class="wt-modal-hd">
                    <div class="wt-modal-title">
                        <div class="wt-modal-status-dot dot-available"></div>
                        Mở bàn {{ openDialogTable.code }}
                    </div>
                    <button class="wt-modal-close" aria-label="Đóng" @click="openDialogTable = null">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="wt-modal-bd">
                    <!-- Table info summary -->
                    <div class="wt-table-info">
                        <div class="wt-table-info-name">{{ openDialogTable.name }}</div>
                        <div class="wt-table-info-meta">{{ openDialogTable.location }} · Tối đa {{ openDialogTable.capacity }} người</div>
                    </div>

                    <!-- Guest count -->
                    <div class="wt-form-group">
                        <label class="wt-label" :for="'gc-' + openDialogTable.id">Số lượng khách</label>
                        <div class="wt-qty">
                            <button class="wt-qty-btn" @click="newSession.guest_count = Math.max(1, newSession.guest_count - 1)">−</button>
                            <input
                                :id="'gc-' + openDialogTable.id"
                                v-model.number="newSession.guest_count"
                                type="number"
                                min="1"
                                :max="openDialogTable.capacity"
                                class="wt-qty-val"
                            />
                            <button class="wt-qty-btn" @click="newSession.guest_count = Math.min(openDialogTable.capacity, newSession.guest_count + 1)">+</button>
                        </div>
                        <p class="wt-hint">Sức chứa tối đa: {{ openDialogTable.capacity }} người</p>
                    </div>

                    <!-- Customer name -->
                    <div class="wt-form-group">
                        <label class="wt-label" for="cust-name">Tên khách (tùy chọn)</label>
                        <input id="cust-name" v-model="newSession.customer_name" type="text" class="wt-input" placeholder="Ví dụ: Anh Tuấn, Cô Hương…" />
                    </div>
                </div>

                <div class="wt-modal-ft">
                    <button class="btn-ghost-sm" @click="openDialogTable = null">Hủy</button>
                    <button class="btn-primary-sm" :disabled="opening" @click="confirmOpen">
                        <svg v-if="opening" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        {{ opening ? 'Đang mở…' : 'Mở bàn & chọn món' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ===== Modal: Bàn đang phục vụ — chọn hành động ===== -->
    <Teleport to="body">
        <div v-if="actionTable" class="wt-modal-mask" @click.self="actionTable = null" role="dialog" aria-modal="true" :aria-label="`Bàn ${actionTable.code}`">
            <div class="wt-modal">
                <div class="wt-modal-hd">
                    <div class="wt-modal-title">
                        <div class="wt-modal-status-dot" :class="'dot-' + actionTable.status.toLowerCase().replace('_', '-')"></div>
                        <span>{{ actionTable.code }} — {{ actionTable.name }}</span>
                        <span class="wt-modal-status-lbl">{{ TABLE_STATUS_LABELS[actionTable.status].label }}</span>
                    </div>
                    <button class="wt-modal-close" aria-label="Đóng" @click="actionTable = null">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="wt-modal-bd">
                    <!-- Session quick summary -->
                    <div v-if="activeSessionFor(actionTable.id)" class="wt-session-card">
                        <div class="wt-session-item">
                            <span class="wt-session-key">Khách</span>
                            <span class="wt-session-val">{{ activeSessionFor(actionTable.id)!.customer_name ?? 'Khách lẻ' }}</span>
                        </div>
                        <div class="wt-session-item">
                            <span class="wt-session-key">Số khách</span>
                            <span class="wt-session-val">{{ activeSessionFor(actionTable.id)!.guest_count }}</span>
                        </div>
                        <div class="wt-session-item">
                            <span class="wt-session-key">Mở lúc</span>
                            <span class="wt-session-val">{{ formatTime(activeSessionFor(actionTable.id)!.opened_at) }}</span>
                        </div>
                        <div class="wt-session-item">
                            <span class="wt-session-key">Đã ngồi</span>
                            <span class="wt-session-val wt-session-highlight">{{ sessionAge(activeSessionFor(actionTable.id)!) }}</span>
                        </div>
                        <div class="wt-session-item wt-session-total">
                            <span class="wt-session-key">Tạm tính</span>
                            <span class="wt-session-val">{{ formatVND(activeSessionFor(actionTable.id)!.subtotal) }}</span>
                        </div>
                    </div>

                    <!-- Action list -->
                    <div class="wt-actions">
                        <button class="wt-action-card wt-action-primary" @click="goToOrder(actionTable)">
                            <div class="wt-action-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            </div>
                            <div class="wt-action-body">
                                <span class="wt-action-title">Vào đơn / Thêm món</span>
                                <span class="wt-action-desc">Chọn món cho bàn này</span>
                            </div>
                            <svg class="wt-action-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>

                        <button v-if="activeSessionFor(actionTable.id)" class="wt-action-card" @click="openMoveDialog(actionTable)">
                            <div class="wt-action-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </div>
                            <div class="wt-action-body">
                                <span class="wt-action-title">Đổi bàn</span>
                                <span class="wt-action-desc">Chuyển khách sang bàn trống khác</span>
                            </div>
                            <svg class="wt-action-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>

                        <button v-if="activeSessionFor(actionTable.id)" class="wt-action-card" :disabled="requestingInvoice" @click="requestInvoice(actionTable)">
                            <div class="wt-action-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            </div>
                            <div class="wt-action-body">
                                <span class="wt-action-title">{{ requestingInvoice ? 'Đang gửi…' : 'Yêu cầu xuất hóa đơn' }}</span>
                                <span class="wt-action-desc">Ghi nhận khách cần hóa đơn</span>
                            </div>
                            <svg class="wt-action-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                </div>

                <div class="wt-modal-ft wt-modal-ft-right">
                    <button class="btn-ghost-sm" @click="actionTable = null">Đóng</button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ===== Modal: Đổi bàn ===== -->
    <Teleport to="body">
        <div v-if="moveDialog" class="wt-modal-mask" @click.self="!moving && (moveDialog = null)" role="dialog" aria-modal="true" :aria-label="`Đổi bàn từ ${moveDialog.from.code}`">
            <div class="wt-modal">
                <div class="wt-modal-hd">
                    <div class="wt-modal-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Đổi bàn: {{ moveDialog.from.code }} → ?
                    </div>
                    <button class="wt-modal-close" aria-label="Đóng" :disabled="moving" @click="moveDialog = null">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="wt-modal-bd">
                    <p class="wt-modal-sub">Chọn bàn trống có sức chứa ≥ {{ activeSessionFor(moveDialog.from.id)?.guest_count ?? 1 }} người.</p>
                    <div v-if="moveCandidates.length" class="wt-move-grid">
                        <button
                            v-for="t in moveCandidates"
                            :key="t.id"
                            class="wt-move-card"
                            :disabled="moving"
                            @click="confirmMove(t)"
                        >
                            <div class="wt-move-code">{{ t.code }}</div>
                            <div class="wt-move-name">{{ t.name }}</div>
                            <div class="wt-move-cap">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                {{ t.capacity }} chỗ
                            </div>
                        </button>
                    </div>
                    <div v-else class="wt-empty-move">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <p>Không còn bàn trống phù hợp.</p>
                    </div>
                </div>

                <div class="wt-modal-ft">
                    <button class="btn-ghost-sm" :disabled="moving" @click="moveDialog = null">Hủy</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useTablesStore } from '@/stores/staffTables'
import { useStaffOrdersStore } from '@/stores/staffOrders'
import { useToast, formatVND, formatTime } from '@/composables/useStaffUtils'
import { TABLE_STATUS_LABELS, type RestaurantTable, type TableSession } from '@/types/staff'
import { staffOrders, type Order } from '@/api/staff'

// ===== Status icons (inline SVG strings) =====
const TABLE_STATUS_ICONS: Record<string, string> = {
    AVAILABLE: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>`,
    OCCUPIED: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    RESERVED: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
    WAITING_PAYMENT: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
    CLEANING: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>`,
}

const router = useRouter()
const tablesStore = useTablesStore()
const ordersStore = useStaffOrdersStore()
const { tables, sessions, stats, loading, error } = storeToRefs(tablesStore)
const toast = useToast()

const openDialogTable = ref<RestaurantTable | null>(null)
const actionTable = ref<RestaurantTable | null>(null)
const moveDialog = ref<{ from: RestaurantTable } | null>(null)
const opening = ref(false)
const moving = ref(false)
const paying = ref(false)
const requestingInvoice = ref(false)

const newSession = reactive<{ guest_count: number; customer_name: string; note: string }>({
    guest_count: 2,
    customer_name: '',
    note: '',
})

// Live clock
const currentTime = ref('')
let clockTimer: number | undefined
function updateClock() {
    const d = new Date()
    currentTime.value = d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

let elapsedTimer: number | undefined

onMounted(() => {
    void tablesStore.fetchAll()
    updateClock()
    clockTimer = window.setInterval(updateClock, 1000)

    // Update elapsed times every minute
    elapsedTimer = window.setInterval(() => { /* reactivity handles re-render */ }, 60_000)
})

onUnmounted(() => {

    if (clockTimer) clearInterval(clockTimer)
    if (elapsedTimer) clearInterval(elapsedTimer)
})

async function refresh() {
    await tablesStore.fetchAll()
    if (!error.value) toast.success('Đã làm mới sơ đồ bàn')
}

// ===== Floor grouping =====
interface FloorView {
    location: string
    tables: RestaurantTable[]
}

const FLOOR_META: Record<string, { order: number }> = {
    'Tầng 1': { order: 1 },
    'Tầng 2': { order: 2 },
    'Tầng 3': { order: 3 },
    'Sân vườn': { order: 4 },
}

const floors = computed<FloorView[]>(() => {
    const byLocation = new Map<string, RestaurantTable[]>()
    for (const t of tables.value) {
        const loc = t.location || 'Khác'
        const list = byLocation.get(loc) ?? []
        list.push(t)
        byLocation.set(loc, list)
    }
    const result: FloorView[] = []
    for (const [location, list] of byLocation) {
        result.push({
            location,
            tables: [...list].sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true })),
        })
    }
    return result.sort(
        (a, b) => (FLOOR_META[a.location]?.order ?? 99) - (FLOOR_META[b.location]?.order ?? 99)
    )
})

function countByStatus(list: RestaurantTable[], status: string): number {
    return list.filter((t) => t.status === status).length
}

// ===== Session helpers =====
function activeSessionFor(tableId: number): TableSession | undefined {
    return sessions.value.find((s) => s.table_id === tableId && s.status === 'OPEN')
}

function sessionAge(session: TableSession): string {
    if (!session.opened_at) return ''
    const ms = Date.now() - new Date(session.opened_at).getTime()
    const min = Math.floor(ms / 60_000)
    const hr = Math.floor(min / 60)
    return hr > 0 ? `${hr}h${String(min % 60).padStart(2, '0')}p` : `${min}p`
}

// ===== Table click =====
function onTableClick(table: RestaurantTable): void {
    if (table.status === 'AVAILABLE') {
        openOpenDialog(table)
    } else {
        actionTable.value = table
    }
}

function openOpenDialog(table: RestaurantTable): void {
    openDialogTable.value = table
    newSession.guest_count = Math.min(2, table.capacity)
    newSession.customer_name = ''
    newSession.note = ''
}

async function confirmOpen(): Promise<void> {
    if (!openDialogTable.value) return
    opening.value = true
    try {
        const session = await tablesStore.openSession({
            table_id: openDialogTable.value.id,
            guest_count: newSession.guest_count,
            customer_name: newSession.customer_name || null,
        })
        toast.success(`Đã mở bàn ${openDialogTable.value.code}`)
        const t = openDialogTable.value
        openDialogTable.value = null

        try {
            const order = await ordersStore.createFromSession(session.id)
            if (order) {
                router.push({
                    name: 'waiter-order-detail',
                    params: { id: String(order.id) },
                    query: { table_id: String(t.id), session_id: String(session.id) },
                })
            }
        } catch {
            router.push({ name: 'waiter-orders' })
        }
    } catch (err: unknown) {
        const e = err as { message?: string; response?: { data?: { message?: string } } }
        toast.error(e?.response?.data?.message || e?.message || 'Lỗi mở bàn')
    } finally {
        opening.value = false
    }
}

async function goToOrder(table: RestaurantTable): Promise<void> {
    const session = activeSessionFor(table.id)
    if (!session) {
        toast.warning('Bàn này chưa có phiên phục vụ.')
        return
    }
    try {
        let order: Order | null = null
        if (session.order_id) {
            order = await ordersStore.fetchDetail(session.order_id)
        } else {
            order = await ordersStore.createFromSession(session.id)
        }
        if (!order) return
        router.push({
            name: 'waiter-order-detail',
            params: { id: String(order.id) },
            query: { table_id: String(table.id), session_id: String(session.id) },
        })
    } catch (err: unknown) {
        const e = err as { message?: string }
        toast.error(e?.message || 'Lỗi mở đơn')
    }
    actionTable.value = null
}

async function requestInvoice(table: RestaurantTable): Promise<void> {
    const session = activeSessionFor(table.id)
    if (!session?.order_id || requestingInvoice.value) return
    requestingInvoice.value = true
    try {
        await staffOrders.requestInvoice(session.order_id)
        toast.success(`Đã ghi nhận yêu cầu xuất hóa đơn cho bàn ${table.code}.`)
        actionTable.value = null
    } catch (err: unknown) {
        const e = err as { message?: string; response?: { data?: { message?: string } } }
        toast.error(e?.response?.data?.message || e?.message || 'Lỗi yêu cầu xuất hóa đơn')
    } finally {
        requestingInvoice.value = false
    }
}

// ===== Đổi bàn =====
function openMoveDialog(table: RestaurantTable): void {
    moveDialog.value = { from: table }
    actionTable.value = null
}

const moveCandidates = computed(() => {
    if (!moveDialog.value) return []
    const sess = activeSessionFor(moveDialog.value.from.id)
    const need = sess?.guest_count ?? 1
    return tables.value
        .filter((t) => t.id !== moveDialog.value!.from.id)
        .filter((t) => t.status === 'AVAILABLE' && !activeSessionFor(t.id))
        .filter((t) => t.capacity >= need)
        .sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true }))
})

async function confirmMove(target: RestaurantTable): Promise<void> {
    if (!moveDialog.value || moving.value) return
    const from = moveDialog.value.from
    const session = activeSessionFor(from.id)
    if (!session) {
        toast.error('Không tìm thấy phiên phục vụ.')
        return
    }
    moving.value = true
    try {
        await tablesStore.moveSession(session.id, target.id)
        await ordersStore.fetchAll()
        toast.success(`Đã chuyển khách từ ${from.code} sang ${target.code}`)
        moveDialog.value = null
    } catch (err: unknown) {
        const e = err as { message?: string; response?: { data?: { message?: string } } }
        toast.error(e?.response?.data?.message || e?.message || 'Lỗi đổi bàn')
    } finally {
        moving.value = false
    }
}
useRealtimeRefresh(() => tablesStore.fetchAll(true), () => !loading.value && !opening.value && !moving.value && !paying.value && !requestingInvoice.value)
</script>

<style scoped>
/* ===== View shell ===== */
.wt-view {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px;
    max-width: 1280px;
    margin: 0 auto;
}

/* ===== Header ===== */
.wt-header {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.wt-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.wt-title {
    font-size: 22px;
    font-weight: 800;
    color: var(--color-charcoal-black, #111111);
    margin: 0;
}

.wt-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.wt-clock {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-500, #6b7280);
    font-variant-numeric: tabular-nums;
}

.btn-refresh {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 1.5px solid var(--color-gray-200, #e5e7eb);
    border-radius: var(--radius-md, 8px);
    background: #fff;
    color: var(--color-gray-700, #374151);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--duration-fast, 150ms);
    font-family: inherit;
}
.btn-refresh:hover:not(:disabled) {
    border-color: var(--color-chili-red, #D71920);
    color: var(--color-chili-red, #D71920);
}
.btn-refresh:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== Stats bar ===== */
.wt-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.stat-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: var(--radius-md, 8px);
    font-size: 13px;
    font-weight: 500;
    border: 1.5px solid transparent;
}

.stat-n {
    font-size: 16px;
    font-weight: 800;
    line-height: 1;
}

.stat-l {
    color: inherit;
    opacity: 0.8;
}

.stat-available {
    background: var(--color-success-bg, #DCFCE7);
    color: #166534;
    border-color: #BBF7D0;
}
.stat-available .stat-n { color: #166534; }

.stat-occupied {
    background: var(--color-error-bg, #FEE2E2);
    color: #991B1B;
    border-color: #FECACA;
}
.stat-occupied .stat-n { color: #991B1B; }

.stat-reserved {
    background: var(--color-warning-bg, #FEF3C7);
    color: #92400E;
    border-color: #FDE68A;
}
.stat-reserved .stat-n { color: #92400E; }

.stat-waiting {
    background: #FFF7ED;
    color: #9A3412;
    border-color: #FED7AA;
}
.stat-waiting .stat-n { color: #9A3412; }

.stat-cleaning {
    background: var(--color-info-bg, #DBEAFE);
    color: #1E40AF;
    border-color: #BFDBFE;
}
.stat-cleaning .stat-n { color: #1E40AF; }

/* ===== Floor sections ===== */
.wt-floors {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.floor {}

.floor-hd {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}

.floor-id-badge {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--color-chili-red, #D71920);
    background: #FFF0F0;
    padding: 4px 10px;
    border-radius: var(--radius-full, 9999px);
    border: 1px solid #FFD0D0;
}

.floor-meta {
    font-size: 12px;
    color: var(--color-gray-500, #6b7280);
    display: flex;
    align-items: center;
    gap: 4px;
}

.sep { opacity: 0.5; }

.avail-count {
    color: #166534;
    font-weight: 600;
}

/* ===== Table grid ===== */
.t-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
}

/* ===== Table card ===== */
.t-card {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
    padding: 0;
    background: #fff;
    border-radius: var(--radius-lg, 12px);
    border: 1.5px solid var(--color-gray-200, #e5e7eb);
    cursor: pointer;
    font-family: inherit;
    text-align: left;
    transition: border-color var(--duration-fast, 150ms), box-shadow var(--duration-fast, 150ms), transform var(--duration-fast, 150ms);
    position: relative;
    overflow: hidden;
    min-height: 120px;
}

.t-card:hover {
    border-color: var(--color-gray-400, #9ca3af);
    box-shadow: var(--shadow-md, 0 4px 6px -1px rgba(0,0,0,0.1));
    transform: translateY(-1px);
}

.t-card:focus-visible {
    outline: 3px solid var(--color-chili-red, #D71920);
    outline-offset: 2px;
}

/* Status color bar on left */
.t-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
}

.t-available::before { background: var(--color-success, #22C55E); }
.t-occupied::before  { background: var(--color-error, #EF4444); }
.t-reserved::before  { background: var(--color-warning, #F59E0B); }
.t-waiting-payment::before { background: #F97316; }
.t-cleaning::before { background: var(--color-info, #3B82F6); }

/* Status row */
.t-status-row {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px 10px 4px 14px;
}

.t-status-icon {
    display: flex;
    align-items: center;
    flex-shrink: 0;
}

.t-available .t-status-icon { color: var(--color-success, #22C55E); }
.t-occupied .t-status-icon  { color: var(--color-error, #EF4444); }
.t-reserved .t-status-icon  { color: var(--color-warning, #F59E0B); }
.t-waiting-payment .t-status-icon { color: #F97316; }
.t-cleaning .t-status-icon   { color: var(--color-info, #3B82F6); }

.t-status-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.t-available .t-status-lbl  { color: var(--color-success, #22C55E); }
.t-occupied .t-status-lbl   { color: var(--color-error, #EF4444); }
.t-reserved .t-status-lbl   { color: var(--color-warning, #F59E0B); }
.t-waiting-payment .t-status-lbl { color: #F97316; }
.t-cleaning .t-status-lbl   { color: var(--color-info, #3B82F6); }

/* Table code */
.t-code {
    font-size: 22px;
    font-weight: 900;
    color: var(--color-charcoal-black, #111111);
    padding: 2px 10px 4px 14px;
    line-height: 1.1;
    letter-spacing: -0.5px;
}

/* Capacity */
.t-cap {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 10px 8px 14px;
    font-size: 11px;
    color: var(--color-gray-500, #6b7280);
    font-weight: 500;
}

/* Session info */
.t-session {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 8px 10px 10px 14px;
    border-top: 1px solid var(--color-gray-100, #f3f4f6);
    background: var(--color-gray-50, #f9fafb);
}

.t-session-row {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--color-gray-600, #4b5563);
    font-weight: 500;
}

.t-session-elapsed {
    font-size: 13px;
    font-weight: 800;
    color: var(--color-chili-red, #D71920);
}

.t-session-total {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111111);
}

/* Reservation time */
.t-note {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px 8px 14px;
    font-size: 11px;
    font-weight: 600;
    color: var(--color-warning, #F59E0B);
    border-top: 1px solid var(--color-gray-100, #f3f4f6);
}

/* ===== State views ===== */
.wt-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 60px 24px;
    text-align: center;
    background: #fff;
    border-radius: var(--radius-lg, 12px);
    border: 1px solid var(--color-gray-200, #e5e7eb);
    color: var(--color-gray-500, #6b7280);
    font-size: 14px;
}

.wt-state p { margin: 0; }

.wt-error {
    color: var(--color-error, #EF4444);
}

.wt-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--color-gray-200, #e5e7eb);
    border-top-color: var(--color-chili-red, #D71920);
    border-radius: 50%;
    animation: wt-spin 0.7s linear infinite;
}

@keyframes wt-spin {
    to { transform: rotate(360deg); }
}

.wt-error-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #FFF7ED;
    border: 1px solid #FED7AA;
    border-radius: var(--radius-md, 8px);
    font-size: 13px;
    color: #9A3412;
}

/* ===== Buttons ===== */
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-md, 8px);
    font-weight: 600;
    cursor: pointer;
    border: none;
    font-family: inherit;
    font-size: 13px;
    transition: all var(--duration-fast, 150ms);
}

.btn-primary-sm {
    background: var(--color-chili-red, #D71920);
    color: #fff;
}
.btn-primary-sm:hover:not(:disabled) {
    background: var(--color-dark-red, #A80F17);
}
.btn-primary-sm:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-ghost-sm {
    background: transparent;
    color: var(--color-gray-600, #4b5563);
    padding: 8px 14px;
    border-radius: var(--radius-md, 8px);
    font-weight: 600;
    cursor: pointer;
    border: 1.5px solid var(--color-gray-200, #e5e7eb);
    font-family: inherit;
    font-size: 13px;
    transition: all var(--duration-fast, 150ms);
}
.btn-ghost-sm:hover:not(:disabled) {
    background: var(--color-gray-50, #f9fafb);
    border-color: var(--color-gray-300, #d1d5db);
}
.btn-ghost-sm:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.link-btn {
    background: none;
    border: none;
    color: inherit;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
    font-family: inherit;
    font-size: inherit;
    padding: 0;
}

/* ===== Modal ===== */
.wt-modal-mask {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(2px);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.wt-modal {
    background: #fff;
    border-radius: var(--radius-xl, 16px);
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-xl, 0 20px 25px -5px rgba(0,0,0,0.1));
    display: flex;
    flex-direction: column;
}

.wt-modal-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
    flex-shrink: 0;
}

.wt-modal-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111111);
}

.wt-modal-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.dot-available { background: var(--color-success, #22C55E); }
.dot-occupied  { background: var(--color-error, #EF4444); }
.dot-reserved  { background: var(--color-warning, #F59E0B); }
.dot-waiting-payment { background: #F97316; }
.dot-cleaning  { background: var(--color-info, #3B82F6); }

.wt-modal-status-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-gray-500, #6b7280);
    background: var(--color-gray-100, #f3f4f6);
    padding: 3px 8px;
    border-radius: var(--radius-full, 9999px);
}

.wt-modal-close {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-md, 8px);
    border: none;
    background: transparent;
    color: var(--color-gray-400, #9ca3af);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--duration-fast, 150ms);
    flex-shrink: 0;
}
.wt-modal-close:hover {
    background: var(--color-gray-100, #f3f4f6);
    color: var(--color-charcoal-black, #111111);
}

.wt-modal-bd {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    flex: 1;
}

.wt-modal-ft {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid var(--color-gray-200, #e5e7eb);
    background: var(--color-gray-50, #f9fafb);
    flex-shrink: 0;
}

.wt-modal-ft-right {
    justify-content: flex-end;
}

.wt-modal-sub {
    font-size: 13px;
    color: var(--color-gray-500, #6b7280);
    margin: 0;
}

/* Table info in modal */
.wt-table-info {
    background: var(--color-gray-50, #f9fafb);
    border-radius: var(--radius-md, 8px);
    padding: 12px 14px;
    border: 1px solid var(--color-gray-200, #e5e7eb);
}

.wt-table-info-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111111);
}

.wt-table-info-meta {
    font-size: 12px;
    color: var(--color-gray-500, #6b7280);
    margin-top: 2px;
}

/* Session summary in modal */
.wt-session-card {
    background: var(--color-gray-50, #f9fafb);
    border-radius: var(--radius-md, 8px);
    padding: 12px 14px;
    border: 1px solid var(--color-gray-200, #e5e7eb);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.wt-session-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}

.wt-session-key {
    color: var(--color-gray-500, #6b7280);
}

.wt-session-val {
    font-weight: 600;
    color: var(--color-charcoal-black, #111111);
}

.wt-session-highlight {
    color: var(--color-chili-red, #D71920);
}

.wt-session-total {
    padding-top: 8px;
    border-top: 1px solid var(--color-gray-200, #e5e7eb);
}
.wt-session-total .wt-session-key {
    font-weight: 600;
    color: var(--color-charcoal-black, #111111);
}
.wt-session-total .wt-session-val {
    font-size: 16px;
    font-weight: 800;
    color: var(--color-chili-red, #D71920);
}

/* Form controls in modal */
.wt-form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.wt-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-700, #374151);
}

.wt-input {
    width: 100%;
    height: 44px;
    padding: 0 14px;
    border: 1.5px solid var(--color-gray-300, #d1d5db);
    border-radius: var(--radius-md, 8px);
    font-size: 14px;
    font-family: inherit;
    background: #fff;
    color: var(--color-charcoal-black, #111111);
    transition: border-color var(--duration-fast, 150ms);
    box-sizing: border-box;
}
.wt-input:focus {
    outline: none;
    border-color: var(--color-chili-red, #D71920);
}

.wt-qty {
    display: inline-flex;
    align-items: center;
    background: var(--color-gray-100, #f3f4f6);
    border-radius: var(--radius-md, 8px);
    overflow: hidden;
}

.wt-qty-btn {
    width: 44px;
    height: 44px;
    border: none;
    background: #fff;
    cursor: pointer;
    font-size: 18px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111111);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color var(--duration-fast, 150ms);
}
.wt-qty-btn:hover {
    color: var(--color-chili-red, #D71920);
}

.wt-qty-val {
    width: 52px;
    height: 44px;
    text-align: center;
    border: none;
    background: transparent;
    font-size: 16px;
    font-weight: 700;
    font-family: inherit;
    color: var(--color-charcoal-black, #111111);
}
.wt-qty-val:focus { outline: none; }

.wt-hint {
    font-size: 11px;
    color: var(--color-gray-400, #9ca3af);
    margin: 0;
}

/* Action cards in modal */
.wt-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.wt-action-card {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1.5px solid var(--color-gray-200, #e5e7eb);
    border-radius: var(--radius-md, 8px);
    background: #fff;
    cursor: pointer;
    font-family: inherit;
    text-align: left;
    transition: all var(--duration-fast, 150ms);
}
.wt-action-card:hover:not(:disabled) {
    border-color: var(--color-gray-300, #d1d5db);
    background: var(--color-gray-50, #f9fafb);
}
.wt-action-card:focus-visible {
    outline: 3px solid var(--color-chili-red, #D71920);
    outline-offset: 2px;
}
.wt-action-card:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.wt-action-primary {
    background: rgba(215, 25, 32, 0.06);
    border-color: var(--color-chili-red);
}
.wt-action-primary:hover:not(:disabled) {
    background: rgba(215, 25, 32, 0.12);
    border-color: var(--color-dark-red);
}

.wt-action-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md, 8px);
    background: #fff;
    border: 1px solid var(--color-gray-200, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--color-gray-700, #374151);
}
.wt-action-primary .wt-action-icon {
    background: var(--color-chili-red, #D71920);
    border-color: var(--color-chili-red, #D71920);
    color: #fff;
}

.wt-action-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.wt-action-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111111);
}
.wt-action-desc {
    font-size: 12px;
    color: var(--color-gray-500, #6b7280);
}

.wt-action-arrow {
    color: var(--color-gray-400, #9ca3af);
    flex-shrink: 0;
}

/* Move table grid */
.wt-move-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 8px;
}

.wt-move-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 14px 10px;
    background: var(--color-gray-50, #f9fafb);
    border: 1.5px solid var(--color-gray-200, #e5e7eb);
    border-radius: var(--radius-md, 8px);
    cursor: pointer;
    font-family: inherit;
    transition: all var(--duration-fast, 150ms);
}
.wt-move-card:hover:not(:disabled) {
    background: rgba(215, 25, 32, 0.04);
    border-color: var(--color-chili-red);
    transform: translateY(-1px);
}
.wt-move-card:focus-visible {
    outline: 3px solid var(--color-chili-red, #D71920);
    outline-offset: 2px;
}
.wt-move-card:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.wt-move-code {
    font-size: 16px;
    font-weight: 900;
    color: var(--color-charcoal-black, #111111);
}
.wt-move-name {
    font-size: 11px;
    color: var(--color-gray-500, #6b7280);
}
.wt-move-cap {
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    font-weight: 500;
    color: var(--color-gray-600, #4b5563);
}

.wt-empty-move {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 32px 16px;
    color: var(--color-gray-400, #9ca3af);
    font-size: 13px;
    text-align: center;
}
.wt-empty-move p { margin: 0; }

/* Spinner in button */
.spin {
    animation: wt-spin 0.7s linear infinite;
}

/* ===== Responsive ===== */
@media (max-width: 768px) {
    .wt-view { padding: 12px; gap: 12px; }
    .t-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
    .t-code { font-size: 20px; }
    .wt-stats { gap: 6px; }
    .stat-chip { padding: 6px 10px; }
    .stat-n { font-size: 15px; }
    .wt-modal { max-width: 100%; margin: 0 8px; border-radius: var(--radius-xl, 16px) var(--radius-xl, 16px) 0 0; }
    .wt-modal-mask { align-items: flex-end; padding: 0; }
}

@media (max-width: 480px) {
    .t-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
    .t-code { font-size: 18px; }
    .t-session-elapsed { font-size: 12px; }
}
</style>
