<template>
    <section class="table-schedule" aria-label="Lịch sử dụng bàn">
        <div class="schedule-toolbar">
            <label>Ngày xem lịch
                <input v-model="date" type="date" required />
            </label>
            <label>Tầng
                <select v-model="floor">
                    <option value="">Tất cả các tầng</option>
                    <option v-for="location in floors" :key="location">{{ location }}</option>
                </select>
            </label>
            <label>Khung giờ
                <select v-model="slot" :disabled="!options">
                    <option value="">Cả ngày</option>
                    <option v-for="start in slots" :key="start" :value="start">
                        {{ clock(start) }} – {{ clock(Math.min(start + 30, closing)) }}
                    </option>
                </select>
            </label>
            <button type="button" :disabled="busy || (!date && !error)" @click="reload()">Làm mới</button>
            <RouterLink class="schedule-link" :to="{ name: reservationRoute }">Xử lý đặt bàn</RouterLink>
        </div>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-else-if="!date && options && !busy" role="status">Chọn ngày để xem lịch bàn.</p>
        <p v-else-if="!snapshot || snapshot.date !== date" role="status">Đang tải lịch bàn…</p>
        <template v-else>
            <p class="schedule-note">
                Khung đặt dự kiến tính {{ bookingMinutes }} phút từ giờ hẹn, gồm thời gian dùng và dọn bàn.
                Với bàn đang bận hôm nay, hãy kiểm tra tại quán trước khi xếp khách.
            </p>
            <p v-if="pastRange" class="schedule-note">Khung giờ đã qua. Có thể xem lịch đặt, tình trạng bàn tại thời điểm đó chưa được lưu trong lịch này.</p>
            <div v-if="rows.length" class="schedule-table" tabindex="0" role="region" aria-label="Danh sách bàn và khung giờ">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Bàn</th>
                            <th v-if="isToday" scope="col">Tại quán hiện giờ</th>
                            <th scope="col">Trống theo lịch</th>
                            <th scope="col">Khách đặt bàn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.table.id">
                        <tr>
                            <th scope="row">
                                <button
                                    type="button"
                                    class="table-toggle"
                                    :aria-expanded="selectedTable === row.table.id"
                                    :aria-controls="selectedTable === row.table.id ? 'schedule-slots-' + row.table.id : undefined"
                                    @click="selectedTable = selectedTable === row.table.id ? null : row.table.id"
                                >
                                    {{ row.table.code }} <span aria-hidden="true">{{ selectedTable === row.table.id ? '▴' : '▾' }}</span>
                                </button>
                                <small>{{ row.table.location || 'Chưa phân tầng' }} · {{ row.table.capacity }} chỗ</small>
                            </th>
                            <td v-if="isToday">
                                <span class="schedule-state" :class="{ busy: row.table.status !== 'AVAILABLE' }">
                                    {{ tableLabels[row.table.status] }}
                                </span>
                                <small v-if="row.session">{{ row.session.guest_count }} khách · từ {{ sessionTime(row.session.opened_at) }}</small>
                            </td>
                            <td>
                                <span v-if="pastRange">Đã qua</span>
                                <span v-else-if="row.unknown" class="schedule-state busy">Chưa rõ giờ trống</span>
                                <ul v-else-if="row.free.length" class="schedule-list">
                                    <li v-for="range in row.free" :key="range.start">
                                        <span class="schedule-state">{{ clock(range.start) }} – {{ clock(range.end) }}</span>
                                    </li>
                                </ul>
                                <span v-else>Đã kín lịch</span>
                            </td>
                            <td>
                                <ul v-if="row.bookings.length" class="schedule-list">
                                    <li v-for="booking in row.bookings" :key="booking.id">
                                        <RouterLink :to="{ name: reservationRoute, query: { reservation_id: booking.id } }">
                                            {{ booking.reserved_time }} · {{ booking.customer_name }} · {{ booking.guest_count }} khách
                                        </RouterLink>
                                        <small>{{ reservationLabels[booking.status] }}</small>
                                        <small v-if="row.unknown && needsAttention(booking)" class="schedule-attention">Cần kiểm tra bàn trước giờ hẹn</small>
                                    </li>
                                </ul>
                                <span v-else>Không có lịch đặt</span>
                                <details v-for="group in row.released" :key="group.status" class="cancelled-bookings">
                                    <summary>{{ reservationLabels[group.status] }} ({{ group.bookings.length }})</summary>
                                    <ul class="schedule-list">
                                        <li v-for="booking in group.bookings" :key="booking.id">
                                            <RouterLink :to="{ name: reservationRoute, query: { reservation_id: booking.id } }">
                                                {{ booking.reserved_time }} · {{ booking.customer_name }} · {{ booking.guest_count }} khách
                                            </RouterLink>
                                        </li>
                                    </ul>
                                </details>
                            </td>
                        </tr>
                        <tr v-if="selectedTable === row.table.id" class="slot-row">
                            <td :colspan="isToday ? 4 : 3">
                                <section :id="'schedule-slots-' + row.table.id" :aria-label="'Khung giờ bàn ' + row.table.code">
                                    <h3>Bàn {{ row.table.code }} · {{ displayDate }}</h3>
                                    <ul class="slot-grid">
                                        <li v-for="item in selectedSlots" :key="item.start" :class="'slot-' + item.state">
                                            <strong>{{ clock(item.start) }}</strong>
                                            <small>{{ slotLabels[item.state] }}</small>
                                            <RouterLink
                                                v-for="booking in item.bookings"
                                                :key="booking.id"
                                                :to="{ name: reservationRoute, query: { reservation_id: booking.id } }"
                                            >
                                                <span v-if="booking.reserved_time !== clock(item.start)">{{ booking.reserved_time }} · </span>
                                                {{ booking.customer_name }} · {{ booking.guest_count }} khách
                                            </RouterLink>
                                        </li>
                                    </ul>
                                </section>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-else role="status">Không có bàn trong tầng đang chọn.</p>
            <details v-if="unassigned.length" class="schedule-unassigned" open>
                <summary>{{ unassigned.length }} lượt đặt chưa có bàn hoạt động</summary>
                <ul class="schedule-list">
                    <li v-for="booking in unassigned" :key="booking.id">
                        <RouterLink :to="{ name: reservationRoute, query: { reservation_id: booking.id } }">
                            {{ booking.reserved_time }} · {{ booking.customer_name }} · {{ booking.guest_count }} khách
                        </RouterLink>
                        <small>{{ reservationLabels[booking.status] }}<template v-if="booking.table_code"> · Bàn {{ booking.table_code }}</template></small>
                    </li>
                </ul>
            </details>
        </template>
    </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getReservationOptions } from '@/api/reservations'
import { staffReservations, staffTables, type Reservation, type RestaurantTable, type TablesResponse } from '@/api/staff'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { restaurantNow, type ReservationOptions } from '@/utils/reservation'

const route = useRoute()
const reservationRoute = computed(() => route.name === 'manager-table-schedule' ? 'manager-reservations' : 'cashier-reservations')
const tableLabels: Record<RestaurantTable['status'], string> = {
    AVAILABLE: 'Đang trống', OCCUPIED: 'Đang phục vụ', WAITING_PAYMENT: 'Chờ thanh toán',
    CLEANING: 'Đang dọn', RESERVED: 'Đang giữ bàn',
}
const reservationLabels: Record<Reservation['status'], string> = {
    PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận', ARRIVED: 'Khách đã đến',
    CANCELLED: 'Đã hủy', COMPLETED: 'Hoàn tất', NO_SHOW: 'Khách không đến',
}
const options = ref<ReservationOptions>()
const date = ref('')
const floor = ref('')
const slot = ref<number | ''>('')
const selectedTable = ref<number | null>(null)
const localNow = ref('')
const snapshot = ref<{ date: string; live: TablesResponse; bookings: Reservation[] }>()
const busy = ref(false)
const error = ref('')
let loadId = 0

function minutes(time: string) {
    return Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5))
}

function clock(value: number) {
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`
}

function sessionTime(openedAt: string) {
    return options.value ? restaurantNow(options.value.timezone, Date.parse(openedAt)).slice(11, 16) : ''
}

const opening = computed(() => minutes(options.value?.opens_at ?? '00:00'))
const closing = computed(() => minutes(options.value?.closes_at ?? '00:00'))
const bookingMinutes = computed(() => Math.max(70, options.value?.hold_minutes ?? 0))
const slots = computed(() => Array.from({ length: Math.ceil((closing.value - opening.value) / 30) }, (_, index) => opening.value + index * 30))
const today = computed(() => localNow.value.slice(0, 10))
const isToday = computed(() => date.value === today.value)
const rangeStart = computed(() => slot.value === '' ? opening.value : slot.value)
const rangeEnd = computed(() => slot.value === '' ? closing.value : Math.min(slot.value + 30, closing.value))
const freeStart = computed(() => isToday.value ? Math.max(rangeStart.value, minutes(localNow.value.slice(11, 16))) : rangeStart.value)
const pastRange = computed(() => date.value < today.value || freeStart.value >= rangeEnd.value)
const floors = computed(() => [...new Set(snapshot.value?.live.tables.flatMap(table => table.location ? [table.location] : []))].sort())
const bookingsInRange = computed(() => (snapshot.value?.bookings ?? [])
    .filter(booking => slot.value === '' || (
        minutes(booking.reserved_time) < rangeEnd.value &&
        minutes(booking.reserved_time) + bookingMinutes.value > rangeStart.value
    ))
    .sort((first, second) => first.reserved_time.localeCompare(second.reserved_time)))
const unassigned = computed(() => {
    const activeTables = new Set(snapshot.value?.live.tables.map(table => table.id))
    return bookingsInRange.value.filter(booking => !activeTables.has(booking.table_id ?? -1))
})

function holdsTable(booking: Reservation) {
    return ['PENDING', 'CONFIRMED', 'ARRIVED'].includes(booking.status)
}

function needsAttention(booking: Reservation) {
    return holdsTable(booking) && booking.status !== 'ARRIVED' &&
        minutes(booking.reserved_time) + bookingMinutes.value > minutes(localNow.value.slice(11, 16))
}

const rows = computed(() => {
    const sessions = new Map(snapshot.value?.live.sessions.map(session => [session.table_id, session]))
    return (snapshot.value?.live.tables ?? [])
        .filter(table => !floor.value || table.location === floor.value)
        .map(table => {
            const bookings = bookingsInRange.value.filter(booking => booking.table_id === table.id)
            const session = sessions.get(table.id)
            const unknown = isToday.value && (table.status !== 'AVAILABLE' || !!session)
            const free: { start: number; end: number }[] = []
            if (!unknown && !pastRange.value) {
                let cursor = freeStart.value
                for (const booking of bookings) {
                    if (!holdsTable(booking)) continue
                    const start = minutes(booking.reserved_time)
                    if (cursor < start) free.push({ start: cursor, end: Math.min(start, rangeEnd.value) })
                    cursor = Math.max(cursor, start + bookingMinutes.value)
                }
                if (cursor < rangeEnd.value) free.push({ start: cursor, end: rangeEnd.value })
            }
            const releasedStatuses = ['CANCELLED', 'NO_SHOW'] as const
            return {
                table, session, unknown, free,
                bookings: bookings.filter(booking => booking.status !== 'CANCELLED' && booking.status !== 'NO_SHOW'),
                released: releasedStatuses.map(status => ({
                    status, bookings: bookings.filter(booking => booking.status === status),
                })).filter(group => group.bookings.length),
            }
        })
})

const displayDate = computed(() => date.value.split('-').reverse().join('/'))
const slotLabels = {
    booked: 'Đã đặt', unknown: 'Chưa rõ giờ trống',
    past: 'Đã qua', free: 'Chưa có lịch đặt',
}
const selectedSlots = computed(() => {
    const row = rows.value.find(item => item.table.id === selectedTable.value)
    if (!row) return []
    const bookings = (snapshot.value?.bookings ?? [])
        .filter(booking => booking.table_id === row.table.id && (holdsTable(booking) || booking.status === 'COMPLETED'))
    const currentMinute = minutes(localNow.value.slice(11, 16))
    return slots.value.map(start => {
        const end = Math.min(start + 30, closing.value)
        const starting = bookings.filter(booking => {
            const at = minutes(booking.reserved_time)
            return at >= start && at < end
        })
        let state: keyof typeof slotLabels = 'free'
        if (starting.length) state = 'booked'
        else if (date.value < today.value || (isToday.value && end <= currentMinute)) state = 'past'
        else if (row.unknown) state = 'unknown'
        return { start, state, bookings: starting }
    })
})

async function reload() {
    const id = ++loadId
    busy.value = true
    const requestedDate = date.value
    try {
        const settings = options.value ?? await getReservationOptions()
        if (id !== loadId) return
        options.value = settings
        const now = restaurantNow(settings.timezone)
        const target = requestedDate || now.slice(0, 10)
        const [live, bookings] = await Promise.all([staffTables.list(), staffReservations.list(target)])
        if (id !== loadId) return
        localNow.value = restaurantNow(settings.timezone)
        snapshot.value = { date: target, live, bookings }
        if (!date.value) date.value = target
        if (floor.value && !floors.value.includes(floor.value)) floor.value = ''
        error.value = ''
    } catch {
        if (id === loadId) error.value = 'Không tải được lịch bàn. Bấm Làm mới để thử lại.'
    } finally {
        if (id === loadId) busy.value = false
    }
}

watch(date, value => {
    if (value && value !== snapshot.value?.date) {
        error.value = ''
        void reload()
    } else {
        ++loadId
        busy.value = false
        error.value = ''
    }
}, { flush: 'sync' })
onMounted(reload)
useRealtimeRefresh(reload, () => !!date.value && !busy.value, 15000)
</script>

<style scoped>
.table-schedule { min-width: 0; color: #273342; }
.schedule-toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; }
.schedule-toolbar label { display: grid; gap: 6px; font-size: .875rem; font-weight: 600; }
.schedule-toolbar input, .schedule-toolbar select, .schedule-toolbar button, .schedule-link {
    min-height: 42px; padding: 8px 12px; border: 1px solid #bdc7d3; border-radius: 8px;
    background: #fff; color: inherit; font: inherit; box-sizing: border-box;
}
.schedule-toolbar button { cursor: pointer; }
.schedule-toolbar button:disabled { opacity: .65; cursor: default; }
.schedule-link { display: inline-flex; align-items: center; font-weight: 600; }
.schedule-note { margin: 14px 0; color: #465466; font-size: .875rem; }
.schedule-table { container-type: inline-size; overflow-x: auto; border: 1px solid #d8dee7; border-radius: 10px; background: #fff; }
table { border-collapse: collapse; width: 100%; min-width: 600px; font-variant-numeric: tabular-nums; }
th, td { padding: 12px 16px; text-align: left; vertical-align: top; }
thead { background: #edf1f6; }
tbody tr + tr { border-top: 1px solid #e0e5ed; }
tbody tr:hover { background: #f7f9fb; }
small { display: block; margin-top: 4px; color: #465466; font-weight: 400; }
.schedule-list { display: grid; gap: 8px; padding: 0; margin: 0; list-style: none; }
.cancelled-bookings { margin-top: 8px; color: #465466; font-size: .875rem; }
.cancelled-bookings summary {
    width: fit-content; padding: 4px 8px; border: 1px solid #bdc7d3;
    border-radius: 6px; background: #edf1f6; cursor: pointer;
}
.cancelled-bookings ul { margin-top: 8px; }
.schedule-state { display: inline-block; padding: 3px 8px; border-radius: 5px; background: #e5f3ea; color: #19563a; font-weight: 600; }
.schedule-state.busy { background: #fff0d9; color: #70420e; }
.schedule-attention { font-weight: 600; color: #70420e; }
.table-toggle {
    display: inline-flex; align-items: center; gap: 8px; min-height: 36px;
    padding: 4px 10px; border: 1px solid #bdc7d3; border-radius: 6px;
    background: #fff; color: #174b84; font: inherit; font-weight: 700; cursor: pointer;
}
.table-toggle[aria-expanded="true"] { background: #eaf2fb; border-color: #5c80a7; }
.slot-row, .slot-row:hover { background: #f7f9fb; }
.slot-row section { max-width: calc(100cqi - 32px); }
.slot-row h3 { margin: 0 0 10px; font-size: 1rem; }
.slot-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 8px; padding: 0; margin: 0; list-style: none;
}
.slot-grid li {
    min-width: 0; padding: 8px; border: 1px solid #bdc7d3; border-radius: 8px;
    background: #fff; overflow-wrap: anywhere;
}
.slot-grid small { margin-top: 2px; }
.slot-grid .slot-booked { background: #fde7e7; border-color: #d56060; color: #8b2020; }
.slot-booked small, .slot-grid .slot-booked a { color: #8b2020; }
.slot-grid a { display: block; margin-top: 4px; font-size: .8125rem; }
.slot-grid .slot-past, .slot-grid .slot-unknown { background: #edf1f6; }
.table-schedule a { color: #174b84; text-underline-offset: 2px; }
.table-schedule :focus-visible { outline: 2px solid #174b84; outline-offset: 2px; }
.schedule-unassigned { margin-top: 16px; padding: 12px 16px; border: 1px solid #d8dee7; border-radius: 8px; }
.schedule-unassigned summary { font-weight: 600; cursor: pointer; }
.schedule-unassigned ul { margin-top: 10px; }
</style>
