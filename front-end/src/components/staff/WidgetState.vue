<template>
    <div class="widget-state" :class="`widget-state--${effectiveState}`" role="status">
        <template v-if="loading">
            <div class="widget-state__skeleton" aria-hidden="true">
                <span class="widget-state__bar widget-state__bar--sm"></span>
                <span class="widget-state__bar widget-state__bar--lg"></span>
                <span class="widget-state__bar widget-state__bar--md"></span>
                <span class="widget-state__bar widget-state__bar--xs"></span>
            </div>
        </template>
        <template v-else-if="error">
            <div class="widget-state__msg">
                <span class="widget-state__icon" aria-hidden="true">!</span>
                <div>
                    <strong>Không tải được dữ liệu</strong>
                    <p>{{ error }}</p>
                </div>
                <button class="widget-state__btn" @click="emit('retry')">Thử lại</button>
            </div>
        </template>
        <template v-else-if="empty">
            <p class="widget-state__empty">{{ emptyMessage }}</p>
        </template>
        <template v-else>
            <slot />
        </template>
    </div>
</template>

<script setup lang="ts">
/**
 * WidgetState — wrapper cho từng widget statistics.
 *  - loading: hiển thị skeleton.
 *  - error: thông báo lỗi + nút retry.
 *  - empty: thông báo "chưa có dữ liệu" nhưng không crash.
 *  - bình thường: render slot.
 */
import { computed } from 'vue'

const props = defineProps<{
    loading: boolean
    error: string | null | undefined
    empty: boolean
    emptyMessage?: string
}>()

const emit = defineEmits<{ (e: 'retry'): void }>()

const effectiveState = computed(() => {
    if (props.loading) return 'loading'
    if (props.error) return 'error'
    if (props.empty) return 'empty'
    return 'ok'
})
</script>

<style scoped>
.widget-state {
    position: relative;
    width: 100%;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.widget-state--loading { display: block; padding: 8px 0; }
.widget-state__skeleton {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    animation: ws-pulse 1.4s ease-in-out infinite;
}
.widget-state__bar {
    display: block;
    height: 16px;
    border-radius: 8px;
    background: linear-gradient(90deg, #f3f4f6, #e5e7eb, #f3f4f6);
    background-size: 200% 100%;
    animation: ws-shimmer 1.4s linear infinite;
}
.widget-state__bar--lg { width: 80%; }
.widget-state__bar--md { width: 60%; }
.widget-state__bar--sm { width: 40%; }
.widget-state__bar--xs { width: 25%; }
@keyframes ws-shimmer {
    from { background-position: 0% 0; }
    to   { background-position: -200% 0; }
}
@keyframes ws-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
.widget-state__msg {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fee2e2;
    color: #991b1b;
    padding: 14px 18px;
    border-radius: 12px;
    width: 100%;
}
.widget-state__msg strong { font-weight: 800; }
.widget-state__msg p { margin: 2px 0 0; font-size: 12px; opacity: 0.85; }
.widget-state__icon {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: #991b1b;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    flex: 0 0 auto;
}
.widget-state__btn {
    margin-left: auto;
    padding: 7px 14px;
    background: #fff;
    border: 1px solid #fca5a5;
    color: #991b1b;
    font-weight: 700;
    font-family: inherit;
    border-radius: 9999px;
    cursor: pointer;
}
.widget-state__btn:hover { background: #fee2e2; }
.widget-state__empty {
    text-align: center;
    color: var(--mgr-gray-500, #6b7280);
    font-size: 13px;
    padding: 40px 12px;
    margin: 0;
    width: 100%;
    border: 1px dashed var(--mgr-gray-200, #e5e7eb);
    border-radius: 12px;
}
</style>
