<template>
    <dialog ref="dialogEl" class="blog-modal" @cancel="cancel" @click="backdrop">
        <form class="modal-form" @submit.prevent="submit">
            <header class="modal-head">
                <div>
                    <span class="eyebrow">{{ editingId ? 'CẬP NHẬT' : 'TẠO MỚI' }}</span>
                    <h3>{{ editingId ? 'Sửa bài viết' : 'Thêm bài viết' }}</h3>
                </div>
                <button type="button" class="icon-btn" :disabled="saving" aria-label="Đóng" @click="close">×</button>
            </header>

            <div class="modal-body">
                <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

                <label>
                    Tiêu đề *
                    <input v-model="form.title" required maxlength="250" :disabled="saving" />
                </label>

                <div class="grid-2">
                    <label>
                        Slug (tuỳ chọn)
                        <input
                            v-model="form.slug"
                            maxlength="220"
                            placeholder="Tự sinh nếu bỏ trống"
                            :disabled="saving"
                        />
                        <small>Chữ thường, số, gạch ngang — dùng cho URL /bai-viet/&lt;slug&gt;.</small>
                    </label>
                    <label>
                        Tóm tắt (excerpt)
                        <textarea
                            v-model="form.excerpt"
                            maxlength="500"
                            rows="2"
                            :disabled="saving"
                            placeholder="Hiển thị ở danh sách tin (tuỳ chọn)"
                        />
                    </label>
                </div>

                <!-- Cover image upload -->
                <div class="cover-upload">
                    <label>Ảnh bìa</label>
                    <div class="cover-row">
                        <div class="cover-preview" :class="{ 'has-image': form.cover_image }">
                            <img v-if="form.cover_image" :src="form.cover_image" alt="cover" />
                            <span v-else class="cover-placeholder">Chưa có ảnh bìa</span>
                        </div>
                        <div class="cover-actions">
                            <label class="upload-btn" :class="{ disabled: uploading || saving }">
                                {{ uploading ? 'Đang tải…' : form.cover_image ? 'Đổi ảnh' : 'Tải ảnh lên' }}
                                <input
                                    ref="coverInputRef"
                                    type="file"
                                    accept="image/*"
                                    :disabled="uploading || saving"
                                    @change="onCoverSelected"
                                />
                            </label>
                            <button
                                v-if="form.cover_image"
                                type="button"
                                class="btn ghost xs"
                                :disabled="uploading || saving"
                                @click="form.cover_image = ''"
                            >
                                Xoá ảnh
                            </button>
                            <small class="hint">Tối đa 2MB · jpg, png, webp, gif, avif</small>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <label>
                        Danh mục
                        <select v-model="form.category" :disabled="saving">
                            <option value="NEWS">Tin mới</option>
                            <option value="PROMOTION">Tin khuyến mãi</option>
                            <option value="NEW_DISH">Món mới</option>
                            <option value="EVENT">Sự kiện</option>
                            <option value="CULINARY">Cẩm nang ẩm thực</option>
                        </select>
                    </label>
                    <label>
                        Hiển thị ở mục (news_section)
                        <select v-model="form.news_section" :disabled="saving">
                            <option value="NEWS">Tin mới</option>
                            <option value="OPENING">Tin khai trương</option>
                            <option value="PROMOTION">Tin khuyến mãi</option>
                        </select>
                    </label>
                </div>

                <label class="content-label">
                    Nội dung *
                    <div class="editor-wrapper">
                        <!--
                          :key="editingId ?? 'new'" buộc TinyMCE remount khi đổi bài
                          (Editor Vue cache lại instance, addOnManager cache lại plugin
                          licensekeymanager từ session cũ). Khi đổi blog hoặc đóng/mở
                          modal, Editor sẽ tạo lại từ đầu → đọc lại license_key mới nhất.
                        -->
                        <Editor
                            :key="editingId ?? 'new'"
                            v-model="form.content"
                            :init="editorInit"
                            :disabled="saving"
                        />
                    </div>
                </label>

                <div class="grid-2">
                    <label>
                        Trạng thái
                        <select v-model="form.status" :disabled="saving">
                            <option value="DRAFT">Bản nháp (chưa hiện public)</option>
                            <option value="PUBLISHED">Xuất bản</option>
                            <option value="ARCHIVED">Lưu trữ</option>
                        </select>
                    </label>
                </div>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn ghost" :disabled="saving" @click="close">Huỷ</button>
                <button type="submit" class="btn primary" :disabled="saving || !form.title.trim() || !form.content.trim() || contentTooShort">
                    {{ saving ? 'Đang lưu…' : editingId ? 'Cập nhật' : 'Tạo bài viết' }}
                </button>
            </footer>
        </form>
    </dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Editor from '@tinymce/tinymce-vue'
// Import trực tiếp TinyMCE 8.x local → self-host (tránh popup "Get TinyMCE API Key"
// của Tiny Cloud, và không phụ thuộc CDN ngoài).
import 'tinymce/tinymce'
import 'tinymce/models/dom'
// Theme + skin + plugins cần dùng — import ở đây để Vite bundle chung vào output.
import 'tinymce/themes/silver'
import 'tinymce/skins/ui/oxide/skin.min.css'
import 'tinymce/plugins/link'
import 'tinymce/plugins/image'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/autolink'
import 'tinymce/plugins/charmap'
import 'tinymce/plugins/searchreplace'
import 'tinymce/plugins/wordcount'
import { uploadBlogImage } from '@/api/managerBlog'
import type { BlogPayload, ManagerBlog, BlogCategory, BlogSection, BlogStatus } from '@/api/managerBlog'

interface Props {
    blog: ManagerBlog | null
}
const props = defineProps<Props>()
const emit = defineEmits<{
    save: [payload: BlogPayload & { id?: number }]
    cancel: []
}>()

const dialogEl = ref<HTMLDialogElement | null>(null)
const saving = ref(false)
const formError = ref('')
const editingId = computed(() => props.blog?.id ?? null)

const coverInputRef = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

// TinyMCE init — self-host, toolbar gọn cho blog nhà hàng.
const editorInit = {
    height: 380,
    menubar: false,
    // Self-host: trỏ tới assets được Vite serve ở /tinymce/*
    // (dev: Vite middleware serve từ node_modules; prod: vite-static-copy copy sang dist/tinymce).
    base_url: '/tinymce',
    suffix: '.min',
    // TinyMCE 8.x license handling:
    //   - `license_key: 'gpl'` → dùng built-in GplLicenseKeyManager (không load plugin).
    //   - KHÔNG set `api_key` → onlineStatus = 'offline' → không cố tải plugin từ cloud.
    //   (Tiny Cloud luôn cố load `licensekeymanager` từ server khi có api_key → 404 nếu self-host.)
    license_key: 'gpl',
    promotion: false,
    branding: false,
    statusbar: true,
    elementpath: false,
    // TinyMCE 8 self-host: KHÔNG set `language_url` để tránh lỗi 404 trên production
    // (Vite bundle khác với việc serve nguyên thư mục node_modules). Để mặc định English —
    // admin thường quen các nhãn Save/Insert/Bold/Italic/Link/Image.
    plugins: 'link image lists autolink charmap searchreplace wordcount',
    toolbar:
        'undo redo | blocks | bold italic underline | bullist numlist | link image | removeformat | wordcount',
    images_upload_handler: async (blob: Blob): Promise<string> => {
        try {
            const file = new File([blob], 'inline-' + Date.now() + '.jpg', { type: blob.type })
            const r = await uploadBlogImage(file)
            return r.url
        } catch (e: any) {
            // TinyMCE hiển thị alert nếu throw Error.
            throw new Error(e?.response?.data?.message || 'Upload ảnh thất bại.')
        }
    },
    file_picker_types: 'image',
    convert_urls: false,
    // Không ép rel noopener cho link an toàn.
    default_link_target: '_blank',
    link_rel_list: [{ title: 'None', value: '' }, { title: 'noopener', value: 'noopener' }],
}

const contentTooShort = computed(() => {
    // Server yêu cầu content tối thiểu ~20 ký tự (sau khi strip HTML).
    const text = (form.content || '').replace(/<[^>]+>/g, '').trim()
    return text.length < 20
})

function blankForm(): BlogPayload {
    return {
        title: '',
        slug: '',
        excerpt: '',
        content: '',
        cover_image: '',
        category: 'NEWS' as BlogCategory,
        news_section: 'NEWS' as BlogSection,
        status: 'DRAFT' as BlogStatus,
    }
}

const form = reactive<BlogPayload>(blankForm())

function reset(): void {
    Object.assign(form, blankForm())
    formError.value = ''
}

function loadFrom(b: ManagerBlog | null): void {
    reset()
    if (!b) return
    form.title = b.title
    form.slug = b.slug
    form.excerpt = b.excerpt ?? ''
    form.content = b.content ?? ''
    form.cover_image = b.cover_image ?? ''
    form.category = b.category
    form.news_section = b.news_section
    form.status = b.status
}

watch(
    () => props.blog,
    (b) => loadFrom(b),
    { immediate: true },
)

async function onCoverSelected(e: Event) {
    const input = e.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return
    if (file.size > 2 * 1024 * 1024) {
        formError.value = 'Ảnh vượt quá 2MB.'
        return
    }
    uploading.value = true
    formError.value = ''
    try {
        const r = await uploadBlogImage(file)
        form.cover_image = r.url
    } catch (err: any) {
        formError.value = err?.response?.data?.message || 'Upload ảnh thất bại.'
    } finally {
        uploading.value = false
        if (coverInputRef.value) coverInputRef.value.value = ''
    }
}

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
    if (!form.title.trim() || !form.content.trim()) {
        formError.value = 'Tiêu đề và Nội dung là bắt buộc.'
        return
    }
    saving.value = true
    const payload: BlogPayload = {
        title: form.title.trim(),
        slug: (form.slug ?? '').trim() || null,
        excerpt: (form.excerpt ?? '').trim() || null,
        content: form.content,
        cover_image: (form.cover_image ?? '').trim() || null,
        category: form.category,
        news_section: form.news_section,
        status: form.status,
    }
    emit('save', { ...payload, id: editingId.value } as BlogPayload & { id?: number })
}

defineExpose({ open, close })
</script>

<style scoped>
.blog-modal {
    border: none;
    border-radius: 16px;
    padding: 0;
    background: transparent;
    width: min(820px, calc(100vw - 32px));
    color: #1a1a1a;
    max-height: calc(100vh - 32px);
}
.blog-modal::backdrop {
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
    color: var(--color-chili-red, #d71920);
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
.modal-body input,
.modal-body textarea,
.modal-body select {
    padding: 9px 11px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    font-size: 13px;
    background: #fff;
}
.modal-body textarea {
    resize: vertical;
    min-height: 60px;
}
.modal-body input:focus,
.modal-body textarea:focus,
.modal-body select:focus {
    outline: none;
    border-color: var(--color-chili-red, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.modal-body small {
    color: #6b5f54;
    font-size: 11px;
    font-weight: 500;
}

/* ===== Cover image upload ===== */
.cover-upload {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cover-upload > label {
    font-size: 12px;
    font-weight: 600;
    color: rgba(26, 14, 6, 0.85);
}
.cover-row {
    display: flex;
    gap: 14px;
    align-items: stretch;
}
.cover-preview {
    width: 180px;
    height: 120px;
    border-radius: 10px;
    border: 2px dashed rgba(26, 14, 6, 0.15);
    background: #fafafa;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cover-preview.has-image {
    border-style: solid;
    border-color: transparent;
}
.cover-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.cover-placeholder {
    color: #6b5f54;
    font-size: 12px;
    text-align: center;
    padding: 0 8px;
}
.cover-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
    justify-content: center;
    flex: 1;
}
.upload-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 14px;
    border-radius: 8px;
    background: var(--color-chili-red, #d71920);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    align-self: flex-start;
    transition: background 0.12s;
}
.upload-btn:hover:not(.disabled) {
    background: #b91518;
}
.upload-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.upload-btn input[type='file'] {
    display: none;
}
.cover-actions .hint {
    color: #6b5f54;
    font-size: 11px;
}
.btn.ghost.xs {
    background: transparent;
    border: 1px solid rgba(26, 14, 6, 0.12);
    color: #2a221c;
    padding: 6px 10px;
    font-size: 11px;
    border-radius: 6px;
    cursor: pointer;
    align-self: flex-start;
}

/* ===== Editor wrapper ===== */
.content-label {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.editor-wrapper {
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}
.editor-wrapper :deep(.tox-tinymce) {
    border: 0 !important;
    border-radius: 8px;
}
.editor-wrapper :deep(.tox-toolbar) {
    background: #fafafa !important;
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
    .grid-2 {
        grid-template-columns: 1fr;
    }
    .cover-row {
        flex-direction: column;
    }
    .cover-preview {
        width: 100%;
    }
}
</style>