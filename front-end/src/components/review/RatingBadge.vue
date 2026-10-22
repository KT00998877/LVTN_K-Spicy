<script setup lang="ts">
/**
 * RatingBadge — hiển thị rating gọn cho menu card / best sellers.
 * Fetch summary và cache trong memory.
 */
import { ref, watch } from 'vue'
import { fetchReviewSummary } from '@/api/review'

const props = defineProps<{
    menuItemId: number
    ratingSummary?: { count: number; average: number }
    /** Hiển thị compact (chỉ sao + số) hay đầy đủ (badge nổi bật). */
    variant?: 'badge' | 'inline'
}>()

const summary = ref<{ count: number; average: number } | null>(null)
const loading = ref(false)

async function load() {
    if (props.ratingSummary) {
        summary.value = props.ratingSummary
        return
    }
    if (!props.menuItemId) return
    loading.value = true
    try {
        summary.value = await fetchReviewSummary(props.menuItemId)
    } catch {
        summary.value = null
    } finally {
        loading.value = false
    }
}

watch(() => [props.menuItemId, props.ratingSummary], load, { immediate: true })
</script>

<template>
    <div v-if="summary && summary.count > 0" class="rating-badge" :class="variant || 'badge'" :aria-label="`Đánh giá ${summary.average.toFixed(1)} trên 5 từ ${summary.count} khách`">
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
            <path
                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
                fill="#FFB000"
            />
        </svg>
        <span class="rating-text">
            <strong>{{ summary.average.toFixed(1) }}</strong>
            <span class="count">({{ summary.count }})</span>
        </span>
    </div>
</template>

<style scoped>
.rating-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
}

.rating-badge.badge {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(8px);
    color: var(--color-charcoal-black, #111);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.rating-badge.inline {
    background: transparent;
    color: var(--color-gray-600, #4b5563);
    padding: 0;
}

.rating-text { display: inline-flex; align-items: baseline; gap: 2px; }
.rating-text strong { font-weight: 800; }
.rating-text .count { color: var(--color-gray-500, #6b7280); font-size: 11px; font-weight: 500; }
</style>
