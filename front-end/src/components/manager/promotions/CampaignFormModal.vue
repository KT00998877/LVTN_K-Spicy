<template>
    <dialog ref="dialogEl" class="campaign-modal" @cancel="cancel" @click="backdrop">
        <form class="modal-form" @submit.prevent="submit">
            <header class="modal-head">
                <div>
                    <span class="eyebrow">{{ editingId ? 'CẬP NHẬT' : 'TẠO MỚI' }}</span>
                    <h3>{{ editingId ? 'Sửa chiến dịch' : 'Thêm chiến dịch' }}</h3>
                </div>
                <button type="button" class="icon-btn" :disabled="saving" aria-label="Đóng" @click="close">
                    ×
                </button>
            </header>

            <div class="modal-body">
                <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

                <div class="grid-2">
                    <label>
                        Tên chiến dịch
                        <input v-model="form.name" required maxlength="150" :disabled="saving" />
                    </label>
                    <label>
                        Slug (tuỳ chọn)
                        <input
                            v-model="form.slug"
                            maxlength="120"
                            placeholder="Tự sinh nếu bỏ trống"
                            :disabled="saving"
                        />
                        <small>Chữ thường, số, gạch ngang — dùng làm URL /tin-vui/tin-khuyen-mai/&lt;slug&gt;.</small>
                    </label>
                </div>

                <label>
                    Mô tả
                    <textarea
                        v-model="form.description"
                        maxlength="2000"
                        rows="3"
                        :disabled="saving"
                        placeholder="Mô tả ngắn cho trang chiến dịch (tuỳ chọn)"
                    />
                </label>

                <label>
                    Ảnh bìa (URL)
                    <input
                        v-model="form.cover_image_url"
                        type="url"
                        maxlength="500"
                        placeholder="https://… hoặc /storage/campaigns/anh-bia.jpg"
                        :disabled="saving"
                    />
                </label>

                <div class="grid-2">
                    <label>
                        Bắt đầu
                        <input v-model="form.starts_at" type="datetime-local" :disabled="saving" />
                    </label>
                    <label>
                        Kết thúc
                        <input v-model="form.ends_at" type="datetime-local" :disabled="saving" />
                    </label>
                </div>

                <label class="row">
                    <input type="checkbox" v-model="form.is_active" :disabled="saving" />
                    Kích hoạt ngay
                </label>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn ghost" :disabled="saving" @click="close">Huỷ</button>
                <button type="submit" class="btn primary" :disabled="saving || !form.name.trim()">
                    {{ saving ? 'Đang lưu…' : editingId ? 'Cập nhật' : 'Tạo chiến dịch' }}
                </button>
            </footer>
        </form>
    </dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { CampaignPayload, ManagerCampaignDetail } from '@/types/campaign'

interface Props {
    campaign: ManagerCampaignDetail | null
}
const props = defineProps<Props>()
const emit = defineEmits<{
    save: [CampaignPayload & { id?: number }]
    cancel: []
}>()

const dialogEl = ref<HTMLDialogElement | null>(null)
const saving = ref(false)
const formError = ref('')
const editingId = computed(() => props.campaign?.id ?? null)

function blankForm(): CampaignPayload {
    return {
        name: '',
        slug: '',
        description: null,
        cover_image_url: null,
        starts_at: null,
        ends_at: null,
        is_active: true,
    }
}

const form = reactive<CampaignPayload>(blankForm())

function reset(): void {
    Object.assign(form, blankForm())
    formError.value = ''
}

function loadFrom(c: ManagerCampaignDetail | null): void {
    reset()
    if (!c) return
    form.name = c.name
    form.slug = c.slug
    form.description = c.description
    form.cover_image_url = c.cover_image_url
    form.starts_at = c.starts_at
    form.ends_at = c.ends_at
    form.is_active = c.is_active
}

watch(
    () => props.campaign,
    (c) => loadFrom(c),
    { immediate: true },
)

function open(): void {
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
    if (!form.name.trim()) {
        formError.value = 'Nhập tên chiến dịch.'
        return
    }
    saving.value = true
    const payload = { ...form }
    if (!payload.slug) delete payload.slug
    emit('save', { ...payload, id: editingId.value } as CampaignPayload & { id?: number })
}

defineExpose({ open, close })
</script>

<style scoped>
.campaign-modal {
    border: none;
    border-radius: 16px;
    padding: 0;
    background: transparent;
    width: min(560px, calc(100vw - 32px));
    color: #1a1a1a;
}
.campaign-modal::backdrop {
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
    align-items: flex-end;
    justify-content: space-between;
    padding: 18px 22px 12px;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
}
.eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
    margin-bottom: 4px;
}
.modal-head h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
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
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.modal-body label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
}
.modal-body label.row {
    flex-direction: row;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}
.modal-body input,
.modal-body textarea {
    padding: 8px 10px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.modal-body textarea {
    resize: vertical;
    min-height: 64px;
}
.modal-body input:focus,
.modal-body textarea:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.modal-body small {
    color: #6b5f54;
    font-size: 11px;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--chili, #d71920);
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
    background: var(--chili, #d71920);
    color: #fff;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
@media (max-width: 600px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>
