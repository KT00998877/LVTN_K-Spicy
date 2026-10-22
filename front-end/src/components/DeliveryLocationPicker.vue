<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { reverseGeocode } from '@/utils/geocoding'

const props = defineProps<{
    selected?: { latitude: number; longitude: number } | null
    disabled?: boolean
    /** Địa chỉ đã có sẵn (chỉ để hiển thị readonly). Không sửa địa chỉ này từ picker. */
    initialAddress?: string
}>()
const emit = defineEmits<{
    /** Phát khi toạ độ thay đổi. */
    change: [point: { latitude: number; longitude: number; address: string }]
    /** Phát khi chỉ địa chỉ hiển thị thay đổi (sau khi reverse geocode). */
    'update:address': [address: string]
}>()

const container = ref<HTMLElement>()
const error = ref('')
const locating = ref(false)
const resolvingAddress = ref(false)
const lat = ref<number | null>(null)
const lng = ref<number | null>(null)
const resolvedAddress = ref('')
let map: L.Map | undefined
let pin: L.Marker | undefined
let alive = true
let geocodeController: AbortController | undefined
let lastEmittedCoords = ''
const icon = L.divIcon({ className: 'delivery-pin', html: '<span aria-hidden="true">📍</span>', iconSize: [30, 36], iconAnchor: [15, 36] })

function isSameCoordinate(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }) {
    return Math.abs(a.latitude - b.latitude) < 1e-6 && Math.abs(a.longitude - b.longitude) < 1e-6
}

async function resolveAndEmit(latitude: number, longitude: number) {
    const key = `${latitude.toFixed(6)},${longitude.toFixed(6)}`
    if (key === lastEmittedCoords) return
    lastEmittedCoords = key

    // Lần đầu: gửi toạ độ ngay để quote hiển thị phí ship; địa chỉ sẽ cập nhật khi reverse geocode xong.
    emit('change', { latitude, longitude, address: resolvedAddress.value })

    if (geocodeController) geocodeController.abort()
    geocodeController = new AbortController()
    resolvingAddress.value = true
    const result = await reverseGeocode(latitude, longitude, geocodeController.signal)
    resolvingAddress.value = false
    if (!alive || geocodeController.signal.aborted) return
    if (result && result.display !== resolvedAddress.value) {
        resolvedAddress.value = result.display
        emit('change', { latitude, longitude, address: result.display })
        emit('update:address', result.display)
    }
}

function select(latitude: number, longitude: number) {
    if (!alive || props.disabled || !map) return
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
        error.value = 'Vĩ độ phải từ -90 đến 90; kinh độ từ -180 đến 180.'
        return
    }
    lat.value = latitude
    lng.value = longitude
    error.value = ''
    if (pin) pin.setLatLng([latitude, longitude])
    else {
        pin = L.marker([latitude, longitude], { icon, draggable: true, title: 'Vị trí giao hàng' }).addTo(map)
        pin.on('dragend', () => {
            const point = pin!.getLatLng()
            select(point.lat, point.lng)
        })
    }
    map.panTo([latitude, longitude])
    resolveAndEmit(latitude, longitude)
}

function locate() {
    if (!navigator.geolocation) {
        error.value = 'Thiết bị không hỗ trợ định vị. Hãy chọn trên bản đồ hoặc nhập tọa độ.'
        return
    }
    locating.value = true
    navigator.geolocation.getCurrentPosition(position => {
        locating.value = false
        select(position.coords.latitude, position.coords.longitude)
    }, () => {
        locating.value = false
        error.value = 'Không lấy được vị trí. Hãy cấp quyền định vị hoặc chọn vị trí trên bản đồ.'
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 })
}

onMounted(() => {
    if (!container.value) return
    resolvedAddress.value = props.initialAddress ?? ''
    // Mặc định bản đồ mở ở Đại học Cần Thơ (10.0308, 105.7689) — sẽ tự panTo khi có vị trí khách.
    map = L.map(container.value).setView([10.0308, 105.7689], 13)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', maxZoom: 19,
    }).addTo(map).on('tileerror', () => { error.value = 'Không tải được bản đồ. Bạn vẫn có thể dùng GPS hoặc nhập tọa độ.' })
    map.on('click', e => select(e.latlng.lat, e.latlng.lng))
    if (props.selected) select(props.selected.latitude, props.selected.longitude)
    else if (resolvedAddress.value) emit('update:address', resolvedAddress.value)
})
watch(() => props.disabled, disabled => { if (disabled) pin?.dragging?.disable(); else pin?.dragging?.enable() })
watch(() => props.selected, selected => {
    if (selected && !isSameCoordinate(selected, { latitude: lat.value ?? NaN, longitude: lng.value ?? NaN }) && map) {
        select(selected.latitude, selected.longitude)
    }
})
onBeforeUnmount(() => { alive = false; geocodeController?.abort(); map?.remove() })
</script>

<template>
    <div class="location-picker">
        <div class="actions">
            <button type="button" class="btn" :disabled="disabled || locating" @click="locate">
                <span aria-hidden="true"></span>
                {{ locating ? 'Đang lấy vị trí…' : 'Dùng vị trí hiện tại của tôi' }}
            </button>
            <span v-if="resolvingAddress" class="status-inline" role="status">Đang tra địa chỉ…</span>
        </div>
        <div ref="container" class="delivery-map" aria-label="Bản đồ chọn điểm giao hàng"></div>

        <div v-if="resolvedAddress" class="resolved-address" role="status">
            <strong>Địa chỉ nhận hàng:</strong>
            <span>{{ resolvedAddress }}</span>
        </div>
        <p v-else class="hint">Chạm trên bản đồ hoặc kéo ghim để chọn nơi nhận hàng.</p>

        <!-- <details>
            <summary>Nhập tọa độ thay cho chọn bản đồ</summary>
            <label>Vĩ độ <input v-model.number="lat" type="number" step="any" min="-90" max="90" :disabled="disabled" /></label>
            <label>Kinh độ <input v-model.number="lng" type="number" step="any" min="-180" max="180" :disabled="disabled" /></label>
            <button type="button" :disabled="disabled || lat === null || lng === null" @click="select(Number(lat), Number(lng))">Ghim tọa độ</button>
        </details> -->
        <!-- <p v-if="lat !== null && lng !== null" class="coords" role="status">
            Toạ độ: {{ Number(lat).toFixed(6) }}, {{ Number(lng).toFixed(6) }}
        </p> -->
        <p v-if="error" class="error" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.location-picker { display: grid; gap: 10px; font-size: 14px; }
.location-picker p { margin: 0; }
.actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.btn {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--color-chili-red, #d71920);
    background: var(--color-chili-red, #d71920);
    color: #fff;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn:disabled { opacity: .55; cursor: not-allowed; }
.status-inline { color: var(--color-gray-500, #777); font-size: 13px; }
.delivery-map { height: 280px; border-radius: 12px; z-index: 0; }
.resolved-address {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 14px;
    line-height: 1.5;
}
.hint { color: var(--color-gray-500, #777); font-size: 13px; }
.coords { color: var(--color-gray-500, #777); font-size: 12px; }
.error { color: #991b1b; font-size: 13px; }
details summary { cursor: pointer; color: var(--color-gray-600, #555); font-size: 13px; }
:deep(.delivery-pin) { font-size: 28px; background: none; border: 0; }
</style>
