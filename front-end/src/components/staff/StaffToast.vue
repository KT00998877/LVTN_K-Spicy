<template>
    <Teleport to="body">
        <div class="staff-toast-container">
            <transition-group name="staff-toast">
                <div
                    v-for="t in toasts"
                    :key="t.id"
                    class="staff-toast"
                    :class="`staff-toast-${t.type}`"
                >
                    <span class="staff-toast-icon">{{ iconFor(t.type) }}</span>
                    <span class="staff-toast-message">{{ t.message }}</span>
                </div>
            </transition-group>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { useToast } from '@/composables/useStaffUtils'

const { toasts } = useToast()

function iconFor(type: 'success' | 'error' | 'warning' | 'info'): string {
    return type === 'success' ? '✓'
         : type === 'error'   ? '✕'
         : type === 'warning' ? '⚠'
         : 'ⓘ'
}
</script>

<style scoped>
.staff-toast-container {
    position: fixed;
    top: 80px;
    right: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}

.staff-toast {
    pointer-events: auto;
    min-width: 240px;
    max-width: 360px;
    padding: 12px 16px;
    border-radius: 10px;
    color: #fff;
    font-size: 14px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
}

.staff-toast-success { background: #16a34a; }
.staff-toast-error   { background: #dc2626; }
.staff-toast-warning { background: #ea580c; }
.staff-toast-info    { background: #2563eb; }

.staff-toast-icon {
    font-weight: 800;
    font-size: 18px;
    flex-shrink: 0;
}

.staff-toast-enter-from { opacity: 0; transform: translateX(20px); }
.staff-toast-leave-to   { opacity: 0; transform: translateX(20px); }
.staff-toast-enter-active,
.staff-toast-leave-active { transition: all 0.25s ease; }
</style>
