<script setup lang="ts">
/**
 * ReviewSection — section đánh giá cho 1 món ăn.
 * Bao gồm:
 *  - Summary (avg + breakdown)
 *  - List reviews
 *  - Nút "Viết đánh giá" / "Sửa đánh giá" cho khách đủ điều kiện
 *
 * Dùng cho FoodDetailModal (mở rộng) hoặc OrderDetailView.
 */
import { ref, onMounted } from 'vue'
import ReviewList from './ReviewList.vue'
import ReviewFormModal from './ReviewFormModal.vue'
import StarRating from './StarRating.vue'
import { fetchReviewEligibility, deleteReview } from '@/api/review'
import { useAuthStore } from '@/stores/auth'
import { useAppDialog } from '@/composables/useAppDialog'
import { useToast } from '@/composables/useStaffUtils'
import type { Review, ReviewEligibility } from '@/types/review'

const props = defineProps<{
    menuItem: { id: number; name: string; image_url?: string | null }
    /** Ẩn summary, chỉ list. Mặc định false. */
    compact?: boolean
}>()

const emit = defineEmits<{
    /** Bắn ra khi review thay đổi (để parent reload summary/list bên ngoài). */
    changed: []
}>()

const authStore = useAuthStore()
const appDialog = useAppDialog()
const toast = useToast()
const eligibility = ref<ReviewEligibility | null>(null)
const loadingEligibility = ref(false)
const showModal = ref(false)
const modalMode = ref<'create' | 'edit'>('create')
const refreshing = ref(false)
const listRef = ref<InstanceType<typeof ReviewList> | null>(null)

async function loadEligibility() {
    if (!authStore.isAuthenticated) {
        eligibility.value = null
        return
    }
    loadingEligibility.value = true
    try {
        eligibility.value = await fetchReviewEligibility(props.menuItem.id)
    } catch {
        eligibility.value = null
    } finally {
        loadingEligibility.value = false
    }
}

function openCreate() {
    modalMode.value = 'create'
    showModal.value = true
}

function openEdit() {
    modalMode.value = 'edit'
    showModal.value = true
}

async function handleDelete() {
    if (!eligibility.value?.existing_review) return
    if (!await appDialog.confirm({ title: 'Xóa đánh giá?', message: 'Đánh giá này sẽ bị xóa khỏi nhà hàng.', confirmLabel: 'Xóa đánh giá', tone: 'danger' })) return
    try {
        await deleteReview(eligibility.value.existing_review.id)
        await loadEligibility()
        listRef.value?.$el?.dispatchEvent(new Event('refresh'))
        // Trigger reload by re-mounting key in parent if needed
        refreshLocal()
        emit('changed')
    } catch {
        toast.error('Không thể xóa đánh giá. Vui lòng thử lại.')
    }
}

function refreshLocal() {
    refreshing.value = true
    setTimeout(() => { refreshing.value = false }, 100)
}

async function handleSubmitted(review: Review) {
    await loadEligibility()
    emit('changed')
}

onMounted(() => {
    loadEligibility()
})
</script>

<template>
    <section class="review-section">
        <header class="section-head">
            <div>
                <h3 class="section-title">Đánh giá từ khách hàng</h3>
                <p class="section-subtitle">Trải nghiệm thực tế từ những người đã thưởng thức món này</p>
            </div>

            <!-- Actions -->
            <div v-if="authStore.isAuthenticated && eligibility" class="actions">
                <template v-if="eligibility.existing_review">
                    <button type="button" class="btn btn-outline" @click="openEdit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                        Sửa đánh giá
                    </button>
                    <button type="button" class="btn btn-ghost-danger" @click="handleDelete">Xóa</button>
                </template>
                <template v-else-if="eligibility.can_review">
                    <button type="button" class="btn btn-primary" @click="openCreate">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        Viết đánh giá
                    </button>
                </template>
                <div v-else-if="!eligibility.can_review && !eligibility.has_reviewed" class="ineligible-hint">
                    Bạn cần hoàn thành đơn có món này để đánh giá.
                </div>
            </div>
            <div v-else-if="!authStore.isAuthenticated" class="actions">
                <a href="/dang-nhap" class="btn btn-outline">Đăng nhập để đánh giá</a>
            </div>
        </header>

        <!-- Review list -->
        <div :key="refreshing.toString()">
            <ReviewList
                ref="listRef"
                :menu-item-id="menuItem.id"
                :compact="compact"
                @changed="emit('changed')"
            />
        </div>

        <!-- Modal -->
        <ReviewFormModal
            v-model:visible="showModal"
            :menu-item="menuItem"
            :existing="modalMode === 'edit' && eligibility?.existing_review ? {
                id: eligibility.existing_review.id,
                rating: eligibility.existing_review.rating,
                comment: eligibility.existing_review.comment,
                photos: eligibility.existing_review.photos,
                user: { id: 0, name: '' },
                menu_item_id: menuItem.id,
                is_hidden: false,
                created_at: null,
                updated_at: null,
            } : null"
            @submitted="handleSubmitted"
        />
    </section>
</template>

<style scoped>
.review-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.section-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
}

.section-title {
    font-size: 22px;
    font-weight: 800;
    color: var(--color-charcoal-black, #111);
    margin: 0;
}

.section-subtitle {
    font-size: 14px;
    color: var(--color-gray-500, #6b7280);
    margin: 4px 0 0;
}

.actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: var(--radius-md, 8px);
    border: none;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-primary {
    background: var(--color-chili-red, #D71920);
    color: white;
    box-shadow: 0 4px 12px rgba(215, 25, 32, 0.3);
}
.btn-primary:hover {
    background: var(--color-dark-red, #A80F17);
    transform: translateY(-1px);
}

.btn-outline {
    background: var(--color-white, #fff);
    color: var(--color-charcoal-black, #111);
    border: 1px solid var(--color-gray-300, #d1d5db);
}
.btn-outline:hover {
    border-color: var(--color-chili-red, #D71920);
    color: var(--color-chili-red, #D71920);
}

.btn-ghost-danger {
    background: transparent;
    color: #B91C1C;
    border: 1px solid transparent;
}
.btn-ghost-danger:hover {
    background: #FEF2F2;
    border-color: #FECACA;
}

.ineligible-hint {
    font-size: 13px;
    color: var(--color-gray-500, #6b7280);
    background: var(--color-gray-50, #f9fafb);
    padding: 8px 12px;
    border-radius: var(--radius-md, 8px);
}

@media (max-width: 640px) {
    .section-head {
        flex-direction: column;
        align-items: stretch;
    }
    .actions { justify-content: flex-start; }
}
</style>
