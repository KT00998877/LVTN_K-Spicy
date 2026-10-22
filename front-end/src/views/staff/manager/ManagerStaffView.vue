<template>
    <div class="manager-staff">
        <header class="manager-heading"><div><span class="eyebrow">QUẢN LÝ NHÀ HÀNG</span><h2>Đội ngũ nhân viên</h2><p>Tra cứu nhân viên theo vai trò và quản lý trạng thái tài khoản.</p></div><button class="manager-button" :disabled="dashboardStore.loading" @click="dashboardStore.fetchAll()">Làm mới dữ liệu</button></header>
        <p v-if="dashboardStore.loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>
        <p v-else-if="dashboardStore.error" class="manager-notice error" role="alert">{{ dashboardStore.error }} <button @click="dashboardStore.fetchAll()">Thử lại</button></p>
        <input v-model="search" class="manager-search" aria-label="Tìm nhân viên" placeholder="Tìm theo tên hoặc email…" />
        <div class="role-tabs">
            <button
                v-for="r in roles"
                :key="r.code"
                class="role-tab"
                :class="{ active: filterRole === r.code }"
                @click="filterRole = r.code"
            >
                {{ r.label }} ({{ countByRole(r.code) }})
            </button>
            <button class="role-tab" :class="{ active: filterRole === null }" @click="filterRole = null">
                Tất cả ({{ dashboardStore.staffUsers.length }})
            </button>
        </div>

        <div class="staff-grid">
            <div v-for="user in filteredUsers" :key="user.id" class="staff-card">
                <div class="staff-avatar">{{ initials(user.name) }}</div>
                <div class="staff-info">
                    <div class="staff-name">{{ user.name }}</div>
                    <div class="staff-email">{{ user.email }}</div>
                    <div class="staff-roles">
                        <span
                            v-for="r in user.roles"
                            :key="r"
                            class="role-tag"
                            :style="{ background: ROLE_LABELS[r as StaffRole].color + '20', color: ROLE_LABELS[r as StaffRole].color }"
                        >
                            {{ ROLE_LABELS[r as StaffRole].label }}
                        </span>
                    </div>
                </div>
                <div class="staff-actions"><span class="account-status" :class="{ inactive: !user.is_active }">{{ user.is_active ? 'Đang hoạt động' : 'Đã vô hiệu hóa' }}</span>
                    <button class="manager-button" :disabled="updating !== null" @click="toggleStatus(user)" :title="user.is_active ? 'Vô hiệu hóa' : 'Kích hoạt'">
                        {{ user.is_active ? 'Vô hiệu hóa' : 'Kích hoạt' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="!dashboardStore.loading && !dashboardStore.error && filteredUsers.length === 0" class="empty-mini">
            Không có nhân viên nào trong nhóm này
        </div>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref } from 'vue'
import { useDashboardStore } from '@/stores/staffDashboard'
import { useToast } from '@/composables/useStaffUtils'
import { ROLE_LABELS, type StaffRole } from '@/types/staff'

const dashboardStore = useDashboardStore()
const toast = useToast()

const filterRole = ref<StaffRole | null>(null)
const search = ref('')
const updating = ref<number | null>(null)

const roles: Array<{ code: StaffRole; label: string; icon: string }> = [
    { code: 'WAITER',  ...ROLE_LABELS.WAITER },
    { code: 'KITCHEN', ...ROLE_LABELS.KITCHEN },
    { code: 'CASHIER', ...ROLE_LABELS.CASHIER },
    { code: 'SHIPPER', ...ROLE_LABELS.SHIPPER },
    { code: 'MANAGER', ...ROLE_LABELS.MANAGER },
]

onMounted(() => dashboardStore.fetchAll())

const filteredUsers = computed(() => {
    return dashboardStore.staffUsers.filter(u => (!filterRole.value || u.roles.includes(filterRole.value)) && `${u.name} ${u.email}`.toLowerCase().includes(search.value.trim().toLowerCase()))
})

function countByRole(role: StaffRole): number {
    return dashboardStore.staffUsers.filter(u => u.roles.includes(role)).length
}

function initials(name: string): string {
    return name.split(/\s+/).map(p => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase()
}

async function toggleStatus(user: { id: number; name: string; is_active: boolean }): Promise<void> {
    if (updating.value !== null) return
    updating.value = user.id
    try {
        await dashboardStore.updateStaffUser(user.id, { is_active: !user.is_active })
        toast.success(`${user.name}: ${!user.is_active ? 'Đã kích hoạt' : 'Đã vô hiệu'}`)
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    } finally { updating.value = null }
}
useRealtimeRefresh(() => dashboardStore.fetchAll(true), () => !dashboardStore.loading)
</script>

<style scoped>
.staff-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }.account-status { font-size: 12px; color: #166534; }.account-status.inactive { color: #9a3412; }.staff-email { overflow-wrap: anywhere; }.staff-card { flex-wrap: wrap; }
.manager-staff { display: flex; flex-direction: column; gap: 16px; }

.role-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.role-tab {
    background: #fff;
    border: 1px solid #e5e7eb;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    color: #6b7280;
}

.role-tab.active {
    background: #7c3aed;
    color: #fff;
    border-color: #7c3aed;
}

.staff-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.staff-card {
    background: #fff;
    padding: 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.staff-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    color: #fff;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.staff-info { flex: 1; min-width: 0; }

.staff-name { font-weight: 700; font-size: 14px; }
.staff-email { font-size: 12px; color: #6b7280; margin-top: 2px; }

.staff-roles {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.role-tag {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}

.btn-icon {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 20px;
}

.empty-mini {
    padding: 60px;
    text-align: center;
    color: #9ca3af;
}
</style>
