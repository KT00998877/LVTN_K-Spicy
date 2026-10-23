<template>
    <dialog ref="dialogEl" class="promo-modal" @cancel="cancel" @click="backdrop">
        <form class="modal-form" @submit.prevent="submit">
            <header class="modal-head">
                <div>
                    <span class="eyebrow">{{ editingId ? 'CẬP NHẬT' : 'TẠO MỚI' }}</span>
                    <h3>{{ editingId ? 'Sửa chương trình khuyến mãi' : 'Thêm chương trình khuyến mãi' }}</h3>
                </div>
                <button type="button" class="icon-btn" :disabled="saving" aria-label="Đóng" @click="close">×</button>
            </header>

            <ol class="stepper" :aria-disabled="saving">
                <li v-for="(s, i) in steps" :key="s.key" :class="{ active: i === step, done: i < step }">
                    <span class="dot">{{ i + 1 }}</span>
                    <span class="label">{{ s.label }}</span>
                </li>
            </ol>

            <div class="modal-body">
                <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

                <!-- Step 1: Thông tin cơ bản -->
                <section v-show="step === 0" class="step-pane">
                    <div class="grid-2">
                        <label>
                            Tên chương trình
                            <input v-model="form.name" required maxlength="160" :disabled="saving" />
                        </label>
                        <label>
                            Slug (tuỳ chọn)
                            <input
                                v-model="form.slug"
                                maxlength="160"
                                placeholder="Tự sinh nếu bỏ trống"
                                :disabled="saving"
                            />
                            <small>Chữ thường, số, gạch ngang.</small>
                        </label>
                    </div>

                    <label>
                        Mô tả
                        <textarea v-model="form.description" maxlength="500" rows="2" :disabled="saving" />
                    </label>

                    <CampaignPicker v-model="form.campaign_id" :disabled="saving" />

                    <fieldset class="grid-3">
                        <label>
                            Loại khuyến mãi
                            <select v-model="form.kind" :disabled="saving || !!editingId">
                                <option value="PERCENT_OFF">Giảm theo %</option>
                                <option value="FIXED_OFF">Giảm số tiền cố định</option>
                                <option value="BXGY">Mua X tặng Y</option>
                                <option value="COMBO">Combo giá cố định</option>
                            </select>
                            <small v-if="editingId">Không thể đổi loại khi sửa.</small>
                        </label>
                        <label>
                            Ưu tiên
                            <input v-model.number="form.priority" type="number" min="0" max="9999" :disabled="saving" />
                            <small>Số cao áp trước.</small>
                        </label>
                        <label>
                            Chế độ cộng dồn
                            <select v-model="form.stacking_mode" :disabled="saving">
                                <option value="STACK">Cộng dồn nhiều KM</option>
                                <option value="EXCLUSIVE">Chỉ cái cao nhất</option>
                                <option value="BEST_ONLY">Chọn cái giảm nhiều nhất</option>
                            </select>
                        </label>
                    </fieldset>
                </section>

                <!-- Step 2: Điều kiện -->
                <section v-show="step === 1" class="step-pane">
                    <ConditionBuilder v-model="form.conditions" :disabled="saving" />
                </section>

                <!-- Step 3: Phạm vi & cấu hình -->
                <section v-show="step === 2" class="step-pane">
                    <fieldset class="grid-2">
                        <label>
                            Bắt đầu
                            <input v-model="form.starts_at" type="datetime-local" :disabled="saving" />
                        </label>
                        <label>
                            Kết thúc
                            <input v-model="form.ends_at" type="datetime-local" :disabled="saving" />
                        </label>
                    </fieldset>

                    <fieldset class="grid-2">
                        <label>
                            Hạn mức toàn hệ thống
                            <input
                                v-model.number="form.usage_limit"
                                type="number"
                                min="1"
                                placeholder="Không giới hạn"
                                :disabled="saving"
                            />
                        </label>
                        <label>
                            Hạn mức / khách
                            <input
                                v-model.number="form.usage_limit_per_user"
                                type="number"
                                min="1"
                                placeholder="Không giới hạn"
                                :disabled="saving"
                            />
                        </label>
                    </fieldset>

                    <label class="row">
                        <input type="checkbox" v-model="form.is_active" :disabled="saving" />
                        Kích hoạt ngay sau khi tạo
                    </label>

                    <hr class="divider" />

                    <!-- Config theo kind -->
                    <div v-if="form.kind === 'PERCENT_OFF'" class="kind-config">
                        <fieldset class="grid-2">
                            <label>
                                Phần trăm giảm
                                <input
                                    v-model.number="(form.config as Extract<PromotionConfig, { kind: 'PERCENT_OFF' }>).value"
                                    type="number"
                                    min="0.1"
                                    max="100"
                                    step="0.1"
                                    required
                                    :disabled="saving"
                                />
                            </label>
                            <label>
                                Trần giảm / đơn (VND)
                                <input
                                    v-model.number="(form.config as Extract<PromotionConfig, { kind: 'PERCENT_OFF' }>).max_discount"
                                    type="number"
                                    min="0"
                                    step="1000"
                                    placeholder="Không giới hạn"
                                    :disabled="saving"
                                />
                            </label>
                        </fieldset>
                        <ScopePicker v-model="form.config" :disabled="saving" />
                    </div>

                    <div v-else-if="form.kind === 'FIXED_OFF'" class="kind-config">
                        <label>
                            Số tiền giảm (VND)
                            <input
                                v-model.number="(form.config as Extract<PromotionConfig, { kind: 'FIXED_OFF' }>).value"
                                type="number"
                                min="1000"
                                step="1000"
                                required
                                :disabled="saving"
                            />
                            <small>Tối thiểu 1.000đ.</small>
                        </label>
                        <ScopePicker v-model="form.config" :disabled="saving" />
                    </div>

                    <div v-else-if="form.kind === 'BXGY'" class="kind-config">
                        <fieldset class="grid-3">
                            <label>
                                Số lượng mua
                                <input
                                    v-model.number="(form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).buy_qty"
                                    type="number"
                                    min="1"
                                    required
                                    :disabled="saving"
                                />
                            </label>
                            <label>
                                Số lượng tặng
                                <input
                                    v-model.number="(form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).get_qty"
                                    type="number"
                                    min="1"
                                    required
                                    :disabled="saving"
                                />
                            </label>
                            <label>
                                % giảm trên món tặng
                                <input
                                    v-model.number="(form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).discount_percent"
                                    type="number"
                                    min="0.1"
                                    max="100"
                                    step="0.1"
                                    required
                                    :disabled="saving"
                                />
                                <small>100% = miễn phí phần tặng.</small>
                            </label>
                        </fieldset>
                        <label>
                            ID món được tặng (phân cách dấu phẩy)
                            <input
                                v-model="bxgyItems"
                                type="text"
                                placeholder="VD: 12, 34"
                                :disabled="saving"
                                @blur="commitBxgyItems"
                            />
                            <small>Nhập theo ID menu_item.</small>
                        </label>
                    </div>

                    <div v-else-if="form.kind === 'COMBO'" class="kind-config">
                        <label>
                            Giá combo (VND)
                            <input
                                v-model.number="(form.config as Extract<PromotionConfig, { kind: 'COMBO' }>).bundle_price"
                                type="number"
                                min="1"
                                required
                                :disabled="saving"
                            />
                        </label>
                        <div>
                            <span class="field-label">Món trong combo</span>
                            <div class="bundle-list">
                                <div
                                    v-for="(row, i) in (form.config as Extract<PromotionConfig, { kind: 'COMBO' }>).bundle_items"
                                    :key="i"
                                    class="bundle-row"
                                >
                                    <input
                                        v-model.number="row.item_id"
                                        type="number"
                                        min="1"
                                        placeholder="ID món"
                                        :disabled="saving"
                                    />
                                    <input
                                        v-model.number="row.qty"
                                        type="number"
                                        min="1"
                                        placeholder="SL"
                                        :disabled="saving"
                                    />
                                    <button
                                        type="button"
                                        class="icon-btn small danger"
                                        :disabled="saving"
                                        @click="removeBundle(i)"
                                    >
                                        ×
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="btn ghost"
                                    :disabled="saving"
                                    @click="addBundle"
                                >
                                    + Thêm món
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Step 4: Xem trước -->
                <section v-show="step === 3" class="step-pane">
                    <p class="hint">
                        Tóm tắt cấu hình — kiểm tra nhanh trước khi lưu. Sau khi lưu, dùng panel
                        "Xem trước cart mẫu" ở sidebar để chạy thử trên cart giả lập.
                    </p>
                    <dl class="summary">
                        <div>
                            <dt>Tên</dt>
                            <dd>{{ form.name || '—' }}</dd>
                        </div>
                        <div>
                            <dt>Loại</dt>
                            <dd>{{ kindLabel(form.kind) }}</dd>
                        </div>
                        <div>
                            <dt>Ưu tiên / Stacking</dt>
                            <dd>{{ form.priority }} / {{ form.stacking_mode }}</dd>
                        </div>
                        <div>
                            <dt>Điều kiện</dt>
                            <dd>{{ conditionSummary }}</dd>
                        </div>
                        <div>
                            <dt>Cấu hình</dt>
                            <dd><code>{{ configSummary }}</code></dd>
                        </div>
                        <div>
                            <dt>Thời gian</dt>
                            <dd>
                                {{ form.starts_at || 'Không giới hạn' }} →
                                {{ form.ends_at || 'Không giới hạn' }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <footer class="modal-foot">
                <button
                    v-if="step > 0"
                    type="button"
                    class="btn ghost"
                    :disabled="saving"
                    @click="step--"
                >
                    Quay lại
                </button>
                <button v-if="step < 3" type="button" class="btn primary" :disabled="saving" @click="step++">
                    Tiếp tục
                </button>
                <button
                    v-else
                    type="submit"
                    class="btn primary"
                    :disabled="saving || !canSubmit"
                >
                    {{ saving ? 'Đang lưu…' : editingId ? 'Cập nhật' : 'Tạo khuyến mãi' }}
                </button>
                <button type="button" class="btn ghost" :disabled="saving" @click="close">Huỷ</button>
            </footer>
        </form>
    </dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
    PromotionConditions,
    PromotionConfig,
    PromotionKind,
    PromotionPayload,
} from '@/types/promotion'
import ConditionBuilder from './ConditionBuilder.vue'
import ScopePicker from './ScopePicker.vue'
import CampaignPicker from './CampaignPicker.vue'

interface Props {
    promotion: PromotionPayload | null
}
const props = defineProps<Props>()
const emit = defineEmits<{
    save: [PromotionPayload & { id?: number }]
    cancel: []
}>()

const dialogEl = ref<HTMLDialogElement | null>(null)
const step = ref(0)
const saving = ref(false)
const formError = ref('')
const editingId = computed(() => (props.promotion as unknown as { id?: number } | null)?.id ?? null)

const steps = [
    { key: 'basic', label: 'Thông tin' },
    { key: 'condition', label: 'Điều kiện' },
    { key: 'config', label: 'Phạm vi' },
    { key: 'preview', label: 'Xem trước' },
]

function emptyConfig(kind: PromotionKind): PromotionConfig {
    switch (kind) {
        case 'PERCENT_OFF':
            return { kind: 'PERCENT_OFF', value: 10, applies_to: 'ALL' }
        case 'FIXED_OFF':
            return { kind: 'FIXED_OFF', value: 20000, applies_to: 'ALL' }
        case 'BXGY':
            return { kind: 'BXGY', buy_qty: 2, get_qty: 1, discount_percent: 100, get_item_ids: [] }
        case 'COMBO':
            return {
                kind: 'COMBO',
                bundle_items: [
                    { item_id: 0, qty: 1 },
                    { item_id: 0, qty: 1 },
                ],
                bundle_price: 99000,
            }
    }
}

type PromotionForm = PromotionPayload & { campaign_id: number | null; conditions: PromotionConditions }

function blankForm(): PromotionForm {
    return {
        name: '',
        description: null,
        campaign_id: null,
        slug: '',
        kind: 'PERCENT_OFF',
        stacking_mode: 'STACK',
        priority: 10,
        config: emptyConfig('PERCENT_OFF'),
        conditions: {},
        usage_limit: null,
        usage_limit_per_user: null,
        starts_at: null,
        ends_at: null,
        is_active: true,
        items: [],
    }
}

const form = reactive<PromotionForm>(blankForm())
const bxgyItems = ref('')

function resetForm(): void {
    Object.assign(form, blankForm())
    bxgyItems.value = ''
    step.value = 0
    formError.value = ''
}

function loadFrom(p: PromotionPayload | null): void {
    resetForm()
    if (!p) return
    form.name = p.name
    form.description = p.description ?? null
    form.campaign_id = p.campaign_id ?? null
    form.slug = p.slug ?? ''
    form.kind = p.kind
    form.stacking_mode = p.stacking_mode
    form.priority = p.priority
    form.conditions = { ...(p.conditions ?? {}) }
    form.usage_limit = p.usage_limit ?? null
    form.usage_limit_per_user = p.usage_limit_per_user ?? null
    form.starts_at = p.starts_at ?? null
    form.ends_at = p.ends_at ?? null
    form.is_active = p.is_active ?? true
    form.config = JSON.parse(JSON.stringify(p.config))
    form.items = (p.items ?? []).map((it) => ({ ...it }))
    if (form.kind === 'BXGY') bxgyItems.value = (form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).get_item_ids.join(', ')
}

watch(
    () => props.promotion,
    (p) => loadFrom(p),
    { immediate: true },
)

watch(
    () => form.kind,
    (k, prev) => {
        if (k === prev) return
        // Đổi kind → reset config để tránh conflict.
        form.config = emptyConfig(k)
        if (k === 'BXGY') bxgyItems.value = (form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).get_item_ids.join(', ')
    },
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

function commitBxgyItems(): void {
    if (form.kind !== 'BXGY') return
    const ids = bxgyItems.value
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => Number.isFinite(n) && n > 0)
    ;(form.config as Extract<PromotionConfig, { kind: 'BXGY' }>).get_item_ids = ids
}

function addBundle(): void {
    if (form.kind !== 'COMBO') return
    ;(form.config as Extract<PromotionConfig, { kind: 'COMBO' }>).bundle_items.push({ item_id: 0, qty: 1 })
}
function removeBundle(i: number): void {
    if (form.kind !== 'COMBO') return
    ;(form.config as Extract<PromotionConfig, { kind: 'COMBO' }>).bundle_items.splice(i, 1)
}

const conditionSummary = computed(() => {
    const c = form.conditions ?? {}
    const parts: string[] = []
    if (c.min_subtotal && c.min_subtotal > 0) parts.push(`Đơn ≥ ${c.min_subtotal.toLocaleString('vi-VN')}đ`)
    if (c.days_of_week?.length) parts.push(`${c.days_of_week.length} ngày/tuần`)
    if (c.time_from || c.time_to) parts.push(`${c.time_from ?? '—'} → ${c.time_to ?? '—'}`)
    if (c.channels?.length) parts.push(`${c.channels.join(', ')}`)
    if (c.first_order_only) parts.push('Đơn đầu tiên')
    if (c.loyalty_tier_ids?.length) parts.push(`Hạng ${c.loyalty_tier_ids.join('/')}`)
    return parts.length ? parts.join(' · ') : 'Không ràng buộc'
})

const configSummary = computed(() => JSON.stringify(form.config))
const canSubmit = computed(() => !!form.name.trim())

function kindLabel(k: PromotionKind): string {
    return (
        { PERCENT_OFF: 'Giảm theo %', FIXED_OFF: 'Giảm cố định', BXGY: 'Mua X tặng Y', COMBO: 'Combo' }[
            k
        ] || k
    )
}

function submit(): void {
    formError.value = ''
    if (!form.name.trim()) {
        formError.value = 'Nhập tên chương trình.'
        step.value = 0
        return
    }
    if (form.kind === 'BXGY') commitBxgyItems()
    saving.value = true
    const payload: PromotionPayload = {
        ...form,
        conditions: { ...form.conditions },
    }
    // Loại bỏ key rỗng trong conditions để BE nhận JSON gọn.
    Object.keys(payload.conditions ?? {}).forEach((k) => {
        const conditions = payload.conditions ?? {}
        const v = conditions[k as keyof PromotionConditions]
        if (v === undefined || (Array.isArray(v) && v.length === 0)) {
            delete conditions[k as keyof PromotionConditions]
        }
    })
    payload.conditions = payload.conditions ?? {}
    emit('save', { ...(payload as object), id: editingId.value } as PromotionPayload & {
        id?: number
    })
}

defineExpose({ open, close })
</script>

<style scoped>
.promo-modal {
    border: none;
    border-radius: 16px;
    padding: 0;
    background: transparent;
    width: min(720px, calc(100vw - 32px));
    max-height: calc(100vh - 32px);
    color: #1a1a1a;
}
.promo-modal::backdrop {
    background: rgba(26, 14, 6, 0.55);
    backdrop-filter: blur(2px);
}
.modal-form {
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 16px;
    max-height: calc(100vh - 32px);
    overflow: hidden;
}
.modal-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    padding: 20px 24px 12px;
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
    font-size: 20px;
    font-weight: 800;
}
.icon-btn {
    background: transparent;
    border: 0;
    font-size: 24px;
    line-height: 1;
    color: #6b5f54;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 8px;
}
.icon-btn:hover {
    background: rgba(26, 14, 6, 0.06);
}
.icon-btn.small {
    width: 26px;
    height: 26px;
    font-size: 16px;
}
.icon-btn.danger {
    color: var(--chili, #d71920);
}

.stepper {
    display: flex;
    gap: 8px;
    list-style: none;
    margin: 0;
    padding: 12px 24px;
    background: rgba(26, 14, 6, 0.02);
    border-bottom: 1px solid rgba(26, 14, 6, 0.05);
}
.stepper li {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6b5f54;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(26, 14, 6, 0.04);
}
.stepper li.active {
    background: rgba(217, 25, 32, 0.1);
    color: var(--chili, #d71920);
    font-weight: 700;
}
.stepper li.done {
    color: #166534;
}
.stepper li .dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(26, 14, 6, 0.1);
    font-weight: 700;
    font-size: 11px;
}
.stepper li.active .dot {
    background: var(--chili, #d71920);
    color: #fff;
}
.stepper li.done .dot {
    background: #16a34a;
    color: #fff;
}

.modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1;
}
.step-pane {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
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
.modal-body textarea,
.modal-body select {
    padding: 9px 11px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
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
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.modal-body small {
    color: #6b5f54;
    font-size: 11px;
}
.modal-body fieldset {
    border: 1px solid rgba(26, 14, 6, 0.1);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: rgba(26, 14, 6, 0.02);
}
.divider {
    border: 0;
    border-top: 1px dashed rgba(26, 14, 6, 0.12);
    margin: 8px 0;
}
.field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
}
.bundle-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.bundle-row {
    display: grid;
    grid-template-columns: 1fr 80px 32px;
    gap: 8px;
    align-items: center;
}
.summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin: 0;
}
.summary div {
    background: rgba(26, 14, 6, 0.04);
    border-radius: 8px;
    padding: 8px 10px;
}
.summary dt {
    font-size: 11px;
    color: #6b5f54;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 2px;
}
.summary dd {
    margin: 0;
    font-weight: 600;
    font-size: 13px;
}
.summary code {
    font-size: 11px;
    word-break: break-word;
}
.alert.error {
    background: rgba(217, 25, 32, 0.08);
    color: var(--chili, #d71920);
    border-radius: 8px;
    padding: 10px 12px;
    margin: 0 0 12px;
    font-size: 13px;
}
.hint {
    margin: 0 0 8px;
    font-size: 13px;
    color: #6b5f54;
}

.modal-foot {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding: 14px 24px;
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
    transition: background 0.12s, transform 0.06s;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn.primary {
    background: var(--chili, #d71920);
    color: #fff;
}
.btn.primary:not(:disabled):hover {
    background: #b5131a;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
.btn.ghost:hover {
    background: rgba(26, 14, 6, 0.05);
}
@media (max-width: 640px) {
    .grid-2,
    .grid-3,
    .summary {
        grid-template-columns: 1fr;
    }
}
</style>
