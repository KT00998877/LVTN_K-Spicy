<script setup lang="ts">
/**
 * ReviewList — hiển thị danh sách reviews + summary rating + phân trang.
 *
 * Props:
 *  - menuItemId: id món ăn (bắt buộc)
 *  - limit: số review tối đa lấy lần đầu (mặc định 5)
 *  - showSummary: hiển thị summary (avg + breakdown) - mặc định true
 *  - showMenuItemInfo: hiển thị tên món (mặc định false - dùng cho AccountView)
 */
import { ref, computed, onMounted, watch } from 'vue'
import StarRating from './StarRating.vue'
import type { Review, ReviewListResponse, ReviewSummary } from '@/types/review'
import { fetchReviews } from '@/api/review'

const props = withDefaults(defineProps<{
    menuItemId?: number
    items?: Review[]
    summary?: ReviewSummary
    showSummary?: boolean
    showMenuItemInfo?: boolean
    compact?: boolean
    emptyText?: string
}>(), {
    menuItemId: 0,
    items: () => [],
    showSummary: true,
    showMenuItemInfo: false,
    compact: false,
    emptyText: 'Chưa có đánh giá nào cho món này.',
})

const reviews = ref<Review[]>([])
const summary = ref<ReviewSummary | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)

const isOwnProps = computed(() => props.items && props.items.length >= 0 && !props.menuItemId)

async function load(page = 1) {
    if (!props.menuItemId) return
    loading.value = true
    error.value = null
    try {
        const data: ReviewListResponse = await fetchReviews(props.menuItemId, page, 10)
        reviews.value = data.items
        summary.value = data.summary
        currentPage.value = data.meta.current_page
        lastPage.value = data.meta.last_page
        total.value = data.meta.total
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Không thể tải đánh giá.'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    if (!isOwnProps.value) load(1)
})

watch(() => props.menuItemId, () => {
    if (!isOwnProps.value) load(1)
})

const displayItems = computed<Review[]>(() => {
    return isOwnProps.value ? props.items : reviews.value
})

const displaySummary = computed(() => {
    return props.summary ?? summary.value ?? { count: 0, average: 0, breakdown: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 } }
})

function formatDate(s: string | null) {
    if (!s) return ''
    const d = new Date(s)
    return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function breakdownPercent(level: number) {
    const total = displaySummary.value.count || 0
    if (total === 0) return 0
    const bd = displaySummary.value.breakdown as unknown as Record<number, number>
    return Math.round(((bd[level] || 0) / total) * 100)
}

function loadPage(p: number) {
    if (p < 1 || p > lastPage.value || loading.value) return
    load(p)
}
</script>

<template>
    <div class="review-list" :class="{ compact }">
        <!-- Summary -->
        <div v-if="showSummary" class="review-summary">
            <div class="summary-avg">
                <div class="avg-number">{{ displaySummary.average > 0 ? displaySummary.average.toFixed(1) : '—' }}</div>
                <StarRating
                    :value="displaySummary.average"
                    readonly
                    size="md"
                />
                <div class="avg-count">{{ displaySummary.count }} đánh giá</div>
            </div>
            <div class="summary-breakdown">
                <div v-for="level in [5, 4, 3, 2, 1]" :key="level" class="breakdown-row">
                    <span class="breakdown-label">{{ level }} sao</span>
                    <div class="breakdown-bar" :aria-label="`${displaySummary.breakdown[level] || 0} đánh giá ${level} sao`">
                        <div class="breakdown-fill" :style="{ width: `${breakdownPercent(level)}%` }"></div>
                    </div>
                    <span class="breakdown-count">{{ displaySummary.breakdown[level] || 0 }}</span>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-if="error" class="review-error" role="alert">{{ error }}</div>

        <!-- Empty -->
        <div v-else-if="displayItems.length === 0 && !loading && !isOwnProps" class="review-empty">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            <p>{{ emptyText }}</p>
        </div>

        <div v-else-if="displayItems.length === 0 && isOwnProps" class="review-empty">
            <p>{{ emptyText }}</p>
        </div>

        <!-- Loading -->
        <div v-else-if="loading" class="review-loading">Đang tải đánh giá...</div>

        <!-- List -->
        <ul v-else class="reviews">
            <li v-for="r in displayItems" :key="r.id" class="review-item">
                <div class="review-head">
                    <div class="avatar">{{ (r.user?.name || 'K').charAt(0).toUpperCase() }}</div>
                    <div class="meta">
                        <div class="name">{{ r.user?.name || 'Khách hàng' }}</div>
                        <div class="info">
                            <StarRating :value="r.rating" readonly size="sm" />
                            <span class="dot">•</span>
                            <time>{{ formatDate(r.created_at) }}</time>
                            <span v-if="showMenuItemInfo && r.menu_item" class="dot">•</span>
                            <span v-if="showMenuItemInfo && r.menu_item" class="dish-name">{{ r.menu_item.name }}</span>
                        </div>
                    </div>
                </div>

                <p v-if="r.comment" class="review-comment">{{ r.comment }}</p>

                <div v-if="r.photos && r.photos.length > 0" class="review-photos">
                    <a v-for="p in r.photos" :key="p.id" :href="p.url" target="_blank" rel="noopener" class="photo">
                        <img :src="p.url" :alt="`Ảnh đánh giá #${p.id}`" loading="lazy" />
                    </a>
                </div>
            </li>
        </ul>

        <!-- Pagination -->
        <nav v-if="!isOwnProps && lastPage > 1" class="review-pagination" aria-label="Phân trang đánh giá">
            <button
                type="button"
                class="page-btn"
                :disabled="currentPage === 1 || loading"
                @click="loadPage(currentPage - 1)"
            >‹ Trước</button>
            <span class="page-info">{{ currentPage }} / {{ lastPage }}</span>
            <button
                type="button"
                class="page-btn"
                :disabled="currentPage === lastPage || loading"
                @click="loadPage(currentPage + 1)"
            >Sau ›</button>
        </nav>
    </div>
</template>

<style scoped>
.review-list { display: flex; flex-direction: column; gap: 20px; }

/* Summary */
.review-summary {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 32px;
    padding: 24px;
    background: var(--color-cream, #FDF8F0);
    border-radius: var(--radius-lg, 12px);
    border: 1px solid var(--color-gray-100, #f1f1f1);
}

.summary-avg {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding-right: 24px;
    border-right: 1px solid var(--color-gray-200, #e5e5e5);
}

.avg-number {
    font-size: 48px;
    font-weight: 800;
    color: var(--color-charcoal-black, #111);
    line-height: 1;
}

.avg-count {
    font-size: 13px;
    color: var(--color-gray-500, #6b7280);
}

.summary-breakdown {
    display: flex;
    flex-direction: column;
    gap: 8px;
    justify-content: center;
}

.breakdown-row {
    display: grid;
    grid-template-columns: 60px 1fr 40px;
    align-items: center;
    gap: 12px;
    font-size: 13px;
}

.breakdown-label {
    color: var(--color-gray-600, #4b5563);
    font-weight: 600;
}

.breakdown-bar {
    height: 8px;
    background: var(--color-gray-100, #f1f1f1);
    border-radius: 999px;
    overflow: hidden;
}

.breakdown-fill {
    height: 100%;
    background: linear-gradient(90deg, #FFB000 0%, #FF7A00 100%);
    transition: width 0.4s ease;
}

.breakdown-count {
    color: var(--color-gray-500, #6b7280);
    text-align: right;
}

/* Reviews */
.reviews {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.review-item {
    padding: 16px;
    background: var(--color-white, #fff);
    border: 1px solid var(--color-gray-100, #f1f1f1);
    border-radius: var(--radius-lg, 12px);
}

.review-head {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 8px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--color-chili-red, #D71920);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.meta {
    flex: 1;
    min-width: 0;
}

.name {
    font-weight: 700;
    color: var(--color-charcoal-black, #111);
    font-size: 14px;
}

.info {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-gray-500, #6b7280);
    margin-top: 4px;
    flex-wrap: wrap;
}

.dot { opacity: 0.5; }
.dish-name { font-weight: 600; color: var(--color-charcoal-black, #111); }

.review-comment {
    font-size: 14px;
    line-height: 1.5;
    color: var(--color-charcoal-black, #111);
    margin: 8px 0;
    white-space: pre-line;
    word-break: break-word;
}

.review-photos {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.photo {
    display: block;
    width: 88px;
    height: 88px;
    border-radius: var(--radius-md, 8px);
    overflow: hidden;
    border: 1px solid var(--color-gray-100, #f1f1f1);
    transition: transform 0.2s ease;
}

.photo:hover { transform: scale(1.04); }
.photo img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Empty / Loading */
.review-empty, .review-loading {
    text-align: center;
    padding: 32px 16px;
    color: var(--color-gray-500, #6b7280);
    font-size: 14px;
}
.review-empty svg { color: var(--color-gray-300, #d1d5db); margin-bottom: 8px; }
.review-error {
    padding: 16px;
    background: var(--color-error-bg, #fef2f2);
    color: var(--color-error, #dc2626);
    border-radius: var(--radius-md, 8px);
    font-size: 14px;
}

/* Pagination */
.review-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}

.page-btn {
    padding: 8px 16px;
    background: var(--color-white, #fff);
    border: 1px solid var(--color-gray-200, #e5e5e5);
    border-radius: var(--radius-md, 8px);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.page-btn:hover:not(:disabled) {
    border-color: var(--color-chili-red, #D71920);
    color: var(--color-chili-red, #D71920);
}
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-info { font-size: 13px; color: var(--color-gray-500, #6b7280); }

/* Compact (chỉ list, không summary) */
.review-list.compact .review-summary { display: none; }

/* Responsive */
@media (max-width: 640px) {
    .review-summary {
        grid-template-columns: 1fr;
        gap: 16px;
        padding: 16px;
    }
    .summary-avg {
        padding-right: 0;
        padding-bottom: 16px;
        border-right: none;
        border-bottom: 1px solid var(--color-gray-200, #e5e5e5);
    }
}
</style>