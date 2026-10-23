<template>
    <aside
        class="staff-sidebar"
        :class="{ collapsed: collapsed && !isManager, 'manager-sidebar': isManager }"
    >
        <div class="sidebar-brand">
            <img src="/logo/logo.png" class="brand-mark" width="44" height="44" alt="K-SPICY" />
            <div v-if="!collapsed || isManager" class="brand-text">
                <div class="brand-title">{{ roleInfoSafe?.label ?? 'Staff' }}</div>
                <div class="brand-subtitle">Mì Cay Hàn Quốc</div>
            </div>
        </div>

        <nav v-if="isManager" class="sidebar-nav manager-groups" aria-label="Chức năng quản lý">
            <RouterLink
                to="/staff/manager/dashboard"
                class="nav-item nav-item--top"
                :class="{ active: isActive('/staff/manager/dashboard') }"
            >Tổng quan</RouterLink>

            <section
                v-for="group in managerGroups"
                :key="group.id"
                class="nav-group"
            >
                <button
                    class="group-toggle"
                    :aria-expanded="openGroups[group.id]"
                    :aria-controls="'manager-group-' + group.id"
                    @click="openGroups[group.id] = !openGroups[group.id]"
                >
                    <span>{{ group.label }}</span>
                    <span class="group-chevron" :class="{ expanded: openGroups[group.id] }" aria-hidden="true"></span>
                </button>
                <div v-show="openGroups[group.id]" :id="'manager-group-' + group.id" class="group-links">
                    <RouterLink
                        v-for="item in group.items"
                        :key="item.path"
                        :to="item.path"
                        class="nav-item"
                        :class="{ active: isActive(item.path) }"
                    >{{ item.label }}</RouterLink>
                </div>
            </section>
        </nav>

        <nav v-else class="sidebar-nav" aria-label="Chức năng">
            <RouterLink
                v-for="item in menuItems"
                :key="item.path"
                :to="item.path"
                class="nav-item"
                :class="{ active: isActive(item.path) }"
            >
                <span v-if="!collapsed || isManager" class="nav-label">{{ item.label }}</span>
                <span
                    v-if="!collapsed && item.badge && item.badge > 0"
                    class="nav-badge"
                    :aria-label="`${item.badge} mục chờ`"
                >{{ item.badge }}</span>
            </RouterLink>
        </nav>

        <div v-if="!isManager" class="sidebar-footer">
            <button class="sidebar-toggle" @click="collapsed = !collapsed">
                {{ collapsed ? 'Mở rộng' : 'Thu gọn' }}
            </button>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { computed, ref, reactive, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useStaffStore } from '@/stores/staff'
import { useKitchenStore } from '@/stores/staffKitchen'
import { usePaymentsStore } from '@/stores/staffPayments'
import { ROLE_LABELS, type StaffRole } from '@/types/staff'

const route = useRoute()
const staffStore = useStaffStore()
const kitchenStore = useKitchenStore()
const paymentsStore = usePaymentsStore()

const { roleInfo } = storeToRefs(staffStore)

const collapsed = ref(false)
const isManager = computed(() => staffStore.currentRole === 'MANAGER')

const managerGroups = [
    { id: 'menu', label: 'Quản lý thực đơn', items: [
        { path: '/staff/manager/menu', label: 'Món ăn' },
        { path: '/staff/manager/categories', label: 'Danh mục món ăn' },
    ] },
    { id: 'inventory', label: 'Kho & nguyên liệu', items: [
        { path: '/staff/manager/ingredients', label: 'Nguyên liệu' },
        { path: '/staff/manager/inventory', label: 'Kho hàng' },
        { path: '/staff/manager/daily-stock', label: 'Sổ kho hằng ngày' },
    ] },
    { id: 'sales', label: 'Đơn hàng & hóa đơn', items: [
        { path: '/staff/manager/orders', label: 'Đơn hàng' },
        { path: '/staff/manager/invoices', label: 'Hóa đơn' },
        { path: '/staff/manager/online-orders', label: 'Đơn giao hàng' },
    ] },
    { id: 'promotions', label: 'Khuyến mãi & Voucher', items: [
        { path: '/staff/manager/promotions', label: 'Mã giảm giá' },
        { path: '/staff/manager/marketing', label: 'AI Marketing Studio' },
    ] },
    { id: 'accounts', label: 'Tài khoản & phân quyền', items: [
        { path: '/staff/manager/accounts', label: 'Tài khoản' },
        { path: '/staff/manager/staff', label: 'Nhân viên' },
        { path: '/staff/manager/permissions', label: 'Phân quyền' },
    ] },
    { id: 'service', label: 'Dịch vụ khách hàng', items: [
        { path: '/staff/manager/live-chat', label: 'Tư vấn trực tiếp' },
    ] },
    { id: 'reports', label: 'Báo cáo thống kê', items: [
        { path: '/staff/manager/report', label: 'Doanh thu & món bán chạy' },
    ] },
]
const openGroups = reactive<Record<string, boolean>>(
    Object.fromEntries(managerGroups.map((group) => [group.id, true])),
)
watch(
    () => route.path,
    () => {
        const group = managerGroups.find((group) => group.items.some((item) => isActive(item.path)))
        if (group) openGroups[group.id] = true
    },
    { immediate: true },
)

interface MenuItem {
    path: string
    label: string
    roles: StaffRole[]
    badge?: number
}

const menuByRole: Record<StaffRole, MenuItem[]> = {
    WAITER: [
        { path: '/staff/waiter/tables',       label: 'Sơ đồ bàn',         roles: ['WAITER'] },
        { path: '/staff/waiter/orders',       label: 'Đơn đang phục vụ',  roles: ['WAITER'] },
        { path: '/staff/waiter/reservations', label: 'Đặt bàn trước',     roles: ['WAITER'] },
    ],
    KITCHEN: [
        { path: '/staff/kitchen/queue',     label: 'Món chờ chế biến', roles: ['KITCHEN'] },
        { path: '/staff/kitchen/preparing', label: 'Đang chế biến',    roles: ['KITCHEN'] },
        { path: '/staff/kitchen/ready',     label: 'Món đã sẵn sàng',  roles: ['KITCHEN'] },
        { path: '/staff/kitchen/availability', label: 'Báo hết món', roles: ['KITCHEN'] },
        { path: '/staff/kitchen/daily-stock', label: 'Sổ kho hằng ngày', roles: ['KITCHEN'] },
    ],
    CASHIER: [
        { path: '/staff/cashier/invoices',      label: 'Hóa đơn',            roles: ['CASHIER'] },
        { path: '/staff/cashier/online-orders', label: 'Đơn giao hàng',      roles: ['CASHIER'] },
        { path: '/staff/cashier/reservations',  label: 'Đặt bàn',            roles: ['CASHIER'] },
        { path: '/staff/cashier/revenue',       label: 'Báo cáo doanh thu',  roles: ['CASHIER'] },
    ],
    SHIPPER: [{ path: '/staff/shipper', label: 'Giao hàng', roles: ['SHIPPER'] }],
    MANAGER: [
        { path: '/staff/manager/dashboard',  label: 'Tổng quan',         roles: ['MANAGER'] },
        { path: '/staff/manager/statistics', label: 'Thống kê tổng quan',roles: ['MANAGER'] },
        { path: '/staff/manager/menu',       label: 'Quản lý món',       roles: ['MANAGER'] },
        { path: '/staff/manager/inventory',  label: 'Kho & Nguyên liệu', roles: ['MANAGER'] },
        { path: '/staff/manager/daily-stock', label: 'Sổ kho hằng ngày',roles: ['MANAGER'] },
        { path: '/staff/manager/promotions', label: 'Khuyến mãi & Voucher', roles: ['MANAGER'] },
        { path: '/staff/manager/staff',      label: 'Nhân viên',         roles: ['MANAGER'] },
        { path: '/staff/manager/report',     label: 'Báo cáo',           roles: ['MANAGER'] },
    ],
}

const menuItems = computed<MenuItem[]>(() => {
    if (!staffStore.currentRole) return []
    const base = menuByRole[staffStore.currentRole] ?? []
    if (staffStore.currentRole === 'KITCHEN') {
        return base.map((item, idx) => {
            if (idx === 0) return { ...item, badge: kitchenStore.pendingItems.length }
            if (idx === 1) return { ...item, badge: kitchenStore.preparingItems.length }
            if (idx === 2) return { ...item, badge: kitchenStore.readyItems.length }
            return item
        })
    }
    if (staffStore.currentRole === 'CASHIER') {
        return base.map((item, idx) => {
            if (idx === 0) return { ...item, badge: paymentsStore.unpaidInvoices.length }
            return item
        })
    }
    return base
})

function isActive(path: string): boolean {
    return route.path === path || route.path.startsWith(path + '/')
}

const roleInfoSafe = computed(() => roleInfo.value ?? ROLE_LABELS.WAITER)
</script>

<style scoped>
/* ============================================================
 * Brand-aligned tokens for staff sidebar.
 * ============================================================ */

.manager-groups { gap: var(--space-2); }

.nav-group {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.04);
    overflow: hidden;
}

.group-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    padding: 12px 14px;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.85);
    font: 600 12px var(--font-family-base);
    text-align: left;
    cursor: pointer;
    letter-spacing: 0.3px;
}

.group-toggle:hover { background: rgba(255, 255, 255, 0.06); }

.group-chevron {
    width: 7px;
    height: 7px;
    border-right: 1.5px solid currentColor;
    border-bottom: 1.5px solid currentColor;
    transform: rotate(45deg);
    flex-shrink: 0;
    transition: transform var(--dur-fast) var(--ease-out);
}

.group-chevron.expanded { transform: rotate(225deg); }

.group-links { padding: 0 6px 6px; }

.group-links .nav-item {
    font-size: 12.5px;
    padding: 9px 12px;
    line-height: 1.5;
}

.staff-sidebar {
    width: 240px;
    background: var(--color-brand-dark);
    color: var(--color-white);
    display: flex;
    flex-direction: column;
    transition: width var(--dur-base) var(--ease-out);
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    flex-shrink: 0;
}

.staff-sidebar.collapsed { width: 70px; }

.sidebar-brand {
    padding: 22px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand-mark {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md);
    object-fit: contain;
    flex-shrink: 0;
}


.brand-title {
    font-size: 16px;
    font-weight: 700;
    line-height: 1.2;
    color: var(--color-white);
}

.brand-subtitle {
    font-size: 11px;
    color: var(--color-brand-muted-inverse);
    margin-top: 2px;
}

.sidebar-nav {
    flex: 1;
    padding: 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: var(--radius-md);
    color: var(--color-brand-muted-inverse);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: background var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
    position: relative;
}

.nav-item:hover {
    background: rgba(255, 255, 255, 0.05);
    color: var(--color-white);
}

.nav-item.active {
    background: var(--color-brand-action);
    color: var(--color-white);
    font-weight: 600;
}

.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 25%;
    bottom: 25%;
    width: 3px;
    background: var(--color-white);
    border-radius: 0 3px 3px 0;
}

.nav-item--top {
    background: rgba(255, 255, 255, 0.06);
    margin-bottom: var(--space-2);
}

.nav-item--top.active {
    background: var(--color-chili-red);
    color: var(--color-white);
}

.nav-label {
    flex: 1;
    white-space: nowrap;
}

.nav-badge {
    background: var(--color-chili-red);
    color: var(--color-white);
    border-radius: var(--radius-full);
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
    min-width: 22px;
    text-align: center;
    line-height: 1.4;
}

.sidebar-footer {
    padding: 12px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.sidebar-toggle {
    width: 100%;
    background: rgba(255, 255, 255, 0.05);
    border: none;
    color: var(--color-brand-muted-inverse);
    padding: 9px;
    border-radius: var(--radius-md);
    cursor: pointer;
    font: 500 13px var(--font-family-base);
    transition: background var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
}

.sidebar-toggle:hover {
    background: rgba(255, 255, 255, 0.1);
    color: var(--color-white);
}

@media (max-width: 768px) {
    .staff-sidebar { width: 70px; }
    .staff-sidebar .nav-label,
    .staff-sidebar .brand-text,
    .staff-sidebar .nav-badge { display: none; }
    .staff-sidebar .brand-mark { margin: 0 auto; }
}
</style>
