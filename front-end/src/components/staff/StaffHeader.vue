<template>
    <header class="staff-header">
        <div class="header-left">
            <h2 class="page-title">{{ pageTitle }}</h2>
            <p v-if="subtitle" class="page-subtitle">{{ subtitle }}</p>
        </div>

        <div class="header-right">
            <StaffNotificationBell v-if="staffStore.userRoles.some(role => ['MANAGER', 'KITCHEN', 'SHIPPER'].includes(role))" />
            <div class="header-clock" aria-live="polite">
                <span class="clock-time">{{ currentTime }}</span>
                <span class="clock-date">{{ currentDate }}</span>
            </div>

            <div
                class="header-user"
                :class="{ 'is-open': showMenu }"
                @click="showMenu = !showMenu"
                v-click-outside="() => showMenu = false"
                tabindex="0"
                role="button"
                :aria-expanded="showMenu"
                aria-haspopup="menu"
                @keydown.enter.prevent="showMenu = !showMenu"
                @keydown.escape="showMenu = false"
            >
                <div class="user-avatar" :aria-hidden="true">{{ userInitials }}</div>
                <div class="user-info">
                    <div class="user-name">{{ staffStore.session?.name }}</div>
                    <div class="user-role">{{ staffStore.roleInfo?.label }}</div>
                </div>
                <span class="user-dropdown" aria-hidden="true">▾</span>

                <transition name="fade-down">
                    <div v-if="showMenu" class="user-menu" role="menu" @click.stop>
                        <button class="menu-item logout" role="menuitem" @click="logout">
                            <span class="menu-item__icon" aria-hidden="true">↩</span>
                            <span>Đăng xuất</span>
                        </button>
                    </div>
                </transition>
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useStaffStore } from '@/stores/staff'
import StaffNotificationBell from '@/components/staff/StaffNotificationBell.vue'

defineProps<{
    pageTitle: string
    subtitle?: string
}>()

const router = useRouter()
const staffStore = useStaffStore()
const { session } = storeToRefs(staffStore)

const showMenu = ref(false)
const currentTime = ref('')
const currentDate = ref('')
let clockTimer: number | null = null

function updateClock(): void {
    const now = new Date()
    currentTime.value = now.toLocaleTimeString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
    currentDate.value = now.toLocaleDateString('vi-VN', {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
    })
}

onMounted(() => {
    updateClock()
    clockTimer = window.setInterval(updateClock, 1000)
})

onUnmounted(() => {
    if (clockTimer !== null) window.clearInterval(clockTimer)
})

const userInitials = computed(() => {
    const name = session.value?.name ?? 'Staff'
    return name
        .split(/\s+/)
        .map((p) => p[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase()
})

async function logout(): Promise<void> {
    await staffStore.logout()
    showMenu.value = false
    router.push('/staff/login')
}

/* Click-outside directive */
const vClickOutside = {
    mounted(el: HTMLElement, binding: { value: () => void }) {
        ;(el as unknown as { _handler: (e: Event) => void })._handler = (e: Event) => {
            if (!el.contains(e.target as Node)) binding.value()
        }
        document.addEventListener('click', (el as unknown as { _handler: (e: Event) => void })._handler)
    },
    unmounted(el: HTMLElement) {
        document.removeEventListener(
            'click',
            (el as unknown as { _handler: (e: Event) => void })._handler,
        )
    },
}
</script>

<style scoped>
.staff-header {
    background: var(--color-brand-soft);
    border-bottom: 1px solid var(--color-border-strong);
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
    gap: var(--space-4);
}

.page-title {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    color: var(--color-brand-ink);
    letter-spacing: -0.4px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--color-gray-500);
    margin: 4px 0 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.header-clock {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    font-size: 13px;
    line-height: 1.3;
}

.clock-time {
    font-weight: 700;
    color: var(--color-charcoal-black);
    font-variant-numeric: tabular-nums;
    font-size: 14px;
}

.clock-date {
    color: var(--color-gray-500);
    font-size: 11px;
    margin-top: 2px;
}

.header-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 12px 6px 6px;
    border-radius: var(--radius-full);
    background: var(--color-gray-100);
    cursor: pointer;
    position: relative;
    transition: background var(--dur-fast) var(--ease-out);
    user-select: none;
    border: 1px solid transparent;
}

.header-user:hover,
.header-user:focus-visible,
.header-user.is-open {
    background: var(--color-cream-dark);
    outline: none;
    border-color: var(--color-chili-red);
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--color-chili-red);
    color: var(--color-white);
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.user-info {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.user-name {
    font-weight: 600;
    font-size: 13px;
    color: var(--color-charcoal-black);
}

.user-role {
    font-size: 11px;
    color: var(--color-gray-500);
    margin-top: 2px;
}

.user-dropdown {
    color: var(--color-gray-500);
    font-size: 12px;
    transition: transform var(--dur-fast) var(--ease-out);
}

.header-user.is-open .user-dropdown {
    transform: rotate(180deg);
    color: var(--color-chili-red);
}

.user-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    min-width: 200px;
    overflow: hidden;
    z-index: 100;
    padding: 6px;
}

.menu-item {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 9px 12px;
    border-radius: var(--radius-sm);
    color: var(--color-charcoal-black);
    cursor: pointer;
    font: 500 13px var(--font-family-base);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background var(--dur-fast) var(--ease-out);
}

.menu-item:hover { background: var(--color-gray-100); }
.menu-item.logout { color: var(--color-error); }
.menu-item.logout:hover { background: var(--color-error-bg); }

.menu-item__icon {
    width: 18px;
    text-align: center;
    font-weight: 700;
}

.fade-down-enter-from { opacity: 0; transform: translateY(-8px); }
.fade-down-leave-to   { opacity: 0; transform: translateY(-8px); }
.fade-down-enter-active,
.fade-down-leave-active { transition: all var(--dur-fast) var(--ease-out); }

@media (max-width: 768px) {
    .staff-header { padding: 12px 16px; }
    .user-info { display: none; }
    .header-clock { display: none; }
}
</style>
