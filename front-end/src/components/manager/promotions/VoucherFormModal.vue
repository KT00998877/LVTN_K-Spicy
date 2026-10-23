<template>
    <dialog ref="dialogEl" class="voucher-modal" @cancel="cancel" @click="backdrop">
        <form class="modal-form" @submit.prevent="submit">
            <header class="modal-head">
                <div>
                    <span class="eyebrow">{{ editingId ? 'CẬP NHẬT' : 'TẠO MỚI' }}</span>
                    <h3>{{ editingId ? 'Sửa mã giảm giá' : 'Thêm mã giảm giá' }}</h3>
                </div>
                <button type="button" class="icon-btn" :disabled="saving" aria-label="Đóng" @click="close">×</button>
            </header>

            <div class="modal-body">
                <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

                <div class="grid-2">
                        <label>
                            Mã voucher
                            <input
                                v-model="form.code"
                                placeholder="VD: CHAOGIO10"
                                maxlength="50"
                                required
                                :disabled="saving"
                                @input="onCodeInput"
                            />
                            <small>Chỉ chữ HOA, số, dấu gạch dưới hoặc ngang.</small>
                        </label>
                        <label>
                            Tên hiển thị
                            <input v-model="form.name" required maxlength="120" :disabled="saving" placeholder="VD: Giảm 10% đơn tối đa 50k" />
                        </label>
                    </div>

                <label>
                    Mô tả
                    <textarea v-model="form.description" maxlength="500" rows="2" :disabled="saving" placeholder="Mô tả ngắn cho khách (tuỳ chọn)" />
                </label>

                <fieldset class="grid-3" :disabled="saving">
                    <label>
                        Loại giảm
                        <select v-model="form.type">
                            <option value="PERCENT">Theo phần trăm (%)</option>
                            <option value="FIXED">Số tiền cố định (đ)</option>
                        </select>
                    </label>
                    <label>
                        Giá trị
                        <input v-model.number="form.value" type="number" min="0" step="1" required :disabled="saving" />
                        <small>{{ form.type === 'PERCENT' ? 'Từ 1 đến 100. Ví dụ 20 = 20%.' : 'Tối thiểu 1.000đ. Ví dụ 20000.' }}</small>
                    </label>
                    <label v-if="form.type === 'PERCENT'">
                        Trần giảm (đ)
                        <input v-model.number="form.max_discount_amount" type="number" min="0" step="1000" placeholder="Không giới hạn" :disabled="saving" />
                        <small>Giảm tối đa bao nhiêu đồng / đơn.</small>
                    </label>
                </fieldset>

                <fieldset class="grid-2">
                    <label>
                        Đơn tối thiểu (đ)
                        <input v-model.number="form.min_order_subtotal" type="number" min="0" step="1000" placeholder="0" :disabled="saving" />
                    </label>
                    <label>
                        Tổng lượt dùng
                        <input v-model.number="form.usage_limit" type="number" min="1" step="1" placeholder="Không giới hạn" :disabled="saving" />
                        <small v-if="editingId && originalUsedCount !== null && form.usage_limit && form.usage_limit < originalUsedCount" class="warn">
                            Đã có {{ originalUsedCount }} lượt dùng — không thể đặt thấp hơn.
                        </small>
                    </label>
                </fieldset>

                <fieldset class="grid-3">
                    <label>
                        Lượt / khách
                        <input v-model.number="form.usage_limit_per_user" type="number" min="1" step="1" placeholder="Không giới hạn" :disabled="saving" />
                    </label>
                    <label>
                        Bắt đầu
                        <input v-model="form.starts_at" type="datetime-local" :disabled="saving" />
                    </label>
                    <label>
                        Kết thúc
                        <input v-model="form.ends_at" type="datetime-local" :disabled="saving" />
                        <small v-if="form.ends_at && form.starts_at && form.ends_at < form.starts_at" class="warn">Ngày kết thúc phải sau ngày bắt đầu.</small>
                    </label>
                </fieldset>

                <label class="checkbox">
                    <input v-model="form.is_active" type="checkbox" :disabled="saving" />
                    Đang kích hoạt
                </label>

                <details class="hint">
                    <summary>Xem trước khách sẽ thấy</summary>
                    <div class="preview-card">
                        <div class="badge">{{ previewBadge }}</div>
                        <div>
                            <strong>{{ form.name || 'Tên mã voucher' }}</strong>
                            <small>{{ form.description || 'Mô tả ngắn mô tả quyền lợi của khách.' }}</small>
                            <small class="meta">
                                <span v-if="form.min_order_subtotal">Đơn từ {{ formatMoney(form.min_order_subtotal) }}</span>
                                <span v-if="form.ends_at">HSD: {{ formatDate(form.ends_at) }}</span>
                            </small>
                        </div>
                    </div>
                </details>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn ghost" :disabled="saving" @click="close">Huỷ</button>
                <button type="submit" class="btn primary" :disabled="!canSubmit || saving">
                    {{ saving ? 'Đang lưu…' : editingId ? 'Lưu thay đổi' : 'Tạo mã' }}
                </button>
            </footer>
        </form>
    </dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { ManagerVoucher, VoucherPayload } from '@/api/managerVoucher'

interface Props {
    /** Truyền voucher cần sửa; không truyền = tạo mới. */
    voucher?: ManagerVoucher | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
    (e: 'save', payload: VoucherPayload & { id?: number }): void
    (e: 'cancel'): void
}>()

const dialogEl = ref<HTMLDialogElement>()
const saving = ref(false)
const formError = ref('')

const originalUsedCount = computed<number | null>(() => props.voucher?.used_count ?? null)

const blankForm = () => ({
    code: '',
    name: '',
    description: '',
    type: 'PERCENT' as 'PERCENT' | 'FIXED',
    value: 10,
    max_discount_amount: null as number | null,
    min_order_subtotal: 0,
    usage_limit: null as number | null,
    usage_limit_per_user: null as number | null,
    starts_at: '',
    ends_at: '',
    is_active: true,
})

const form = reactive(blankForm())

const editingId = computed(() => props.voucher?.id ?? null)

watch(
    () => props.voucher,
    (v) => {
        if (!v) return
        form.code = v.code
        form.name = v.name
        form.description = v.description || ''
        form.type = v.type
        form.value = v.value
        form.max_discount_amount = v.max_discount_amount
        form.min_order_subtotal = v.min_order_subtotal || 0
        form.usage_limit = v.usage_limit
        form.usage_limit_per_user = v.usage_limit_per_user
        form.starts_at = toLocalInput(v.starts_at)
        form.ends_at = toLocalInput(v.ends_at)
        form.is_active = v.is_active
    },
    { immediate: true },
)

const previewBadge = computed(() => {
    if (form.type === 'PERCENT' && form.value > 0) {
        const n = Number(form.value)
        return `-${Number.isInteger(n) ? n : n.toFixed(1)}%`
    }
    if (form.type === 'FIXED' && form.value > 0) {
        return `-${formatMoney(form.value)}`
    }
    return '—'
})

const canSubmit = computed(() => {
    if (!form.code.trim() || !form.name.trim()) return false
    if (form.type === 'PERCENT' && (form.value <= 0 || form.value > 100)) return false
    if (form.type === 'FIXED' && form.value < 1000) return false
    if (form.ends_at && form.starts_at && form.ends_at < form.starts_at) return false
    return true
})

function onCodeInput(e: Event) {
    const target = e.target as HTMLInputElement
    form.code = target.value.toUpperCase().replace(/\s/g, '')
}

function open() {
    formError.value = ''
    dialogEl.value?.showModal()
}
function close() {
    if (saving.value) return
    dialogEl.value?.close()
    Object.assign(form, blankForm())
    emit('cancel')
}
function cancel(e: Event) {
    if (saving.value) {
        e.preventDefault()
        return
    }
    Object.assign(form, blankForm())
}
function backdrop(e: MouseEvent) {
    if (e.target === dialogEl.value) close()
}

function submit() {
    if (!canSubmit.value || saving.value) return
    saving.value = true
    try {
        const payload: VoucherPayload & { id?: number } = {
            code: form.code.trim(),
            name: form.name.trim(),
            description: form.description.trim() || null,
            type: form.type,
            value: Number(form.value),
            max_discount_amount: form.max_discount_amount != null && form.max_discount_amount > 0 ? Number(form.max_discount_amount) : null,
            min_order_subtotal: Number(form.min_order_subtotal) || 0,
            usage_limit: form.usage_limit != null && form.usage_limit > 0 ? Number(form.usage_limit) : null,
            usage_limit_per_user: form.usage_limit_per_user != null && form.usage_limit_per_user > 0 ? Number(form.usage_limit_per_user) : null,
            starts_at: form.starts_at ? toIso(form.starts_at) : null,
            ends_at: form.ends_at ? toIso(form.ends_at) : null,
            is_active: form.is_active,
        }
        if (editingId.value) payload.id = editingId.value
        emit('save', payload)
        dialogEl.value?.close()
    } finally {
        saving.value = false
    }
}

function toLocalInput(iso: string | null): string {
    if (!iso) return ''
    const d = new Date(iso)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function toIso(local: string): string {
    const d = new Date(local)
    return d.toISOString()
}

function formatMoney(n: number): string {
    return n.toLocaleString('vi-VN') + 'đ'
}

function formatDate(iso: string): string {
    const d = new Date(iso)
    return d.toLocaleDateString('vi-VN')
}

defineExpose({ open, close })
</script>

<style scoped>
/* ============ Tokens ============ */
.voucher-modal {
    width: min(720px, calc(100% - 32px));
    max-height: 92vh;
    padding: 0;
    border: 0;
    border-radius: 16px;
    background: var(--cream, #FAF6F1);
    color: var(--charcoal, #1A1A1A);
    font: 14px/1.5 'Inter', sans-serif;
    box-shadow: 0 24px 64px rgba(20, 12, 6, 0.25);
}
.voucher-modal::backdrop { background: rgba(26, 14, 6, 0.55); }

.modal-form { display: flex; flex-direction: column; max-height: inherit; }
.modal-head, .modal-foot {
    padding: 18px 24px;
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
}
.modal-head { border-bottom: 1px solid rgba(26, 14, 6, 0.08); }
.modal-foot { border-top: 1px solid rgba(26, 14, 6, 0.08); background: rgba(255, 255, 255, 0.6); }
.eyebrow { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 1.4px; color: var(--chili, #D71920); margin-bottom: 4px; }
.modal-head h3 { margin: 0; font-size: 18px; font-weight: 700; }

.modal-body { padding: 22px 24px; overflow: auto; display: flex; flex-direction: column; gap: 18px; }
.modal-body label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: #2a221c; }
.modal-body label small { font-weight: 400; color: #7a6e63; font-size: 11px; }
.modal-body label small.warn { color: var(--chili, #D71920); }
.modal-body input, .modal-body textarea, .modal-body select {
    padding: 11px 12px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
    color: inherit;
}
.modal-body input:focus, .modal-body textarea:focus, .modal-body select:focus {
    outline: none; border-color: var(--chili, #D71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; border: 0; padding: 0; margin: 0; }

.checkbox { flex-direction: row !important; align-items: center; gap: 10px !important; font-weight: 500; }
.checkbox input { accent-color: var(--chili, #D71920); width: 18px; height: 18px; }

.alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; }
.alert.error { background: rgba(215, 25, 32, 0.08); color: var(--chili, #D71920); }

.btn {
    cursor: pointer;
    padding: 10px 18px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
    transition: background 0.12s, transform 0.06s;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn:not(:disabled):active { transform: translateY(1px); }
.btn.ghost { background: transparent; border-color: rgba(26, 14, 6, 0.12); color: #2a221c; }
.btn.primary { background: var(--chili, #D71920); color: #fff; }
.btn.primary:not(:disabled):hover { background: #b5131a; }

.icon-btn {
    background: transparent; border: 0; cursor: pointer;
    font-size: 24px; line-height: 1; padding: 4px 8px;
    color: #7a6e63; border-radius: 8px;
}
.icon-btn:hover { background: rgba(26, 14, 6, 0.06); }

.hint { border: 1px dashed rgba(26, 14, 6, 0.18); border-radius: 10px; padding: 12px 14px; background: rgba(255, 255, 255, 0.5); }
.hint summary { cursor: pointer; font-size: 12px; color: #7a6e63; font-weight: 600; }
.preview-card { margin-top: 10px; display: flex; align-items: center; gap: 12px; padding: 12px; background: #fff; border-radius: 10px; }
.preview-card .badge {
    background: var(--chili, #D71920); color: #fff; font-weight: 700;
    padding: 8px 12px; border-radius: 8px; font-size: 16px; min-width: 64px; text-align: center;
}
.preview-card strong { display: block; }
.preview-card small { display: block; color: #7a6e63; font-size: 12px; }
.preview-card .meta { display: flex; gap: 12px; margin-top: 4px; }

@media (max-width: 600px) {
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
}
</style>
