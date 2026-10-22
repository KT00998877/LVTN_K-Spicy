<template>
    <dialog ref="dialogEl" class="version-modal" @cancel="cancel" @click="backdrop">
        <form class="modal-form" @submit.prevent="submit">
            <header class="modal-head">
                <div>
                    <span class="eyebrow">SỬA VERSION #{{ version?.version_number }}</span>
                    <h3>Chỉnh sửa nội dung do AI sinh</h3>
                    <small class="hint">
                        Sửa xong, asset sẽ chuyển về <strong>IN_REVIEW</strong> để duyệt lại
                        (nếu trước đó đang APPROVED/SCHEDULED/PUBLISHED).
                    </small>
                </div>
                <button type="button" class="icon-btn" :disabled="saving" aria-label="Đóng" @click="close">
                    ×
                </button>
            </header>

            <div class="modal-body">
                <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

                <label>
                    Headline
                    <input
                        v-model="form.headline"
                        type="text"
                        maxlength="255"
                        :disabled="saving"
                        placeholder="Tiêu đề ngắn gọn (1 dòng)"
                    />
                </label>

                <label>
                    Caption
                    <textarea
                        v-model="form.caption"
                        maxlength="5000"
                        rows="5"
                        :disabled="saving"
                        placeholder="Caption đăng Facebook/Instagram/TikTok..."
                    />
                    <small>{{ (form.caption || '').length }} / 5000 ký tự</small>
                </label>

                <label>
                    Body (cho blog / email)
                    <textarea
                        v-model="form.body"
                        maxlength="50000"
                        rows="8"
                        :disabled="saving"
                        placeholder="Nội dung dài cho blog article hoặc email blast"
                    />
                    <small>{{ (form.body || '').length }} / 50000 ký tự</small>
                </label>

                <label>
                    Hashtags (mỗi tag 1 dòng, KHÔNG cần kèm dấu #)
                    <textarea
                        v-model="hashtagsText"
                        maxlength="2000"
                        :disabled="saving"
                        rows="3"
                        placeholder="VD:&#10;micayhanquoc&#10;khuyenmai&#10;koreanfood"
                    />
                    <small>{{ form.hashtags?.length ?? 0 }} hashtag</small>
                </label>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn ghost" :disabled="saving" @click="close">Huỷ</button>
                <button type="submit" class="btn primary" :disabled="saving">
                    {{ saving ? 'Đang lưu…' : 'Lưu nội dung' }}
                </button>
            </footer>
        </form>
    </dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { MarketingVersion } from '@/api/marketing'

interface Props {
    version: MarketingVersion | null
}
const props = defineProps<Props>()
const emit = defineEmits<{
    save: [payload: { headline: string; caption: string; body: string; hashtags: string[] }]
    cancel: []
}>()

const dialogEl = ref<HTMLDialogElement | null>(null)
const saving = ref(false)
const formError = ref('')

function blankForm() {
    return {
        headline: '',
        caption: '',
        body: '',
        hashtags: [] as string[],
    }
}

const form = reactive(blankForm())

// Hashtags hiển thị dạng text mỗi dòng 1 tag cho dễ sửa; convert khi lưu.
const hashtagsText = computed({
    get: () => (form.hashtags ?? []).join('\n'),
    set: (v: string) => {
        form.hashtags = v
            .split(/[\n,]+/)
            .map((t) => t.trim().replace(/^#+/, ''))
            .filter((t) => t.length > 0)
            .slice(0, 30)
    },
})

watch(
    () => props.version,
    (v) => {
        if (!v) return
        form.headline = v.headline ?? ''
        form.caption = v.caption ?? ''
        form.body = v.body ?? ''
        form.hashtags = Array.isArray(v.hashtags) ? [...v.hashtags] : []
    },
    { immediate: true },
)

function open(): void {
    formError.value = ''
    dialogEl.value?.showModal()
}
function close(): void {
    dialogEl.value?.close()
    emit('cancel')
}
function cancel(): void {
    close()
}
function backdrop(e: MouseEvent): void {
    if (e.target === dialogEl.value) close()
}

function submit(): void {
    formError.value = ''
    // Cho phép lưu cả khi 1 vài trường trống — manager muốn xoá caption cũng được.
    if (!form.headline.trim() && !form.caption.trim() && !form.body.trim()) {
        formError.value = 'Nhập ít nhất 1 trong 3 trường: Headline, Caption hoặc Body.'
        return
    }
    saving.value = true
    emit('save', {
        headline: form.headline.trim(),
        caption: form.caption.trim(),
        body: form.body.trim(),
        hashtags: [...(form.hashtags ?? [])],
    })
}

defineExpose({ open, close })
</script>

<style scoped>
.version-modal {
    border: none;
    border-radius: 16px;
    padding: 0;
    background: transparent;
    width: min(640px, calc(100vw - 32px));
    color: #1a1a1a;
}
.version-modal::backdrop {
    background: rgba(26, 14, 6, 0.55);
    backdrop-filter: blur(2px);
}
.modal-form {
    background: #fff;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 32px);
    overflow: hidden;
}
.modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 22px 14px;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
}
.modal-head h3 {
    margin: 4px 0 0;
    font-size: 18px;
    font-weight: 800;
}
.eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--color-chili-red, #d71920);
    margin-bottom: 4px;
}
.hint {
    display: block;
    margin-top: 6px;
    color: rgba(26, 14, 6, 0.65);
    font-size: 12px;
    line-height: 1.5;
}
.icon-btn {
    background: transparent;
    border: 0;
    font-size: 22px;
    line-height: 1;
    color: #6b5f54;
    cursor: pointer;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    flex-shrink: 0;
}
.icon-btn:hover {
    background: rgba(26, 14, 6, 0.06);
}
.modal-body {
    padding: 18px 22px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.modal-body label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(26, 14, 6, 0.85);
}
.modal-body input,
.modal-body textarea {
    padding: 9px 11px;
    border: 1px solid rgba(26, 14, 6, 0.16);
    border-radius: 8px;
    font: inherit;
    font-size: 13px;
    background: #fff;
    resize: vertical;
}
.modal-body input:focus,
.modal-body textarea:focus {
    outline: none;
    border-color: var(--color-chili-red, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.16);
}
.modal-body small {
    color: #6b5f54;
    font-size: 11px;
    font-weight: 500;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--color-chili-red, #d71920);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
    margin: 0;
}
.modal-foot {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding: 12px 22px;
    border-top: 1px solid rgba(26, 14, 6, 0.06);
    background: rgba(26, 14, 6, 0.02);
}
.btn {
    cursor: pointer;
    padding: 9px 16px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn.primary {
    background: var(--color-chili-red, #d71920);
    color: #fff;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
@media (max-width: 600px) {
    .version-modal {
        width: calc(100vw - 16px);
    }
}
</style>