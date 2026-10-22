<script setup lang="ts">
/**
 * ReviewFormModal — modal tạo/sửa review.
 *
 * Props:
 *  - visible: trạng thái hiển thị
 *  - menuItem: thông tin món ăn
 *  - existing: review hiện có (nếu sửa)
 *
 * Emits:
 *  - update:visible
 *  - submitted: review vừa tạo/cập nhật
 */
import { ref, computed, watch } from 'vue'
import StarRating from './StarRating.vue'
import { createReview, updateReview, fetchReviewEligibility } from '@/api/review'
import type { Review, ReviewEligibility } from '@/types/review'

const props = defineProps<{
    visible: boolean
    menuItem: { id: number; name: string; image_url?: string | null } | null
    existing?: Review | null
}>()

const emit = defineEmits<{
    'update:visible': [v: boolean]
    'submitted': [review: Review]
}>()

const rating = ref<number>(0)
const comment = ref<string>('')
const photos = ref<File[]>([])
const previews = ref<string[]>([])
const existingPhotoUrls = ref<string[]>([])
const removeExistingPhotos = ref<boolean>(false)
const loading = ref(false)
const error = ref<string | null>(null)
const eligibility = ref<ReviewEligibility | null>(null)
const loadingEligibility = ref(false)

const MAX_PHOTOS = 5
const MAX_SIZE_KB = 4096
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

const title = computed(() => props.existing ? 'Chỉnh sửa đánh giá' : 'Đánh giá món ăn')

const canSubmit = computed(() => rating.value >= 1 && !loading.value)
const totalPhotos = computed(() => {
    return (removeExistingPhotos.value ? 0 : existingPhotoUrls.value.length) + photos.value.length
})

function reset() {
    rating.value = 0
    comment.value = ''
    photos.value = []
    previews.value = []
    existingPhotoUrls.value = []
    removeExistingPhotos.value = false
    error.value = null
    eligibility.value = null
}

function close() {
    if (loading.value) return
    emit('update:visible', false)
}

function onFiles(e: Event) {
    const input = e.target as HTMLInputElement
    const list = input.files ? Array.from(input.files) : []
    error.value = null

    const filtered = list.filter((f) => {
        if (!ALLOWED_TYPES.includes(f.type)) {
            error.value = 'Ảnh phải có định dạng JPG, PNG hoặc WEBP.'
            return false
        }
        if (f.size > MAX_SIZE_KB * 1024) {
            error.value = `Ảnh "${f.name}" quá lớn (tối đa ${MAX_SIZE_KB}KB).`
            return false
        }
        return true
    })

    const remaining = MAX_PHOTOS - (removeExistingPhotos.value ? 0 : existingPhotoUrls.value.length)
    const take = filtered.slice(0, Math.max(0, remaining))

    photos.value = [...photos.value, ...take]
    previews.value = [...previews.value, ...take.map((f) => URL.createObjectURL(f))]
    input.value = ''
}

function removeNewPhoto(idx: number) {
    const url = previews.value[idx]
    if (url) URL.revokeObjectURL(url)
    photos.value.splice(idx, 1)
    previews.value.splice(idx, 1)
}

function removeOldPhoto() {
    existingPhotoUrls.value = []
    removeExistingPhotos.value = true
}

async function loadEligibility() {
    if (!props.menuItem || props.existing) return
    loadingEligibility.value = true
    try {
        eligibility.value = await fetchReviewEligibility(props.menuItem.id)
    } catch {
        // ignore
    } finally {
        loadingEligibility.value = false
    }
}

watch(() => props.visible, async (v) => {
    if (!v) return
    reset()
    if (props.existing) {
        rating.value = props.existing.rating
        comment.value = props.existing.comment || ''
        existingPhotoUrls.value = props.existing.photos?.map((p) => p.url) ?? []
    } else {
        await loadEligibility()
        if (eligibility.value?.existing_review) {
            rating.value = eligibility.value.existing_review.rating
            comment.value = eligibility.value.existing_review.comment || ''
            existingPhotoUrls.value = eligibility.value.existing_review.photos.map((p) => p.url)
        }
    }
})

async function submit() {
    if (!props.menuItem || !canSubmit.value) return
    loading.value = true
    error.value = null
    try {
        let result: Review
        if (props.existing) {
            // Update: nếu xóa hết ảnh cũ + không có ảnh mới → gửi photos=[] để giữ trống.
            // Ngược lại nếu giữ ảnh cũ → không gửi field photos.
            const wantKeepOld = !removeExistingPhotos.value && existingPhotoUrls.value.length > 0 && photos.value.length === 0
            const wantReplaceAll = !removeExistingPhotos.value && existingPhotoUrls.value.length > 0 && photos.value.length > 0
            // Backend: photos = [] rỗng = xóa hết. Không gửi = giữ nguyên.
            if (wantKeepOld) {
                result = await updateReview(props.existing.id, {
                    rating: rating.value,
                    comment: comment.value.trim() || null,
                }, true)
            } else if (wantReplaceAll) {
                // Backend hiện tại không hỗ trợ mix (giữ cũ + thêm mới) trong 1 request.
                // → Hướng dẫn user xóa cũ trước nếu muốn thay.
                // Đơn giản: thay hết = xóa cũ + gửi mới.
                removeExistingPhotos.value = true
                result = await updateReview(props.existing.id, {
                    rating: rating.value,
                    comment: comment.value.trim() || null,
                    photos: photos.value,
                }, false)
            } else {
                result = await updateReview(props.existing.id, {
                    rating: rating.value,
                    comment: comment.value.trim() || null,
                    photos: photos.value,
                }, false)
            }
        } else {
            result = await createReview({
                menu_item_id: props.menuItem.id,
                rating: rating.value,
                comment: comment.value.trim() || null,
                photos: photos.value,
            })
        }
        emit('submitted', result)
        emit('update:visible', false)
    } catch (e: any) {
        const resp = e?.response?.data
        if (resp?.errors) {
            const first = Object.values(resp.errors)[0] as string[]
            error.value = first?.[0] || resp.message || 'Đã có lỗi xảy ra.'
        } else {
            error.value = resp?.message || 'Đã có lỗi xảy ra.'
        }
    } finally {
        loading.value = false
    }
}

const ineligible = computed(() => {
    return !props.existing && eligibility.value && !eligibility.value.can_review && !eligibility.value.has_reviewed
})
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="visible" class="review-modal-overlay" @click.self="close" role="dialog" aria-modal="true" :aria-label="title">
                <div class="review-modal">
                    <header class="modal-header">
                        <h2>{{ title }}</h2>
                        <button type="button" class="close-btn" @click="close" aria-label="Đóng">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </header>

                    <div class="modal-body">
                        <!-- Dish info -->
                        <div v-if="menuItem" class="dish-info">
                            <img v-if="menuItem.image_url" :src="menuItem.image_url" :alt="menuItem.name" />
                            <div v-else class="dish-fallback">🍜</div>
                            <div>
                                <div class="dish-label">Đánh giá cho</div>
                                <div class="dish-name">{{ menuItem.name }}</div>
                            </div>
                        </div>

                        <!-- Ineligible warning -->
                        <div v-if="ineligible" class="warning">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <span>Bạn cần hoàn thành đơn có món này trước khi đánh giá.</span>
                        </div>

                        <template v-else>
                            <!-- Rating -->
                            <div class="field">
                                <label class="field-label">Điểm đánh giá <span class="required">*</span></label>
                                <div class="rating-input">
                                    <StarRating v-model="rating" size="lg" />
                                    <span class="rating-hint">{{ rating > 0 ? `${rating}/5` : 'Chạm để chọn số sao' }}</span>
                                </div>
                            </div>

                            <!-- Comment -->
                            <div class="field">
                                <label class="field-label" for="review-comment">Nhận xét (tùy chọn)</label>
                                <textarea
                                    id="review-comment"
                                    v-model="comment"
                                    class="comment-input"
                                    placeholder="Chia sẻ trải nghiệm của bạn về món ăn..."
                                    rows="4"
                                    maxlength="1000"
                                ></textarea>
                                <div class="char-count">{{ comment.length }}/1000</div>
                            </div>

                            <!-- Photos -->
                            <div class="field">
                                <label class="field-label">Hình ảnh (tối đa {{ MAX_PHOTOS }})</label>
                                <div class="photo-list">
                                    <!-- Existing photos -->
                                    <div v-for="(url, idx) in existingPhotoUrls" :key="`old-${idx}`" class="photo-thumb existing">
                                        <img :src="url" alt="" />
                                        <button type="button" class="remove-photo" @click="removeOldPhoto" aria-label="Xóa ảnh cũ">×</button>
                                        <span class="badge-old">Cũ</span>
                                    </div>

                                    <!-- New photos -->
                                    <div v-for="(preview, idx) in previews" :key="`new-${idx}`" class="photo-thumb">
                                        <img :src="preview" alt="" />
                                        <button type="button" class="remove-photo" @click="removeNewPhoto(idx)" aria-label="Xóa ảnh">×</button>
                                    </div>

                                    <!-- Add button -->
                                    <label v-if="totalPhotos < MAX_PHOTOS" class="photo-add">
                                        <input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="onFiles" />
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="12" y1="5" x2="12" y2="19"></line>
                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                        </svg>
                                        <span>Thêm ảnh</span>
                                    </label>
                                </div>
                                <div class="hint">JPG, PNG, WEBP. Tối đa 4MB mỗi ảnh.</div>
                            </div>

                            <!-- Error -->
                            <div v-if="error" class="error-msg" role="alert">{{ error }}</div>
                        </template>
                    </div>

                    <footer class="modal-footer">
                        <button type="button" class="btn btn-ghost" @click="close" :disabled="loading">Hủy</button>
                        <button
                            v-if="!ineligible"
                            type="button"
                            class="btn btn-primary"
                            :disabled="!canSubmit"
                            @click="submit"
                        >
                            <span v-if="loading">Đang gửi...</span>
                            <span v-else>{{ existing ? 'Lưu thay đổi' : 'Gửi đánh giá' }}</span>
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.review-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17, 17, 17, 0.7);
    backdrop-filter: blur(6px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.review-modal {
    background: var(--color-white, #fff);
    width: 100%;
    max-width: 560px;
    max-height: 92vh;
    border-radius: var(--radius-xl, 16px);
    overflow: hidden;
    box-shadow: var(--shadow-xl, 0 20px 60px rgba(0,0,0,0.3));
    display: flex;
    flex-direction: column;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--color-gray-100, #f1f1f1);
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: var(--color-charcoal-black, #111);
}

.close-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--color-gray-100, #f1f1f1);
    border: none;
    color: var(--color-gray-600, #4b5563);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.close-btn:hover { background: var(--color-charcoal-black, #111); color: white; }

.modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    background: var(--color-cream, #FDF8F0);
    border-top: 1px solid var(--color-gray-100, #f1f1f1);
}

/* Dish info */
.dish-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--color-cream, #FDF8F0);
    border-radius: var(--radius-lg, 12px);
}
.dish-info img {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-md, 8px);
    object-fit: cover;
}
.dish-fallback {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-md, 8px);
    background: var(--color-gray-100, #f1f1f1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}
.dish-label {
    font-size: 11px;
    color: var(--color-gray-500, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.dish-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111);
    margin-top: 2px;
}

/* Fields */
.field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.field-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111);
}
.required { color: var(--color-chili-red, #D71920); }

.rating-input {
    display: flex;
    align-items: center;
    gap: 16px;
}
.rating-hint {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-gray-500, #6b7280);
}

.comment-input {
    width: 100%;
    padding: 12px;
    border: 2px solid var(--color-gray-200, #e5e5e5);
    border-radius: var(--radius-md, 8px);
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    background: var(--color-white, #fff);
    color: var(--color-charcoal-black, #111);
    transition: border-color 0.2s ease;
}
.comment-input:focus {
    outline: none;
    border-color: var(--color-chili-red, #D71920);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.1);
}

.char-count {
    font-size: 12px;
    color: var(--color-gray-400, #9ca3af);
    text-align: right;
}

/* Photos */
.photo-list {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.photo-thumb {
    position: relative;
    width: 88px;
    height: 88px;
    border-radius: var(--radius-md, 8px);
    overflow: hidden;
    border: 2px solid var(--color-gray-200, #e5e5e5);
}
.photo-thumb img { width: 100%; height: 100%; object-fit: cover; }
.photo-thumb.existing { border-color: var(--color-golden, #FFB000); }

.remove-photo {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    border: none;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

.badge-old {
    position: absolute;
    bottom: 4px;
    left: 4px;
    background: rgba(255, 176, 0, 0.9);
    color: #111;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 999px;
    font-weight: 700;
}

.photo-add {
    width: 88px;
    height: 88px;
    border: 2px dashed var(--color-gray-300, #d1d5db);
    border-radius: var(--radius-md, 8px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    color: var(--color-gray-500, #6b7280);
    font-size: 11px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.photo-add:hover {
    border-color: var(--color-chili-red, #D71920);
    color: var(--color-chili-red, #D71920);
    background: rgba(215, 25, 32, 0.04);
}
.photo-add input { display: none; }

.hint {
    font-size: 12px;
    color: var(--color-gray-500, #6b7280);
}

/* Warning & Error */
.warning {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #FFF8E6;
    color: #B45309;
    border-radius: var(--radius-md, 8px);
    font-size: 14px;
    line-height: 1.5;
}
.error-msg {
    padding: 12px 16px;
    background: #FEF2F2;
    color: #B91C1C;
    border-radius: var(--radius-md, 8px);
    font-size: 14px;
}

/* Buttons */
.btn {
    padding: 12px 20px;
    border-radius: var(--radius-md, 8px);
    border: none;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 100px;
}
.btn-ghost {
    background: var(--color-white, #fff);
    color: var(--color-charcoal-black, #111);
    border: 1px solid var(--color-gray-200, #e5e5e5);
}
.btn-ghost:hover:not(:disabled) {
    border-color: var(--color-charcoal-black, #111);
}
.btn-primary {
    background: var(--color-chili-red, #D71920);
    color: white;
    box-shadow: 0 4px 12px rgba(215, 25, 32, 0.3);
}
.btn-primary:hover:not(:disabled) {
    background: var(--color-dark-red, #A80F17);
    transform: translateY(-1px);
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Transition */
.modal-enter-active, .modal-leave-active { transition: opacity 0.25s ease; }
.modal-enter-active .review-modal, .modal-leave-active .review-modal {
    transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-from .review-modal { transform: translateY(20px) scale(0.96); }
.modal-leave-to .review-modal { transform: translateY(20px) scale(0.96); }

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .modal-enter-active, .modal-leave-active { transition: none; }
    .modal-enter-active .review-modal, .modal-leave-active .review-modal { transition: none; }
}
</style>