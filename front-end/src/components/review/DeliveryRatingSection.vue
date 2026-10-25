<script setup lang="ts">
/**
 * DeliveryRatingSection — Phase 4: khách chấm điểm dịch vụ giao hàng.
 *
 * Props:
 *  - orderId:      id đơn ONLINE
 *  - rating:       điểm đã chấm (null = chưa chấm)
 *  - comment:      nhận xét đã chấm
 *  - ratedAt:      thời điểm chấm
 *  - photos:       ảnh đã chấm
 *  - shipperName:  tên shipper phục vụ đơn
 *
 * Emits:
 *  - submitted: đánh giá vừa gửi (payload mới)
 */
import { ref, computed, watch } from 'vue'
import StarRating from '@/components/review/StarRating.vue'
import { submitDeliveryRating } from '@/api/deliveryRating'
import type { DeliveryRating } from '@/types/deliveryRating'

const props = defineProps<{
    orderId: number
    rating?: number | null
    comment?: string | null
    ratedAt?: string | null
    photos?: { id: number; url: string }[]
    shipperName?: string | null
}>()

const emit = defineEmits<{
    submitted: [value: DeliveryRating]
}>()

const MAX_PHOTOS = 5
const MAX_SIZE_KB = 4096
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

const editing = ref(false)
const selected = ref(0)
const comment = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const hasRated = computed(() => (props.rating ?? 0) > 0)
const canSubmit = computed(() => selected.value >= 1 && !loading.value)

function reset() {
    selected.value = props.rating ?? 0
    comment.value = props.comment ?? ''
    files.value = []
    previews.value = []
    error.value = null
}

function startEdit() {
    reset()
    editing.value = true
}

function cancelEdit() {
    files.value.forEach((_, i) => {
        const url = previews.value[i]
        if (url) URL.revokeObjectURL(url)
    })
    files.value = []
    previews.value = []
    editing.value = false
    error.value = null
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

    const take = filtered.slice(0, Math.max(0, MAX_PHOTOS - files.value.length))
    files.value = [...files.value, ...take]
    previews.value = [...previews.value, ...take.map((f) => URL.createObjectURL(f))]
    input.value = ''
}

function removePhoto(idx: number) {
    const url = previews.value[idx]
    if (url) URL.revokeObjectURL(url)
    files.value.splice(idx, 1)
    previews.value.splice(idx, 1)
}

async function submit() {
    if (!canSubmit.value) return
    loading.value = true
    error.value = null
    try {
        const result = await submitDeliveryRating(props.orderId, {
            rating: selected.value,
            comment: comment.value.trim() || null,
            photos: files.value,
        })
        emit('submitted', result)
        editing.value = false
        files.value = []
        previews.value = []
    } catch (e: any) {
        const resp = e?.response?.data
        if (resp?.errors) {
            const first = Object.values(resp.errors)[0] as string[]
            error.value = first?.[0] || resp.message || 'Đã có lỗi xảy ra.'
        } else {
            error.value = resp?.message || 'Không gửi được đánh giá. Vui lòng thử lại.'
        }
    } finally {
        loading.value = false
    }
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return ''
    return new Date(iso).toLocaleString('vi-VN', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

watch(() => props.rating, (v) => {
    if (!editing.value) selected.value = v ?? 0
})
</script>

<template>
    <section class="dr-section" aria-labelledby="dr-title">
        <header class="dr-head">
            <div class="dr-head-text">
                <h2 id="dr-title" class="dr-title">Đánh giá dịch vụ giao hàng</h2>
                <p v-if="shipperName" class="dr-sub">Shipper: <strong>{{ shipperName }}</strong></p>
                <p v-else class="dr-sub">Cho biết trải nghiệm giao hàng của bạn.</p>
            </div>

            <!-- Đã chấm: hiển thị kết quả -->
            <div v-if="hasRated && !editing" class="dr-done">
                <StarRating :value="rating ?? 0" readonly size="md" />
                <span class="dr-done-date">{{ formatDate(ratedAt) }}</span>
            </div>
        </header>

        <!-- Chưa chấm: mời chấm -->
        <div v-if="!hasRated && !editing" class="dr-cta">
            <p class="dr-cta-text">Món ăn đã ổn rồi? Dành 10 giây để chấm điểm giúp nhà hàng cải thiện dịch vụ giao.</p>
            <button type="button" class="dr-btn dr-btn-primary" @click="startEdit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                </svg>
                Chấm điểm giao hàng
            </button>
        </div>

        <!-- Đã chấm + nút sửa -->
        <div v-if="hasRated && !editing" class="dr-cta">
            <p v-if="comment" class="dr-existing-comment">“{{ comment }}”</p>
            <div v-if="photos && photos.length" class="dr-existing-photos">
                <img v-for="p in photos" :key="p.id" :src="p.url" alt="Ảnh đánh giá giao hàng" loading="lazy" />
            </div>
            <button type="button" class="dr-btn dr-btn-ghost" @click="startEdit">Sửa đánh giá</button>
        </div>

        <!-- Form chấm -->
        <form v-if="editing" class="dr-form" @submit.prevent="submit">
            <div class="dr-field">
                <span class="dr-label">Điểm giao hàng <span class="dr-required">*</span></span>
                <div class="dr-rating-row">
                    <StarRating v-model="selected" size="lg" />
                    <span class="dr-rating-hint">
                        {{ selected > 0 ? `${selected}/5` : 'Chạm để chọn số sao' }}
                    </span>
                </div>
                <ul class="dr-scale">
                    <li v-for="s in [{ v: 1, t: 'Rất tệ' }, { v: 2, t: 'Tệ' }, { v: 3, t: 'Bình thường' }, { v: 4, t: 'Tốt' }, { v: 5, t: 'Rất tốt' }]" :key="s.v" :class="{ active: selected === s.v }">
                        {{ s.v }}★ {{ s.t }}
                    </li>
                </ul>
            </div>

            <div class="dr-field">
                <label class="dr-label" for="dr-comment">Nhận xét (tùy chọn)</label>
                <textarea
                    id="dr-comment"
                    v-model="comment"
                    class="dr-textarea"
                    rows="3"
                    maxlength="500"
                    placeholder="Giao nhanh không? Món còn nóng? Shipper có thân thiện không?"
                ></textarea>
                <div class="dr-char-count">{{ comment.length }}/500</div>
            </div>

            <div class="dr-field">
                <span class="dr-label">Hình ảnh (tối đa {{ MAX_PHOTOS }})</span>
                <div v-if="previews.length" class="dr-photo-grid">
                    <div v-for="(url, i) in previews" :key="url" class="dr-photo-item">
                        <img :src="url" alt="Ảnh sẽ gửi" />
                        <button type="button" class="dr-photo-remove" aria-label="Xoá ảnh" @click="removePhoto(i)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                </div>
                <label v-if="previews.length < MAX_PHOTOS" class="dr-upload">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                        <circle cx="12" cy="13" r="4"></circle>
                    </svg>
                    <span>Thêm ảnh</span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="onFiles" />
                </label>
            </div>

            <p v-if="error" class="dr-error" role="alert">{{ error }}</p>

            <div class="dr-actions">
                <button type="button" class="dr-btn dr-btn-ghost" :disabled="loading" @click="cancelEdit">Huỷ</button>
                <button type="submit" class="dr-btn dr-btn-primary" :disabled="!canSubmit">
                    {{ loading ? 'Đang gửi…' : 'Gửi đánh giá' }}
                </button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.dr-section {
    background: var(--color-white, #fff);
    border: 1px solid var(--color-gray-100, #f3f4f6);
    border-radius: var(--radius-2xl, 20px);
    padding: 24px;
}

.dr-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.dr-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: var(--color-charcoal-black, #111);
    letter-spacing: -0.2px;
}

.dr-sub {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--color-gray-500, #6b7280);
}

.dr-done {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.dr-done-date {
    font-size: 12px;
    color: var(--color-gray-400, #9ca3af);
}

.dr-cta {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
}

.dr-cta-text {
    margin: 0;
    font-size: 14px;
    color: var(--color-gray-600, #4b5563);
    line-height: 1.55;
}

.dr-existing-comment {
    margin: 0;
    font-size: 14px;
    font-style: italic;
    color: var(--color-gray-700, #374151);
    line-height: 1.6;
    padding-left: 12px;
    border-left: 3px solid var(--color-golden-yellow, #f5b301);
}

.dr-existing-photos {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.dr-existing-photos img {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: var(--radius-md, 10px);
    border: 1px solid var(--color-gray-200, #e5e7eb);
}

.dr-form {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.dr-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.dr-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-charcoal-black, #111);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.dr-required {
    color: var(--color-chili-red, #D71920);
}

.dr-rating-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.dr-rating-hint {
    font-size: 13px;
    color: var(--color-gray-500, #6b7280);
    font-weight: 600;
}

.dr-scale {
    list-style: none;
    margin: 2px 0 0;
    padding: 0;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.dr-scale li {
    font-size: 11px;
    padding: 4px 9px;
    border-radius: 999px;
    background: var(--color-gray-50, #f9fafb);
    color: var(--color-gray-500, #6b7280);
    border: 1px solid transparent;
    transition: all 0.15s ease;
}

.dr-scale li.active {
    background: rgba(215, 25, 32, 0.08);
    color: var(--color-chili-red, #D71920);
    border-color: rgba(215, 25, 32, 0.25);
    font-weight: 700;
}

.dr-textarea {
    width: 100%;
    border: 1px solid var(--color-gray-200, #e5e7eb);
    border-radius: var(--radius-md, 10px);
    padding: 12px 14px;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.6;
    color: var(--color-charcoal-black, #111);
    background: var(--color-white, #fff);
    resize: vertical;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.dr-textarea:focus {
    outline: none;
    border-color: var(--color-chili-red, #D71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.1);
}

.dr-char-count {
    font-size: 11px;
    color: var(--color-gray-400, #9ca3af);
    text-align: right;
}

.dr-photo-grid {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.dr-photo-item {
    position: relative;
    width: 76px;
    height: 76px;
}

.dr-photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: var(--radius-md, 10px);
    border: 1px solid var(--color-gray-200, #e5e7eb);
}

.dr-photo-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: none;
    background: var(--color-chili-red, #D71920);
    color: #fff;
    cursor: pointer;
    display: grid;
    place-items: center;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.dr-photo-remove:hover {
    background: #b3161c;
}

.dr-upload {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: 1px dashed var(--color-gray-300, #d1d5db);
    border-radius: var(--radius-md, 10px);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-600, #4b5563);
    cursor: pointer;
    transition: all 0.15s ease;
}

.dr-upload:hover {
    border-color: var(--color-chili-red, #D71920);
    color: var(--color-chili-red, #D71920);
    background: rgba(215, 25, 32, 0.04);
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.dr-error {
    margin: 0;
    font-size: 13px;
    color: var(--color-chili-red, #D71920);
    background: rgba(215, 25, 32, 0.06);
    border-left: 3px solid var(--color-chili-red, #D71920);
    padding: 10px 12px;
    border-radius: 0 var(--radius-sm, 8px) var(--radius-sm, 8px) 0;
}

.dr-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.dr-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: var(--radius-md, 10px);
    font-family: inherit;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    min-height: 44px;
}

.dr-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.dr-btn:focus-visible {
    outline: 2px solid var(--color-chili-red, #D71920);
    outline-offset: 2px;
}

.dr-btn-primary {
    background: var(--color-chili-red, #D71920);
    color: #fff;
}

.dr-btn-primary:not(:disabled):hover {
    background: #b3161c;
    transform: translateY(-1px);
}

.dr-btn-ghost {
    background: transparent;
    color: var(--color-gray-600, #4b5563);
    border-color: var(--color-gray-300, #d1d5db);
}

.dr-btn-ghost:not(:disabled):hover {
    border-color: var(--color-gray-400, #9ca3af);
    background: var(--color-gray-50, #f9fafb);
}

@media (max-width: 640px) {
    .dr-section {
        padding: 18px;
    }
    .dr-head {
        flex-direction: column;
    }
    .dr-done {
        align-items: flex-start;
    }
    .dr-actions {
        flex-direction: column-reverse;
    }
    .dr-btn {
        width: 100%;
    }
}
</style>
