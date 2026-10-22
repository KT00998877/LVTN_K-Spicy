<template>
    <div class="cashier-reservations">
        <div class="toolbar">
            <input v-model="searchQuery" type="text" class="search-input" placeholder="🔍 Tìm theo tên, SĐT, bàn..." />
            <div class="filter-tabs">
                <button
                    v-for="f in filters"
                    :key="f.value"
                    class="filter-tab"
                    :class="{ active: filterStatus === f.value }"
                    @click="filterStatus = f.value"
                >
                    {{ f.label }}
                </button>
            </div>
        </div>

        <div class="reservation-grid">
            <div v-for="r in filteredReservations" :key="r.id" class="reservation-card">
                <div class="card-top">
                    <div class="customer-block">
                        <div class="customer-avatar">{{ initials(r.customer_name) }}</div>
                        <div>
                            <div class="customer-name">{{ r.customer_name }}</div>
                            <div class="customer-phone">📱 {{ r.customer_phone }}</div>
                        </div>
                    </div>
                    <span class="status-badge" :class="`badge-${r.status}`">{{ statusLabel(r.status) }}</span>
                </div>

                <div class="card-time">
                    <div class="time-main">{{ formatDate(r.reserved_date) }} · {{ r.reserved_time }}</div>
                    <div class="time-meta">{{ r.guest_count }} khách · {{ r.table_code ? 'Bàn ' + r.table_code : 'Chưa gán bàn' }}</div>
                </div>

                <div v-if="r.note" class="card-note">📝 {{ r.note }}</div>

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
                        Đã đến
                    </button>
                    <button
                        v-if="r.status === 'ARRIVED'"
                        class="btn btn-done"
                        @click="updateStatus(r.id, 'COMPLETED')"
                    >
                        Hoàn tất
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
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useReservationsStore } from '@/stores/staffReservations'
import { useToast, formatDate } from '@/composables/useStaffUtils'
import type { Reservation } from '@/types/staff'

const reservationsStore = useReservationsStore()
const { reservations } = storeToRefs(reservationsStore)
const toast = useToast()

const searchQuery = ref('')
const filterStatus = ref<'all' | Reservation['status']>('all')

const filters: Array<{ value: 'all' | Reservation['status']; label: string }> = [
    { value: 'all', label: 'Tất cả' },
    { value: 'PENDING', label: 'Chờ xác nhận' },
    { value: 'CONFIRMED', label: 'Đã xác nhận' },
    { value: 'ARRIVED', label: 'Khách đã đến' },
    { value: 'COMPLETED', label: 'Hoàn tất' },
    { value: 'CANCELLED', label: 'Đã hủy' },
]

onMounted(() => reservationsStore.fetchAll())

const filteredReservations = computed(() => {
    return reservations.value
        .filter(r => filterStatus.value === 'all' || r.status === filterStatus.value)
        .filter(r => {
            const q = searchQuery.value.toLowerCase().trim()
            if (!q) return true
            return r.customer_name.toLowerCase().includes(q)
                || r.customer_phone.includes(q)
                || (r.table_code ?? '').toLowerCase().includes(q)
        })
        .sort((a, b) => {
            if (a.reserved_date !== b.reserved_date) return a.reserved_date.localeCompare(b.reserved_date)
            return a.reserved_time.localeCompare(b.reserved_time)
        })
})

function initials(name: string): string {
    return name.split(/\s+/).map(p => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase()
}

function statusLabel(status: Reservation['status']): string {
    const map: Record<Reservation['status'], string> = {
        PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận',
        ARRIVED: 'Đã đến', CANCELLED: 'Đã hủy', COMPLETED: 'Hoàn tất',
    }
    return map[status]
}

async function updateStatus(id: number, status: Reservation['status']): Promise<void> {
    try {
        await reservationsStore.updateStatus(id, status)
        toast.success(`Đã cập nhật: ${statusLabel(status)}`)
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    }
}
useRealtimeRefresh(() => reservationsStore.fetchAll(undefined, true), () => !reservationsStore.loading)
</script>

<style scoped>
.cashier-reservations { display: flex; flex-direction: column; gap: 16px; }

.toolbar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.search-input {
    flex: 1;
    min-width: 240px;
    padding: 9px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
}

.filter-tabs {
    display: flex;
    gap: 4px;
    background: #fff;
    padding: 4px;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    flex-wrap: wrap;
}

.filter-tab {
    padding: 7px 12px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    font-size: 12px;
    color: #6b7280;
}

.filter-tab.active { background: #16a34a; color: #fff; }

.reservation-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 12px;
}

.reservation-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.customer-block {
    display: flex;
    gap: 10px;
    align-items: center;
}

.customer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.customer-name { font-weight: 700; font-size: 14px; }
.customer-phone { font-size: 12px; color: #6b7280; margin-top: 2px; }

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

.card-time {
    background: #f9fafb;
    padding: 10px;
    border-radius: 8px;
}

.time-main { font-weight: 700; font-size: 14px; }
.time-meta { font-size: 12px; color: #6b7280; margin-top: 4px; }

.card-note {
    background: #fffbeb;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 12px;
    color: #92400e;
}

.card-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding-top: 8px;
    border-top: 1px solid #f3f4f6;
}

.btn {
    flex: 1;
    padding: 8px 10px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 12px;
}

.btn-confirm { background: #16a34a; color: #fff; }
.btn-arrived { background: #2563eb; color: #fff; }
.btn-done    { background: #6b7280; color: #fff; }
.btn-cancel  { background: #fee2e2; color: #991b1b; }

.empty-state {
    text-align: center;
    padding: 60px;
    color: #9ca3af;
}

.empty-icon { font-size: 56px; margin-bottom: 12px; }
</style>
