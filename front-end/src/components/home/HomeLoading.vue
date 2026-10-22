<script setup lang="ts">
defineProps<{
    loading?: boolean
    error?: string | null
}>()

defineEmits<{
    (e: 'retry'): void
}>()
</script>

<template>
    <div v-if="loading" class="overlay" role="status">
        <div class="spinner" aria-hidden="true"></div>
        <p>Đang tải dữ liệu trang chủ...</p>
    </div>

    <div v-else-if="error" class="overlay error" role="alert">
        <div class="err-icon">⚠️</div>
        <p class="err-msg">{{ error }}</p>
        <button class="retry-btn" @click="$emit('retry')">
            Thử lại
        </button>
    </div>

    <slot v-else />
</template>

<style scoped>
.overlay {
    background: rgba(255, 255, 255, 0.85);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    color: var(--hp-dark);
    text-align: center;
    padding: 20px;
}

.spinner {
    width: 56px;
    height: 56px;
    border: 5px solid var(--hp-border);
    border-top-color: var(--hp-primary);
    border-radius: 50%;
    animation: spin 0.9s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.err-icon { font-size: 56px; }
.err-msg  { color: var(--hp-primary); font-weight: 600; }

.retry-btn {
    margin-top: 6px;
    padding: 10px 22px;
    background: var(--hp-primary);
    color: #fff;
    border: 0;
    border-radius: 999px;
    font-weight: 600;
    cursor: pointer;
}

.retry-btn:hover { background: var(--hp-primary-dark); }
</style>
