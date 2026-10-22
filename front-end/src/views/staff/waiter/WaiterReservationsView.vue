<template>
    <div class="waiter-reservations">
        <div class="toolbar">
            <input v-model="searchQuery" type="text" class="search-input" placeholder="🔍 Tìm theo tên, SĐT..." />
            <button class="btn-primary" @click="showCreateDialog = true">+ Tạo đặt bàn mới</button>
        </div>

        <div class="reservation-list">
            <div v-for="r in filteredReservations" :key="r.id" class="reservation-card" :class="`status-${r.status}`">
                <div class="card-main">
                    <div class="card-header">
                        <div class="customer-info">
                            <div class="customer-name">{{ r.customer_name }}</div>
                            <div class="customer-phone">📱 {{ r.customer_phone }}</div>
                        </div>
                        <span class="status-badge" :class="`badge-${r.status}`">{{ statusLabel(r.status) }}</span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Ngày giờ</span>
                            <span class="info-value">{{ formatDate(r.reserved_date) }} lúc {{ r.reserved_time }}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Số khách</span>
                            <span class="info-value">{{ r.guest_count }} người</span>
                        </div>
                        <div class="info-row" v-if="r.table_code">
                            <span class="info-label">Bàn</span>
                            <span class="info-value">Bàn {{ r.table_code }}</span>
                        </div>
                        <div v-if="r.note" class="note-row">📝 {{ r.note }}</div>
                    </div>
                </div>
                <div class="card-actions">
                    <button
                        v-if="r.status === 'PENDING'"
                        class="btn btn-confirm"
                        @click="updateStatus(r.id, 'CONFIRMED')"
                    >
                        Xác nhận
                    </button>
                    <button
                        v-if="r.status === 'CONFIRMED'"
                        class="btn btn-arrived"
                        @click="updateStatus(r.id, 'ARRIVED')"
                    >
                        Khách đã đến
                    </button>
                    <button
                        v-if="['PENDING', 'CONFIRMED'].includes(r.status)"
                        class="btn btn-cancel"
                        @click="updateStatus(r.id, 'CANCELLED')"
                    >
                        Hủy
                    </button>
                </div>
            </div>
        </div>

        <div v-if="filteredReservations.length === 0" class="empty-state">
            <div class="empty-icon">📅</div>
            <div>Không có đặt bàn nào</div>
        </div>

        <!-- Create dialog -->
        <Teleport to="body">
            <div v-if="showCreateDialog" class="modal-backdrop" @click.self="showCreateDialog = false">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <h3>Tạo đặt bàn mới</h3>
                        <button class="modal-close" @click="showCreateDialog = false">✕</button>
                    </div>
                    <div class="modal-body">
                        <ReservationForm :tables="availableTables" @created="reservationsStore.fetchAll()" />
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref } from 'vue'
import ReservationForm from '@/components/ReservationForm.vue'
import { storeToRefs } from 'pinia'
import { useReservationsStore } from '@/stores/staffReservations'
import { useTablesStore } from '@/stores/staffTables'
import { useToast, formatDate } from '@/composables/useStaffUtils'
import type { Reservation } from '@/types/staff'

const reservationsStore = useReservationsStore()
const tablesStore = useTablesStore()
const toast = useToast()

const { reservations } = storeToRefs(reservationsStore)
const { tables } = storeToRefs(tablesStore)

const searchQuery = ref('')
const showCreateDialog = ref(false)
onMounted(async () => {
    await reservationsStore.fetchAll()
    await tablesStore.fetchAll()
})

const availableTables = computed(() => tables.value.filter(t => t.is_active))

const filteredReservations = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()
    return reservations.value
        .filter(r => !q || r.customer_name.toLowerCase().includes(q) || r.customer_phone.includes(q))
        .sort((a, b) => {
            if (a.reserved_date !== b.reserved_date) return a.reserved_date.localeCompare(b.reserved_date)
            return a.reserved_time.localeCompare(b.reserved_time)
        })
})

function statusLabel(status: Reservation['status']): string {
    const map: Record<Reservation['status'], string> = {
        PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận', ARRIVED: 'Đã đến',
        CANCELLED: 'Đã hủy', COMPLETED: 'Hoàn tất',
    }
    return map[status]
}

async function updateStatus(id: number, status: Reservation['status']): Promise<void> {
    try {
        await reservationsStore.updateStatus(id, status)
        toast.success(`Đã cập nhật: ${statusLabel(status)}`)
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi cập nhật')
    }
}

useRealtimeRefresh(() => reservationsStore.fetchAll(undefined, true), () => !reservationsStore.loading)
</script>

<style scoped>
.waiter-reservations { display: flex; flex-direction: column; gap: 16px; }

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.search-input {
    flex: 1;
    padding: 9px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    min-width: 200px;
    font-family: inherit;
}

.btn-primary {
    background: var(--color-brand-action);
    color: #fff;
    border: none;
    padding: 9px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.btn-primary:hover { background: var(--color-brand-action-hover); }

.reservation-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 12px;
}

.reservation-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.reservation-card.status-CONFIRMED { border-left: 4px solid #16a34a; }
.reservation-card.status-PENDING   { border-left: 4px solid #eab308; }
.reservation-card.status-CANCELLED { opacity: 0.6; border-left: 4px solid #ef4444; }
.reservation-card.status-ARRIVED   { border-left: 4px solid #2563eb; }

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.customer-name {
    font-weight: 700;
    font-size: 15px;
}

.customer-phone {
    font-size: 12px;
    color: #6b7280;
    margin-top: 2px;
}

.status-badge {
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.badge-PENDING   { background: #fef3c7; color: #92400e; }
.badge-CONFIRMED { background: #d1fae5; color: #065f46; }
.badge-ARRIVED   { background: #dbeafe; color: #1e3a8a; }
.badge-CANCELLED { background: #fee2e2; color: #991b1b; }
.badge-COMPLETED { background: #f3f4f6; color: #374151; }

.info-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    padding: 4px 0;
}

.info-label { color: #6b7280; }
.info-value { font-weight: 600; color: #1f2937; }

.note-row {
    background: #fffbeb;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 12px;
    color: #92400e;
    margin-top: 6px;
}

.card-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding-top: 8px;
    border-top: 1px solid #f3f4f6;
}

.btn {
    padding: 7px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 12px;
}

.btn-confirm { background: #16a34a; color: #fff; }
.btn-arrived { background: #2563eb; color: #fff; }
.btn-cancel  { background: #fee2e2; color: #991b1b; }
.btn-secondary { background: #e5e7eb; color: #1f2937; }

.empty-state {
    text-align: center;
    padding: 60px;
    color: #9ca3af;
}

.empty-icon { font-size: 56px; margin-bottom: 12px; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-dialog {
    background: #fff;
    border-radius: 14px;
    max-width: 520px;
    width: 100%;
    overflow: hidden;
}

.modal-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 { margin: 0; font-size: 17px; font-weight: 700; }
.modal-close { background: transparent; border: none; font-size: 22px; color: #6b7280; cursor: pointer; }

.modal-body { padding: 20px; }
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.form-group { margin-bottom: 12px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}

.form-input {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
}

.btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
