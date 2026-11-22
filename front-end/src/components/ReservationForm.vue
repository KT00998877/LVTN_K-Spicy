<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, useId, watch } from 'vue'
import axios from 'axios'
import { useRoute } from 'vue-router'
import { createReservation, getReservationAvailability, getReservationOptions, getReservationStatus, type ReservationStatus } from '@/api/reservations'
import { useAuthStore } from '@/stores/auth'
import { normalizeReservation, restaurantNow, validateReservation, type ReservationForm, type ReservationOptions } from '@/utils/reservation'

const props = defineProps<{ tables?: Array<{ id: number; code: string; capacity: number }> }>()
const route = useRoute()
const authStore = useAuthStore()
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
const bookingStatus = ref<ReservationStatus | null>(null)
const receiptId = ref<number | null>(null)
const availableTimes = ref<string[]>([])
const availabilityLoaded = ref(false)
const availabilityLoading = ref(false)
const availabilityError = ref('')
const message = ref('')
const now = ref(Date.now())
let clockOffset = 0
let timer: ReturnType<typeof setInterval> | undefined
let requestKey = ''
let requestPayload = ''
const preferredTime = typeof route.query.time === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(route.query.time) ? route.query.time : ''
const today = computed(() => options.value ? restaurantNow(options.value.timezone, now.value).slice(0, 10) : '')
const latestTime = computed(() => {
    if (!options.value) return ''
    const parts = options.value.closes_at.split(':').map(Number)
    const minutes = parts[0]! * 60 + parts[1]! - options.value.hold_minutes
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
})
const fields = [
    { key: 'customer_name', label: 'Họ và tên', type: 'text', autocomplete: 'name' },
    { key: 'customer_phone', label: 'Số điện thoại', type: 'tel', autocomplete: 'tel' },
    { key: 'reserved_date', label: 'Ngày đặt bàn', type: 'date', autocomplete: 'off' },
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
    if (typeof route.query.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(route.query.date)) form.reserved_date = route.query.date
    if (typeof route.query.guests === 'string') {
        const guests = Number(route.query.guests)
        if (Number.isInteger(guests) && guests >= 1 && guests <= 20) form.guest_count = guests
    }
    if (route.query.location === 'Tầng 1' || route.query.location === 'Tầng 2') form.location = route.query.location
    void loadOptions()
    timer = setInterval(() => { now.value = Date.now() + clockOffset }, 1000)
})
onUnmounted(() => {
    clearInterval(timer)
    availabilityVersion++
})

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
let availabilityVersion = 0
const canCheckAvailability = computed(() =>
    options.value && /^\d{4}-\d{2}-\d{2}$/.test(form.reserved_date) &&
    Number.isInteger(form.guest_count) && form.guest_count >= 1 && form.guest_count <= options.value.max_guests,
)
const selectedTimeUnavailable = computed(() =>
    availabilityLoaded.value && !!form.reserved_time && !availableTimes.value.includes(form.reserved_time),
)

async function refreshAvailability(background = false) {
    if (!canCheckAvailability.value) return
    const version = ++availabilityVersion
    if (!background) availabilityLoading.value = true
    try {
        const times = await getReservationAvailability(form.reserved_date, form.guest_count, form.location)
        if (version !== availabilityVersion) return
        if (times.length !== availableTimes.value.length || times.some((time, index) => time !== availableTimes.value[index])) {
            availableTimes.value = times
        }
        availabilityLoaded.value = true
        availabilityError.value = ''
        if (!background && preferredTime && times.length) {
            const minutes = (value: string) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5))
            form.reserved_time = [...times].sort((a, b) => Math.abs(minutes(a) - minutes(preferredTime)) - Math.abs(minutes(b) - minutes(preferredTime)))[0]!
        }
    } catch (error) {
        if (version !== availabilityVersion) return
        if (background) throw error
        availabilityError.value = 'Chưa kiểm tra được giờ còn bàn. Bạn có thể nhập giờ thủ công.'
    } finally {
        if (!background && version === availabilityVersion) availabilityLoading.value = false
    }
}

watch([() => form.reserved_date, () => form.guest_count, () => form.location, () => !!options.value], () => {
    availabilityVersion++
    form.reserved_time = ''
    availableTimes.value = []
    availabilityLoaded.value = false
    availabilityLoading.value = false
    availabilityError.value = ''
    void refreshAvailability()
})

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
        success.value = `Cảm ơn ${receipt.customer_name}. Yêu cầu đặt bàn cho ${receipt.guest_count} khách vào lúc ${receipt.reserved_time} ngày ${date} đã được ghi nhận.`
        bookingStatus.value = receipt.status as ReservationStatus
        receiptId.value = receipt.id
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
useRealtimeRefresh(
    () => refreshAvailability(true),
    () => !!canCheckAvailability.value && !availabilityLoading.value && !submitting.value && !success.value,
    20000,
)
useRealtimeRefresh(async () => { bookingStatus.value = await getReservationStatus(requestKey) }, () => !!success.value && bookingStatus.value === 'PENDING', 3000)
</script>

<template>
    <form ref="formElement" class="booking-form" novalidate @submit.prevent="submit">
        <div v-if="success" class="success" role="status">
            <p>{{ success }}</p>
            <p v-if="receiptId"><strong>Mã đặt bàn #{{ receiptId }}.</strong> Bàn chỉ được giữ trong {{ options?.hold_minutes ?? 30 }} phút kể từ giờ đã chọn.</p>
            <strong v-if="bookingStatus === 'PENDING'">Đang chờ thu ngân xác nhận. Trạng thái sẽ tự cập nhật tại đây.</strong>
            <p v-if="bookingStatus === 'PENDING' && !authStore.isAuthenticated">Bạn chưa đăng nhập; hãy giữ trang này mở để nhận kết quả.</p>
            <strong v-else-if="bookingStatus === 'CONFIRMED'">Thu ngân đã xác nhận đặt bàn của bạn.</strong>
            <strong v-else-if="bookingStatus === 'CANCELLED'">Thu ngân không thể tiếp nhận đặt bàn này. Vui lòng chọn lịch khác hoặc liên hệ nhà hàng.</strong>
            <p v-if="authStore.isAuthenticated"><RouterLink to="/tai-khoan?tab=reservations">Xem đặt bàn của tôi</RouterLink></p>
        </div>
        <template v-else>
            <p v-if="options" class="helper">Nhận đặt bàn {{ options.opens_at }}–{{ latestTime }} (giờ Việt Nam). Vui lòng đặt trước ít nhất {{ options.min_advance_minutes }} phút. Dấu * là thông tin bắt buộc.</p>
            <p v-if="options" class="helper">Bàn được giữ tối đa {{ options.hold_minutes }} phút kể từ giờ đặt.</p>
            <fieldset :disabled="submitting" class="fields">
                <div v-for="field in fields" :key="field.key" class="form-group">
                    <label :for="`${id}-${field.key}`">{{ field.label }} *</label>
                    <input :id="`${id}-${field.key}`" v-model="form[field.key]" :type="field.type" :autocomplete="field.autocomplete"
                        :inputmode="field.type === 'tel' ? 'tel' : undefined" required
                        :min="field.type === 'date' ? today : undefined"
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
                <div class="form-group wide" role="group" :aria-labelledby="`${id}-time-label`" :aria-describedby="errors.reserved_time ? `${id}-time-error` : undefined" :aria-invalid="!!errors.reserved_time" tabindex="-1">
                    <strong :id="`${id}-time-label`">Giờ đặt bàn * <span v-if="form.reserved_date">— còn bàn cho {{ form.guest_count }} khách</span></strong>
                    <p v-if="preferredTime && form.reserved_time && !selectedTimeUnavailable" class="helper">AI gợi ý giờ {{ form.reserved_time }} gần với giờ bạn hỏi. Hãy kiểm tra trước khi gửi.</p>
                    <p v-if="!form.reserved_date" class="helper">Chọn ngày để xem giờ còn bàn.</p>
                    <p v-else-if="availabilityLoading" class="helper" role="status">Đang kiểm tra giờ còn bàn...</p>
                    <template v-else-if="availabilityError">
                        <p class="helper">{{ availabilityError }}</p>
                        <label :for="`${id}-fallback-time`" class="sr-only">Nhập giờ đặt bàn</label>
                        <input :id="`${id}-fallback-time`" v-model="form.reserved_time" type="time" :min="options?.opens_at" :max="latestTime" :aria-invalid="!!errors.reserved_time" />
                    </template>
                    <p v-else-if="!availableTimes.length" class="helper">Không có giờ phù hợp trong ngày này. Hãy chọn ngày hoặc khu vực khác.</p>
                    <template v-else>
                        <div class="available-times">
                            <button v-for="time in availableTimes" :key="time" type="button" :aria-pressed="form.reserved_time === time" @click="form.reserved_time = time">{{ time }}</button>
                        </div>
                        <p class="helper">Giờ còn bàn có thể thay đổi trước khi gửi yêu cầu.</p>
                    </template>
                    <p v-if="selectedTimeUnavailable" class="field-error" role="status">Giờ {{ form.reserved_time }} vừa hết chỗ. Vui lòng chọn giờ khác.</p>
                    <p v-if="errors.reserved_time" :id="`${id}-time-error`" class="field-error">{{ errors.reserved_time }}</p>
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
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.wide { grid-column: 1 / -1; }
label { font-size: 14px; font-weight: 600; color: var(--color-charcoal-black); }
input, select, textarea { box-sizing: border-box; width: 100%; min-width: 0; padding: 14px; background: var(--color-white); border: 1px solid var(--color-gray-300, #ccc); border-radius: var(--radius-md); font: inherit; color: var(--color-charcoal-black); }
input:focus-visible, select:focus-visible, textarea:focus-visible, button:focus-visible { outline: 2px solid var(--color-chili-red); outline-offset: 3px; }
[aria-invalid="true"] { border-color: #b91c1c; }
.field-error { color: #991b1b; font-size: 14px; margin: 0; line-height: 1.5; }
.helper { color: var(--color-gray-600, #555); font-size: 14px; margin: 0; line-height: 1.5; }
.success { background: var(--color-success-bg, #f0fdf4); color: #166534; padding: 20px; border-radius: var(--radius-md); line-height: 1.6; }
.submit-btn { padding: 16px; width: 100%; }
.available-times { display: flex; flex-wrap: wrap; gap: 8px; }
.available-times button { padding: 8px 12px; border: 1px solid var(--color-gray-300, #ccc); border-radius: var(--radius-md); background: var(--color-white); color: var(--color-charcoal-black); font: inherit; cursor: pointer; }
.available-times button[aria-pressed="true"] { border-color: var(--color-chili-red); background: var(--color-chili-red); color: var(--color-white); font-weight: 700; }
button:disabled { opacity: .65; cursor: not-allowed; }
@media (max-width: 575px) { .fields { grid-template-columns: 1fr; } }
</style>
