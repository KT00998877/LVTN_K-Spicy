<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, useId, watch } from 'vue'
import axios from 'axios'
import { createReservation, getReservationOptions } from '@/api/reservations'
import { normalizeReservation, restaurantNow, validateReservation, type ReservationForm, type ReservationOptions } from '@/utils/reservation'

const props = defineProps<{ tables?: Array<{ id: number; code: string; capacity: number }> }>()
const emit = defineEmits<{ created: [] }>()
const id = useId()
const formElement = ref<HTMLFormElement>()
const form = reactive<ReservationForm>({ customer_name: '', customer_phone: '', reserved_date: '', reserved_time: '', guest_count: 2, location: '', note: '', table_id: null })
const options = ref<ReservationOptions>()
const errors = ref<Record<string, string>>({})
const touched = reactive<Record<string, boolean>>({})
const attempted = ref(false)
const submitting = ref(false)
const success = ref('')
const message = ref('')
const now = ref(Date.now())
let clockOffset = 0
let timer: ReturnType<typeof setInterval> | undefined
let requestKey = ''
let requestPayload = ''
const today = computed(() => options.value ? restaurantNow(options.value.timezone, now.value).slice(0, 10) : '')
const fields = [
    { key: 'customer_name', label: 'Họ và tên', type: 'text', autocomplete: 'name' },
    { key: 'customer_phone', label: 'Số điện thoại', type: 'tel', autocomplete: 'tel' },
    { key: 'reserved_date', label: 'Ngày đặt bàn', type: 'date', autocomplete: 'off' },
    { key: 'reserved_time', label: 'Giờ đặt bàn', type: 'time', autocomplete: 'off' },
] as const

async function loadOptions() {
    message.value = ''
    try {
        options.value = await getReservationOptions()
        clockOffset = Date.parse(options.value.server_now) - Date.now()
        now.value = Date.now() + clockOffset
    } catch {
        message.value = 'Chưa tải được thông tin đặt bàn. Vui lòng thử lại.'
    }
}
onMounted(() => {
    void loadOptions()
    timer = setInterval(() => { now.value = Date.now() + clockOffset }, 1000)
})
onUnmounted(() => clearInterval(timer))

function validate(field?: string) {
    if (!options.value) return
    if (field) touched[field] = true
    const result = validateReservation(form, options.value, Date.now() + clockOffset)
    if (form.table_id && props.tables) {
        const table = props.tables.find(table => table.id === form.table_id)
        if (!table || table.capacity < form.guest_count) result.table_id = 'Bàn không tồn tại hoặc không đủ sức chứa cho số khách đã chọn.'
    }
    for (const key of Object.keys(form)) {
        if (attempted.value || touched[key]) {
            if (result[key]) errors.value[key] = result[key]
            else delete errors.value[key]
        }
    }
}
watch(form, () => { if (!submitting.value && !success.value) validate() })
watch(today, () => { if (attempted.value || touched.reserved_date) validate() })

async function submit() {
    if (submitting.value || success.value || !options.value) return
    attempted.value = true
    errors.value = {}
    validate()
    if (Object.keys(errors.value).length) {
        message.value = 'Vui lòng kiểm tra lại thông tin đặt bàn.'
        await nextTick()
        formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
        return
    }
    submitting.value = true
    message.value = ''
    const payload = normalizeReservation(form)
    // Reuse the key after a timeout: a committed request must never create another row.
    try {
        if (requestPayload !== JSON.stringify(payload)) {
            requestKey = crypto.randomUUID()
            requestPayload = JSON.stringify(payload)
        }
        const receipt = await createReservation(payload, requestKey)
        const date = receipt.reserved_date.split('-').reverse().join('/')
        success.value = `Cảm ơn ${receipt.customer_name}. Yêu cầu đặt bàn cho ${receipt.guest_count} khách vào lúc ${receipt.reserved_time} ngày ${date} đã được ghi nhận. Nhà hàng sẽ liên hệ xác nhận.`
        emit('created')
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
            const serverErrors = error.response.data?.errors as Record<string, string[]> | undefined
            errors.value = Object.fromEntries(Object.entries(serverErrors ?? {}).map(([key, values]) => [key, values[0] ?? 'Thông tin không hợp lệ.']))
            message.value = Object.entries(errors.value).filter(([key]) => !(key in form)).map(([, value]) => value).join(' ') || 'Vui lòng kiểm tra lại thông tin đặt bàn.'
            await nextTick()
            formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
        } else {
            message.value = 'Chưa nhận được xác nhận từ nhà hàng. Vui lòng thử gửi lại; yêu cầu giống nhau sẽ không được tạo hai lần.'
        }
    } finally {
        submitting.value = false
    }
}
useRealtimeRefresh(async () => { const latest = await getReservationOptions(); options.value = latest; clockOffset = Date.parse(latest.server_now) - Date.now() }, () => !submitting.value && !success.value, 10000)
</script>

<template>
    <form ref="formElement" class="booking-form" novalidate @submit.prevent="submit">
        <p v-if="success" class="success" role="status">{{ success }}</p>
        <template v-else>
            <p v-if="options" class="helper">Nhận đặt bàn {{ options.opens_at }}–{{ options.closes_at }} (giờ Việt Nam). Vui lòng đặt trước ít nhất {{ options.min_advance_minutes }} phút. Dấu * là thông tin bắt buộc.</p>
            <fieldset :disabled="submitting" class="fields">
                <div v-for="field in fields" :key="field.key" class="form-group">
                    <label :for="`${id}-${field.key}`">{{ field.label }} *</label>
                    <input :id="`${id}-${field.key}`" v-model="form[field.key]" :type="field.type" :autocomplete="field.autocomplete"
                        :inputmode="field.type === 'tel' ? 'tel' : undefined" required
                        :min="field.type === 'date' ? today : field.type === 'time' ? options?.opens_at : undefined"
                        :max="field.type === 'time' ? options?.closes_at : undefined"
                        :maxlength="field.key === 'customer_name' ? 100 : field.key === 'customer_phone' ? 30 : undefined"
                        :aria-invalid="!!errors[field.key]" :aria-describedby="errors[field.key] ? `${id}-${field.key}-error` : undefined" @blur="validate(field.key)" />
                    <p v-if="errors[field.key]" :id="`${id}-${field.key}-error`" class="field-error">{{ errors[field.key] }}</p>
                </div>
                <div class="form-group">
                    <label :for="`${id}-guest_count`">Số khách *</label>
                    <input :id="`${id}-guest_count`" v-model.number="form.guest_count" type="number" min="1" :max="options?.max_guests" step="1" required
                        :aria-invalid="!!errors.guest_count" :aria-describedby="errors.guest_count ? `${id}-guest_count-error` : undefined" @blur="validate('guest_count')" />
                    <p v-if="errors.guest_count" :id="`${id}-guest_count-error`" class="field-error">{{ errors.guest_count }}</p>
                </div>
                <div class="form-group">
                    <label :for="`${id}-location`">Khu vực (không bắt buộc)</label>
                    <select :id="`${id}-location`" v-model="form.location" :aria-invalid="!!errors.location" :aria-describedby="errors.location ? `${id}-location-error` : undefined" @blur="validate('location')">
                        <option value="">Nhà hàng sắp xếp</option><option>Tầng 1</option><option>Tầng 2</option>
                    </select>
                    <p v-if="errors.location" :id="`${id}-location-error`" class="field-error">{{ errors.location }}</p>
                </div>
                <div v-if="tables" class="form-group wide">
                        <label :for="`${id}-table_id`">Bàn (không bắt buộc)</label>
                        <select :id="`${id}-table_id`" v-model="form.table_id" :aria-invalid="!!errors.table_id" :aria-describedby="errors.table_id ? `${id}-table_id-error` : undefined" @blur="validate('table_id')">
                            <option :value="null">Nhà hàng sắp xếp</option>
                            <option v-for="table in tables" :key="table.id" :value="table.id" :disabled="table.capacity < form.guest_count">{{ table.code }} — {{ table.capacity }} chỗ</option>
                        </select>
                        <p v-if="errors.table_id" :id="`${id}-table_id-error`" class="field-error">{{ errors.table_id }}</p>
                </div>
                <div class="form-group wide">
                    <label :for="`${id}-note`">Ghi chú (không bắt buộc)</label>
                    <textarea :id="`${id}-note`" v-model="form.note" rows="3" :maxlength="options?.max_note_length"
                        :aria-invalid="!!errors.note" :aria-describedby="`${id}-note-help${errors.note ? ` ${id}-note-error` : ''}`" @blur="validate('note')"></textarea>
                    <span :id="`${id}-note-help`" class="helper">{{ [...form.note].length }}/{{ options?.max_note_length ?? '…' }} ký tự</span>
                    <p v-if="errors.note" :id="`${id}-note-error`" class="field-error">{{ errors.note }}</p>
                </div>
            </fieldset>
            <p v-if="message" class="field-error" role="alert">{{ message }}</p>
            <button v-if="!options && message" type="button" class="btn" @click="loadOptions">Tải lại thông tin đặt bàn</button>
            <button type="submit" class="btn btn-primary submit-btn" :disabled="submitting || !options">{{ submitting ? 'Đang gửi yêu cầu...' : !options ? 'Đang tải thông tin...' : 'Gửi yêu cầu đặt bàn' }}</button>
        </template>
    </form>
</template>

<style scoped>
.booking-form { display: flex; flex-direction: column; gap: 20px; }
.fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; padding: 0; margin: 0; border: 0; min-width: 0; }
.form-group { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.wide { grid-column: 1 / -1; }
label { font-size: 14px; font-weight: 600; color: var(--color-charcoal-black); }
input, select, textarea { box-sizing: border-box; width: 100%; min-width: 0; padding: 14px; background: var(--color-white); border: 1px solid var(--color-gray-300, #ccc); border-radius: var(--radius-md); font: inherit; color: var(--color-charcoal-black); }
input:focus-visible, select:focus-visible, textarea:focus-visible, button:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 3px; }
[aria-invalid="true"] { border-color: #b91c1c; }
.field-error { color: #991b1b; font-size: 14px; margin: 0; line-height: 1.5; }
.helper { color: var(--color-gray-600, #555); font-size: 14px; margin: 0; line-height: 1.5; }
.success { background: var(--color-success-bg, #f0fdf4); color: #166534; padding: 20px; border-radius: var(--radius-md); line-height: 1.6; }
.submit-btn { padding: 16px; width: 100%; }
button:disabled { opacity: .65; cursor: not-allowed; }
@media (max-width: 575px) { .fields { grid-template-columns: 1fr; } }
</style>
