<script setup lang="ts">
import axios from 'axios'
import { nextTick, onMounted, reactive, ref } from 'vue'
import {
    cancelMyReservation,
    fetchMyReservations,
    getReservationOptions,
    updateMyReservation,
    type CustomerReservation,
    type ReservationStatus,
} from '@/api/reservations'
import { useAppDialog } from '@/composables/useAppDialog'
import {
    normalizeReservation,
    validateReservation,
    type ReservationForm,
    type ReservationOptions,
} from '@/utils/reservation'

const appDialog = useAppDialog()
const items = ref<CustomerReservation[]>([])
const loading = ref(false)
const error = ref('')
const success = ref('')
const options = ref<ReservationOptions | null>(null)
const editingId = ref<number | null>(null)
const savingId = ref<number | null>(null)
const cancellingId = ref<number | null>(null)
const editFormElement = ref<HTMLFormElement | null>(null)
const editErrors = ref<Record<string, string>>({})
const editForm = reactive<ReservationForm>({
    customer_name: '',
    customer_phone: '',
    guest_count: 2,
    reserved_date: '',
    reserved_time: '',
    location: '',
    note: '',
    table_id: null,
})

const STATUS: Record<ReservationStatus, { label: string; cls: string }> = {
    PENDING: { label: 'Chờ xác nhận', cls: 'reservation-status pending' },
    CONFIRMED: { label: 'Đã xác nhận', cls: 'reservation-status confirmed' },
    ARRIVED: { label: 'Đã đến', cls: 'reservation-status arrived' },
    CANCELLED: { label: 'Đã hủy', cls: 'reservation-status cancelled' },
    COMPLETED: { label: 'Hoàn thành', cls: 'reservation-status completed' },
}

async function load(): Promise<void> {
    if (loading.value) return
    loading.value = true
    error.value = ''
    try {
        const [list, settings] = await Promise.all([
            fetchMyReservations(),
            options.value ? Promise.resolve(options.value) : getReservationOptions(),
        ])
        items.value = list.data
        options.value = settings
    } catch (err) {
        error.value = axios.isAxiosError(err)
            ? err.response?.data?.message ?? 'Không thể tải lịch đặt bàn.'
            : 'Không thể tải lịch đặt bàn.'
    } finally {
        loading.value = false
    }
}

function startEdit(item: CustomerReservation): void {
    success.value = ''
    error.value = ''
    editErrors.value = {}
    editingId.value = item.id
    Object.assign(editForm, {
        customer_name: item.customer_name,
        customer_phone: item.customer_phone,
        guest_count: item.guest_count,
        reserved_date: item.reserved_date,
        reserved_time: item.reserved_time,
        location: item.table?.location ?? '',
        note: item.note ?? '',
        table_id: null,
    })
}

function stopEdit(): void {
    editingId.value = null
    editErrors.value = {}
}

function validateField(field: string): void {
    if (!options.value) return
    const validation = validateReservation(editForm, options.value)
    if (validation[field]) editErrors.value[field] = validation[field]
    else delete editErrors.value[field]
}

async function save(item: CustomerReservation): Promise<void> {
    if (!options.value || savingId.value !== null) return
    editErrors.value = validateReservation(editForm, options.value)
    if (Object.keys(editErrors.value).length) {
        await nextTick()
        editFormElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
        return
    }

    savingId.value = item.id
    error.value = ''
    try {
        const normalized = normalizeReservation(editForm)
        const updated = await updateMyReservation(item.id, {
            guest_count: normalized.guest_count,
            reserved_date: normalized.reserved_date,
            reserved_time: normalized.reserved_time,
            location: normalized.location,
            note: normalized.note,
            table_id: null,
        })
        items.value = items.value.map((row) => row.id === updated.id ? updated : row)
        success.value = 'Đã cập nhật lịch đặt bàn.'
        stopEdit()
    } catch (err) {
        if (axios.isAxiosError(err) && err.response?.status === 422) {
            const serverErrors = err.response.data?.errors as Record<string, string[]> | undefined
            editErrors.value = Object.fromEntries(
                Object.entries(serverErrors ?? {}).map(([key, messages]) => [key, messages[0] ?? 'Thông tin không hợp lệ.']),
            )
        } else {
            error.value = 'Không thể cập nhật lịch đặt bàn. Vui lòng thử lại.'
        }
    } finally {
        savingId.value = null
    }
}

async function cancel(item: CustomerReservation): Promise<void> {
    const confirmed = await appDialog.confirm({
        title: 'Hủy lịch đặt bàn?',
        message: `Lịch ${formatSchedule(item)} sẽ được hủy và bàn được trả lại cho nhà hàng.`,
        confirmLabel: 'Hủy lịch',
        tone: 'danger',
    })
    if (!confirmed) return

    cancellingId.value = item.id
    error.value = ''
    success.value = ''
    try {
        const updated = await cancelMyReservation(item.id)
        items.value = items.value.map((row) => row.id === updated.id ? updated : row)
        if (editingId.value === item.id) stopEdit()
        success.value = 'Đã hủy lịch đặt bàn.'
    } catch (err) {
        error.value = axios.isAxiosError(err)
            ? err.response?.data?.message ?? 'Không thể hủy lịch đặt bàn.'
            : 'Không thể hủy lịch đặt bàn.'
    } finally {
        cancellingId.value = null
    }
}

function formatSchedule(item: CustomerReservation): string {
    return `${item.reserved_time} ngày ${item.reserved_date.split('-').reverse().join('/')}`
}

onMounted(load)
</script>

<template>
    <div class="reservation-panel">
        <div class="reservation-head">
            <div>
                <h2>Lịch đặt bàn của tôi</h2>
                <p>Các lịch được tạo khi bạn đăng nhập sẽ xuất hiện tại đây.</p>
            </div>
            <button type="button" class="panel-action" :disabled="loading" @click="load">
                {{ loading ? 'Đang tải…' : 'Tải lại' }}
            </button>
        </div>

        <p v-if="success" class="panel-alert success" role="status">{{ success }}</p>
        <p v-if="error" class="panel-alert error" role="alert">{{ error }}</p>

        <div v-if="loading && items.length === 0" class="panel-state" aria-live="polite">
            <span class="panel-spinner" aria-hidden="true"></span>
            <p>Đang tải lịch đặt bàn…</p>
        </div>

        <div v-else-if="!items.length" class="panel-state empty">
            <h3>Bạn chưa có lịch đặt bàn</h3>
            <p>Đặt bàn trong lúc đăng nhập để theo dõi và thay đổi lịch ngay tại đây.</p>
            <router-link to="/dat-ban" class="panel-primary">Đặt bàn</router-link>
        </div>

        <div v-else class="reservation-list">
            <article v-for="item in items" :key="item.id" class="reservation-row">
                <header class="reservation-row-head">
                    <div>
                        <h3>{{ formatSchedule(item) }}</h3>
                        <p>{{ item.guest_count }} khách · {{ item.table?.name || item.table?.code || 'Nhà hàng sắp xếp bàn' }}</p>
                    </div>
                    <span :class="STATUS[item.status].cls">{{ STATUS[item.status].label }}</span>
                </header>

                <dl v-if="editingId !== item.id" class="reservation-details">
                    <div><dt>Khu vực</dt><dd>{{ item.table?.location || 'Nhà hàng sắp xếp' }}</dd></div>
                    <div><dt>Liên hệ</dt><dd>{{ item.customer_phone }}</dd></div>
                    <div v-if="item.note"><dt>Ghi chú</dt><dd>{{ item.note }}</dd></div>
                </dl>

                <form v-else ref="editFormElement" class="reservation-edit" novalidate @submit.prevent="save(item)">
                    <div class="edit-grid">
                        <div class="edit-field">
                            <label :for="`reservation-date-${item.id}`">Ngày đặt bàn</label>
                            <input :id="`reservation-date-${item.id}`" v-model="editForm.reserved_date" type="date" required
                                :aria-invalid="!!editErrors.reserved_date" :aria-describedby="editErrors.reserved_date ? `reservation-date-error-${item.id}` : undefined"
                                @blur="validateField('reserved_date')" />
                            <p v-if="editErrors.reserved_date" :id="`reservation-date-error-${item.id}`" class="field-error">{{ editErrors.reserved_date }}</p>
                        </div>
                        <div class="edit-field">
                            <label :for="`reservation-time-${item.id}`">Giờ đặt bàn</label>
                            <input :id="`reservation-time-${item.id}`" v-model="editForm.reserved_time" type="time" required
                                :min="options?.opens_at" :max="options?.closes_at"
                                :aria-invalid="!!editErrors.reserved_time" :aria-describedby="editErrors.reserved_time ? `reservation-time-error-${item.id}` : undefined"
                                @blur="validateField('reserved_time')" />
                            <p v-if="editErrors.reserved_time" :id="`reservation-time-error-${item.id}`" class="field-error">{{ editErrors.reserved_time }}</p>
                        </div>
                        <div class="edit-field">
                            <label :for="`reservation-guests-${item.id}`">Số khách</label>
                            <input :id="`reservation-guests-${item.id}`" v-model.number="editForm.guest_count" type="number" min="1" :max="options?.max_guests" required
                                :aria-invalid="!!editErrors.guest_count" :aria-describedby="editErrors.guest_count ? `reservation-guests-error-${item.id}` : undefined"
                                @blur="validateField('guest_count')" />
                            <p v-if="editErrors.guest_count" :id="`reservation-guests-error-${item.id}`" class="field-error">{{ editErrors.guest_count }}</p>
                        </div>
                    </div>

                    <fieldset class="location-options">
                        <legend>Khu vực</legend>
                        <label><input v-model="editForm.location" type="radio" value=""> Nhà hàng sắp xếp</label>
                        <label><input v-model="editForm.location" type="radio" value="Tầng 1"> Tầng 1</label>
                        <label><input v-model="editForm.location" type="radio" value="Tầng 2"> Tầng 2</label>
                    </fieldset>

                    <div class="edit-field">
                        <label :for="`reservation-note-${item.id}`">Ghi chú <span>(không bắt buộc)</span></label>
                        <textarea :id="`reservation-note-${item.id}`" v-model="editForm.note" rows="3" :maxlength="options?.max_note_length"
                            :aria-invalid="!!editErrors.note" :aria-describedby="editErrors.note ? `reservation-note-error-${item.id}` : undefined"
                            @blur="validateField('note')"></textarea>
                        <p v-if="editErrors.note" :id="`reservation-note-error-${item.id}`" class="field-error">{{ editErrors.note }}</p>
                    </div>

                    <p v-if="editErrors.reservation" class="field-error" role="alert">{{ editErrors.reservation }}</p>
                    <div class="reservation-actions">
                        <button type="button" class="panel-action" :disabled="savingId === item.id" @click="stopEdit">Đóng</button>
                        <button type="submit" class="panel-primary" :disabled="savingId === item.id">
                            {{ savingId === item.id ? 'Đang lưu…' : 'Lưu thay đổi' }}
                        </button>
                    </div>
                </form>

                <footer v-if="editingId !== item.id && (item.can_edit || item.can_cancel)" class="reservation-actions">
                    <button v-if="item.can_edit" type="button" class="panel-action" @click="startEdit(item)">Đổi lịch</button>
                    <button v-if="item.can_cancel" type="button" class="panel-danger" :disabled="cancellingId === item.id" @click="cancel(item)">
                        {{ cancellingId === item.id ? 'Đang hủy…' : 'Hủy lịch' }}
                    </button>
                </footer>
            </article>
        </div>
    </div>
</template>

<style scoped>
.reservation-panel { display: flex; flex-direction: column; gap: 24px; }
.reservation-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }
.reservation-head h2 { margin: 0 0 6px; color: var(--color-charcoal-black); font-size: 24px; }
.reservation-head p { margin: 0; color: var(--color-gray-600); font-size: 14px; }
.reservation-list { display: flex; flex-direction: column; }
.reservation-row { padding: 24px 0; border-bottom: 1px solid var(--color-gray-200); }
.reservation-row:first-child { padding-top: 0; }
.reservation-row:last-child { padding-bottom: 0; border-bottom: 0; }
.reservation-row-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.reservation-row-head h3 { margin: 0 0 6px; color: var(--color-charcoal-black); font-size: 18px; }
.reservation-row-head p { margin: 0; color: var(--color-gray-600); font-size: 14px; }
.reservation-status { flex: 0 0 auto; padding: 6px 12px; border-radius: var(--radius-full); font-size: 13px; font-weight: 700; }
.reservation-status.pending { background: #fef3c7; color: #92400e; }
.reservation-status.confirmed { background: #dbeafe; color: #1e40af; }
.reservation-status.arrived, .reservation-status.completed { background: #dcfce7; color: #166534; }
.reservation-status.cancelled { background: #fee2e2; color: #991b1b; }
.reservation-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 20px 0 0; }
.reservation-details div:last-child:nth-child(odd) { grid-column: 1 / -1; }
.reservation-details dt { margin-bottom: 4px; color: var(--color-gray-500); font-size: 13px; font-weight: 600; }
.reservation-details dd { margin: 0; color: var(--color-charcoal-black); font-size: 15px; }
.reservation-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.panel-action, .panel-primary, .panel-danger { min-height: 42px; padding: 10px 18px; border-radius: var(--radius-full); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.panel-action { border: 1px solid var(--color-gray-300); background: var(--color-white); color: var(--color-charcoal-black); }
.panel-primary { display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--color-chili-red); background: var(--color-chili-red); color: var(--color-white); text-decoration: none; }
.panel-danger { border: 1px solid #b91c1c; background: var(--color-white); color: #b91c1c; }
.panel-action:hover:not(:disabled) { background: var(--color-gray-50); }
.panel-primary:hover:not(:disabled) { box-shadow: var(--shadow-sm); }
.panel-danger:hover:not(:disabled) { background: #b91c1c; color: var(--color-white); }
.panel-action:focus-visible, .panel-primary:focus-visible, .panel-danger:focus-visible, input:focus-visible, textarea:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 3px; }
button:disabled { cursor: not-allowed; opacity: .6; }
.panel-state { padding: 56px 20px; border-radius: var(--radius-lg); background: var(--color-gray-50); color: var(--color-gray-600); text-align: center; }
.panel-state h3 { margin: 0 0 8px; color: var(--color-charcoal-black); font-size: 20px; }
.panel-state p { margin: 0 0 20px; font-size: 15px; }
.panel-spinner { display: block; width: 32px; height: 32px; margin: 0 auto 12px; border: 3px solid var(--color-gray-200); border-top-color: var(--color-chili-red); border-radius: 50%; animation: reservation-spin .8s linear infinite; }
.panel-alert { margin: 0; padding: 14px 16px; border-radius: var(--radius-md); font-size: 14px; font-weight: 600; }
.panel-alert.success { background: #f0fdf4; color: #166534; }
.panel-alert.error { background: #fef2f2; color: #991b1b; }
.reservation-edit { margin-top: 20px; padding: 20px; border-radius: var(--radius-lg); background: var(--color-gray-50); }
.edit-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.edit-field { display: flex; flex-direction: column; gap: 7px; margin-top: 16px; }
.edit-grid .edit-field { margin-top: 0; }
.edit-field label, .location-options legend { color: var(--color-charcoal-black); font-size: 14px; font-weight: 700; }
.edit-field label span { color: var(--color-gray-500); font-weight: 500; }
.edit-field input, .edit-field textarea { box-sizing: border-box; width: 100%; padding: 12px 14px; border: 1px solid var(--color-gray-300); border-radius: var(--radius-md); background: var(--color-white); color: var(--color-charcoal-black); font: inherit; }
.edit-field [aria-invalid="true"] { border-color: #b91c1c; }
.field-error { margin: 0; color: #991b1b; font-size: 13px; line-height: 1.5; }
.location-options { display: flex; flex-wrap: wrap; gap: 10px 20px; margin: 18px 0 0; padding: 0; border: 0; }
.location-options legend { margin-bottom: 8px; }
.location-options label { display: inline-flex; align-items: center; gap: 8px; color: var(--color-charcoal-black); font-size: 14px; cursor: pointer; }
@keyframes reservation-spin { to { transform: rotate(360deg); } }
@media (max-width: 700px) {
    .reservation-head, .reservation-row-head { flex-direction: column; }
    .reservation-details, .edit-grid { grid-template-columns: 1fr; }
    .reservation-details div:last-child:nth-child(odd) { grid-column: auto; }
    .reservation-actions { justify-content: stretch; }
    .reservation-actions > * { flex: 1; }
}
@media (prefers-reduced-motion: reduce) { .panel-spinner { animation-duration: 0s; } }
</style>
