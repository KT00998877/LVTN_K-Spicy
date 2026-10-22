<template>
    <div class="staff-layout" :class="{
        'manager-shell': staffStore.currentRole === 'MANAGER',
        'staff-layout--marketing': route.name === 'manager-marketing',
    }">
        <StaffSidebar />
        <div class="staff-main">
            <StaffHeader :page-title="pageTitle" :subtitle="subtitle" />
            <ServiceNotifications v-if="staffStore.currentRole === 'WAITER'" />
            <main class="staff-content">
                <RouterView />
            </main>
        </div>
        <StaffToast />
    </div>
</template>

<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router'
import { computed, watch, onUnmounted } from 'vue'
import '@/assets/manager.css'
import StaffSidebar from '@/components/staff/StaffSidebar.vue'
import StaffHeader from '@/components/staff/StaffHeader.vue'
import StaffToast from '@/components/staff/StaffToast.vue'
import ServiceNotifications from '@/components/staff/ServiceNotifications.vue'
import { useStaffStore } from '@/stores/staff'
import { useStaffNotificationsStore } from '@/stores/staffNotifications'
const staffStore = useStaffStore()
const notifications = useStaffNotificationsStore()
watch(() => staffStore.session, session => {
    if (session && session.roles.some(role => ['MANAGER', 'KITCHEN', 'SHIPPER'].includes(role))) notifications.start(session)
    else notifications.stop()
}, { immediate: true })
onUnmounted(() => notifications.stop())

const route = useRoute()

// Lấy title và subtitle từ meta của route
const pageTitle = computed(() => (route.meta?.title as string) ?? 'Staff')
const subtitle = computed(() => (route.meta?.subtitle as string) ?? '')
</script>

<style scoped>
.staff-layout {
    display: flex;
    min-height: 100vh;
    background: var(--color-background);
}

.staff-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.staff-content {
    flex: 1;
    padding: 24px;
    overflow-x: auto;
}

/* Keep native document scrolling; the content must not trap wheel events. */
.staff-layout--marketing .staff-content {
    min-width: 0;
    overflow: visible;
}
/* Marketing sidebar participates in document flow instead of scrolling itself. */
.staff-layout--marketing :deep(.staff-sidebar) {
    position: relative;
    top: auto;
    height: auto;
    min-height: 100vh;
    align-self: stretch;
    overflow: visible;
}

@media (max-width: 768px) {
    .staff-content { padding: 16px; }
}
</style>
