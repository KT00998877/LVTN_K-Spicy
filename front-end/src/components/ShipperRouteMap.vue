<script setup lang="ts">
/**
 * ShipperRouteMap — Hiển thị bản đồ chỉ đường cho shipper.
 *
 * Vẽ:
 *  - Marker nhà hàng (điểm xuất phát) — bắt buộc hiển thị để shipper biết mình đang ở đâu
 *  - Marker khách (điểm đích)
 *  - Polyline đường đi (giải mã từ Google encoded polyline của OSRM)
 *  - Fit bounds tự động để thấy cả 2 điểm
 */
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { decodePolyline } from '@/utils/geocoding'
import type { DeliveryRoute } from '@/api/staff'

const props = defineProps<{ routeData: DeliveryRoute | null }>()

const container = ref<HTMLElement>()
let map: L.Map | undefined
let restaurantMarker: L.Marker | undefined
let customerMarker: L.Marker | undefined
let routeLine: L.Polyline | undefined
let alive = true

const restaurantIcon = L.divIcon({
    className: 'route-pin restaurant',
    html: '<span aria-hidden="true">🍜</span>',
    iconSize: [34, 40], iconAnchor: [17, 40],
})
const customerIcon = L.divIcon({
    className: 'route-pin customer',
    html: '<span aria-hidden="true">📍</span>',
    iconSize: [30, 36], iconAnchor: [15, 36],
})

function clear() {
    restaurantMarker?.remove(); customerMarker?.remove(); routeLine?.remove()
    restaurantMarker = customerMarker = routeLine = undefined
}

function draw(data: DeliveryRoute) {
    if (!map) return
    clear()
    const r = data.restaurant, c = data.customer

    restaurantMarker = L.marker([r.latitude, r.longitude], { icon: restaurantIcon, title: r.name }).addTo(map)
        .bindTooltip(`Quán · ${r.name}`, { permanent: false, direction: 'top' })
    customerMarker = L.marker([c.latitude, c.longitude], { icon: customerIcon, title: 'Vị trí khách' }).addTo(map)
        .bindTooltip(`Khách · ${c.name ?? ''}`, { permanent: false, direction: 'top' })

    if (data.route.polyline) {
        const pts = decodePolyline(data.route.polyline)
        if (pts.length > 1) {
            routeLine = L.polyline(pts, {
                color: '#d71920', weight: 5, opacity: 0.85, lineJoin: 'round',
            }).addTo(map)
        }
    }

    // Nếu fallback Haversine (không có polyline) → vẽ đường thẳng đứt nối để modal không trống.
    if (!routeLine) {
        routeLine = L.polyline(
            [[r.latitude, r.longitude], [c.latitude, c.longitude]],
            { color: '#d71920', weight: 4, opacity: 0.55, dashArray: '8 8' },
        ).addTo(map)
    }

    // Fit cả 2 marker (hoặc cả polyline nếu có)
    const bounds = L.latLngBounds([
        [r.latitude, r.longitude],
        [c.latitude, c.longitude],
    ])
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 })
}

onMounted(() => {
    if (!container.value) return
    map = L.map(container.value).setView([10.0308, 105.7689], 14)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
        maxZoom: 19,
    }).addTo(map)
    if (props.routeData) draw(props.routeData)
})
watch(() => props.routeData, value => { if (value) draw(value) })
onBeforeUnmount(() => { alive = false; clear(); map?.remove() })
</script>

<template>
    <div class="shipper-route-map">
        <div ref="container" class="map" aria-label="Bản đồ hướng dẫn đường đi"></div>
        <div v-if="routeData" class="legend">
            <span><span class="dot restaurant">🍜</span> Quán</span>
            <span><span class="dot customer">📍</span> Khách</span>
            <span class="line"></span> Đường đi ô tô
        </div>
    </div>
</template>

<style scoped>
.shipper-route-map { display: grid; gap: 8px; }
.map { height: 320px; border-radius: 12px; z-index: 0; border: 1px solid var(--color-gray-200, #eee); }
.legend {
    display: flex; align-items: center; gap: 14px; font-size: 12px;
    color: var(--color-gray-600, #555); flex-wrap: wrap;
}
.legend .dot { font-size: 18px; }
.legend .line {
    display: inline-block; width: 24px; height: 3px; background: #d71920;
    border-radius: 2px;
}
:deep(.route-pin) { font-size: 22px; background: none; border: 0; }
:deep(.route-pin.restaurant) { filter: drop-shadow(0 1px 2px rgba(0,0,0,.2)); }
</style>
