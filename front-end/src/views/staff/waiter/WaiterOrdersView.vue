<template>
    <div class="waiter-orders">
        <div class="filter-row">
            <select v-model="filterStatus" class="filter-select">
                <option value="">Đang phục vụ</option>
                <option value="PENDING">Chờ chọn món / chờ bếp</option>
                <option value="CONFIRMED">Đã xác nhận</option>
                <option value="PREPARING">Đang chế biến</option>
                <option value="READY">Sẵn sàng</option>
                <option value="SERVED">Đã lên món</option>
                <option value="COMPLETED">Hoàn tất</option>
                <option value="CANCELLED">Đã hủy</option>
            </select>
            <input v-model="searchTable" type="text" class="filter-input" placeholder="Tìm theo bàn..." />
            <button class="btn-refresh" :disabled="loading" @click="refresh">{{ loading ? 'Đang cập nhật...' : 'Làm mới' }}</button>
        </div>

        <p v-if="error" role="alert">{{ error }} <button :disabled="loading" @click="refresh">Thử lại</button></p>
        <p v-if="loading && !orders.length" role="status">Đang tải danh sách đơn...</p>

        <div class="orders-grid">
            <RouterLink
                v-for="order in filteredOrders"
                :key="order.id"
                :to="`/staff/waiter/orders/${order.id}`"
                class="order-card"
            >
                <div class="order-card-header">
                    <div class="order-id">
                        <div class="order-code">{{ order.code }}</div>
                        <div class="order-table">Bàn {{ order.table_code ?? '—' }}</div>
                    </div>
                    <span class="status-badge" :class="`status-${order.status}`">
                        {{ statusLabel(order.status) }}
                    </span>
                </div>
                <div class="order-card-body">
                    <div class="order-info-row">
                        <span>👤 {{ order.customer_name ?? order.waiter_name ?? 'Khách lẻ' }}</span>
                        <span>🕒 {{ formatTime(order.placed_at) }}</span>
                    </div>
                    <div class="order-items-preview">
                        <div v-for="item in (order.items ?? []).filter(i => i.status !== 'CANCELLED').slice(0, 3)" :key="item.id" class="preview-item">
                            {{ item.quantity }}× {{ item.menu_item_name }}
                            <span class="preview-status" :class="`chip-${item.status}`">·</span>
                        </div>
                        <div v-if="(order.item_count ?? 0) > 3" class="preview-more">
                            +{{ order.item_count! - 3 }} món khác
                        </div>
                        <div v-if="!order.total_quantity" class="preview-more">Bàn đã mở · Chưa chọn món</div>
                    </div>
                </div>
                <div class="order-card-footer">
                    <div class="order-total">{{ formatVND(order.total_amount) }}</div>
                    <div class="order-item-count">{{ order.total_quantity }} món</div>
                </div>
            </RouterLink>
        </div>

        <div v-if="!loading && !error && filteredOrders.length === 0" class="empty-state">
            <div class="empty-icon">📋</div>
            <div>{{ filterStatus || searchTable ? 'Không có đơn phù hợp với bộ lọc' : 'Chưa có đơn đang phục vụ' }}</div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useStaffOrdersStore } from '@/stores/staffOrders'
import { formatTime, formatVND } from '@/composables/useStaffUtils'
import type { Order } from '@/types/staff'

const ordersStore = useStaffOrdersStore()
const { orders, loading, error } = storeToRefs(ordersStore)

const filterStatus = ref('')
const searchTable = ref('')

onMounted(() => {
    void refresh()

})

const filteredOrders = computed(() => {
    return orders.value
        .filter(o => o.order_type === 'DINE_IN')
        .filter(o => filterStatus.value ? o.status === filterStatus.value : !['COMPLETED', 'CANCELLED'].includes(o.status))
        .filter(o => !searchTable.value || (o.table_code ?? '').toLowerCase().includes(searchTable.value.toLowerCase()))
        .sort((a, b) => {
            const aTime = a.placed_at ? new Date(a.placed_at).getTime() : 0
            const bTime = b.placed_at ? new Date(b.placed_at).getTime() : 0
            return bTime - aTime
        })
})

async function refresh(): Promise<void> {
    await ordersStore.fetchAll()
}

function statusLabel(status: Order['status']): string {
    const map: Record<Order['status'], string> = {
        PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận', PREPARING: 'Đang nấu',
        READY: 'Sẵn sàng', SERVED: 'Đã lên',
        DELIVERING: 'Đang giao', DELIVERED: 'Chờ khách xác nhận',
        COMPLETED: 'Hoàn tất', CANCELLED: 'Đã hủy',
    }
    return map[status]
}
useRealtimeRefresh(() => ordersStore.fetchAll(undefined, true), () => !loading.value)
</script>

<style scoped>
.waiter-orders {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.filter-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-select,
.filter-input {
    padding: 9px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    background: #fff;
    font-family: inherit;
}

.filter-input { flex: 1; min-width: 200px; }

.btn-refresh {
    padding: 9px 14px;
    background: #f3f4f6;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
}

.orders-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
}

.order-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px;
    text-decoration: none;
    color: inherit;
    transition: all 0.15s;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.order-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.06);
}

.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.order-code {
    font-weight: 700;
    font-size: 15px;
    color: #111827;
}

.order-table {
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

.status-badge.status-PREPARING { background: #fed7aa; color: #9a3412; }
.status-badge.status-READY     { background: #d1fae5; color: #065f46; }
.status-badge.status-SERVED    { background: #dbeafe; color: #1e3a8a; }
.status-badge.status-COMPLETED { background: #f3f4f6; color: #374151; }
.status-badge.status-PENDING   { background: #e0e7ff; color: #3730a3; }
.status-badge.status-CONFIRMED { background: #dbeafe; color: #1e40af; }

.order-info-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #4b5563;
}

.order-items-preview {
    background: #f9fafb;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 12px;
}

.preview-item {
    color: #1f2937;
    line-height: 1.6;
}

.preview-status {
    margin-left: 4px;
    font-size: 16px;
    line-height: 1;
}

.chip-PENDING   { color: #6366f1; }
.chip-PREPARING { color: #ea580c; }
.chip-READY     { color: #16a34a; }
.chip-SERVED    { color: #2563eb; }
.chip-CANCELLED { color: #ef4444; }

.preview-more {
    color: #6b7280;
    font-style: italic;
    margin-top: 4px;
}

.order-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 8px;
    border-top: 1px solid #f3f4f6;
}

.order-total {
    font-weight: 700;
    font-size: 16px;
    color: #f97316;
}

.order-item-count {
    font-size: 12px;
    color: #6b7280;
}

.empty-state {
    text-align: center;
    padding: 60px;
    color: #9ca3af;
}

.empty-icon {
    font-size: 56px;
    margin-bottom: 12px;
}
</style>
