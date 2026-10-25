import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import apiClient from '@/api/client'

/**
 * Store vị trí shipper (Phase 2).
 *
 * Shipper gửi vị trí hiện tại lên backend theo chu kỳ để hệ thống tìm được
 * shipper gần nhà hàng nhất khi duyệt đơn ONLINE.
 *
 * Chiến lược tiết kiệm pin:
 *  - `watchPosition` theo dõi liên tục, nhưng chỉ GỬI khi:
 *      • đã qua `SEND_INTERVAL_MS` (60s), HOẶC
 *      • đã di chuyển > `MIN_MOVE_METERS` (200m) so với lần gửi gần nhất.
 *  - Dừng theo dõi khi tab ẩn (`visibilitychange`) để không đốt pin nền.
 *  - Có nút cập nhật thủ công dự phòng khi GPS bị từ chối.
 */
export interface ShipperLocationState {
    latitude: number
    longitude: number
    accuracy: number | null
    lastSentAt: string | null
}

const ENDPOINT = '/staff/deliveries/location'

export const useShipperLocationStore = defineStore('shipperLocation', () => {
    const latitude = ref<number | null>(null)
    const longitude = ref<number | null>(null)
    const accuracy = ref<number | null>(null)
    const lastSentAt = ref<string | null>(null)
    const lastError = ref('')
    const sending = ref(false)
    const watching = ref(false)
    const permissionDenied = ref(false)

    /** Chu kỳ gửi tối thiểu. */
    const SEND_INTERVAL_MS = 60_000
    /** Ngưỡng di chuyển để gửi sớm. */
    const MIN_MOVE_METERS = 200

    let watchId: number | null = null
    let lastSent: ShipperLocationState | null = null

    const hasFix = computed(() => latitude.value !== null && longitude.value !== null)

    /** Khoảng cách (mét) giữa 2 toạ độ — Haversine rút gọn. */
    function distanceMeters(lat1: number, lng1: number, lat2: number, lng2: number): number {
        const R = 6371000
        const toRad = (d: number) => (d * Math.PI) / 180
        const dLat = toRad(lat2 - lat1)
        const dLng = toRad(lng2 - lng1)
        const a =
            Math.sin(dLat / 2) ** 2 +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2
        return 2 * R * Math.asin(Math.sqrt(Math.min(1, Math.max(0, a))))
    }

    /** Có đáng gửi lên không (đủ hạn hoặc đã đi xa). */
    function shouldSend(): boolean {
        if (!lastSent) return true
        const elapsed = Date.now() - new Date(lastSent.lastSentAt ?? 0).getTime()
        if (elapsed >= SEND_INTERVAL_MS) return true
        const moved = distanceMeters(
            lastSent.latitude,
            lastSent.longitude,
            latitude.value ?? lastSent.latitude,
            longitude.value ?? lastSent.longitude,
        )
        return moved >= MIN_MOVE_METERS
    }

    /** Gửi vị trí hiện tại lên backend. */
    async function send(): Promise<boolean> {
        if (latitude.value === null || longitude.value === null) return false
        if (sending.value) return false

        sending.value = true
        try {
            await apiClient.post(ENDPOINT, {
                latitude: latitude.value,
                longitude: longitude.value,
                accuracy: accuracy.value,
            })
            lastSent = {
                latitude: latitude.value,
                longitude: longitude.value,
                accuracy: accuracy.value,
                lastSentAt: new Date().toISOString(),
            }
            lastSentAt.value = lastSent.lastSentAt
            lastError.value = ''
            return true
        } catch {
            lastError.value = 'Không gửi được vị trí. Sẽ thử lại lần sau.'
            return false
        } finally {
            sending.value = false
        }
    }

    /** Xử lý vị trí mới từ watchPosition. */
    function onPosition(position: GeolocationPosition) {
        latitude.value = position.coords.latitude
        longitude.value = position.coords.longitude
        accuracy.value = position.coords.accuracy

        if (shouldSend()) void send()
    }

    /** Lỗi GPS: báo rõ cho shipper biết cần bật quyền. */
    function onError(error: GeolocationPositionError) {
        if (error.code === error.PERMISSION_DENIED) {
            permissionDenied.value = true
            lastError.value = 'Trình duyệt đang chặn vị trí. Hãy bật quyền vị trí để nhận đơn gần nhất.'
        } else {
            lastError.value = 'Chưa lấy được vị trí. Sẽ thử lại.'
        }
    }

    /**
     * Bật theo dõi vị trị liên tục.
     * Trả về false nếu trình duyệt không hỗ trợ.
     */
    function watchPosition(): boolean {
        if (!('geolocation' in navigator)) {
            lastError.value = 'Trình duyệt này không hỗ trợ định vị.'
            return false
        }
        if (watchId !== null) return true

        watchId = navigator.geolocation.watchPosition(
            onPosition,
            onError,
            { enableHighAccuracy: true, maximumAge: 15_000, timeout: 20_000 },
        )
        watching.value = true
        return true
    }

    /** Dừng theo dõi (tiết kiệm pin). */
    function stopWatching() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId)
            watchId = null
        }
        watching.value = false
    }

    /**
     * Gửi vị trí 1 lần (nút thủ công / dự phòng khi GPS bị từ chối).
     */
    function requestOnce(): void {
        if (!('geolocation' in navigator)) {
            lastError.value = 'Trình duyệt này không hỗ trợ định vị.'
            return
        }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                latitude.value = position.coords.latitude
                longitude.value = position.coords.longitude
                accuracy.value = position.coords.accuracy
                void send()
            },
            onError,
            { enableHighAccuracy: true, timeout: 20_000 },
        )
    }

    function reset() {
        stopWatching()
        latitude.value = null
        longitude.value = null
        accuracy.value = null
        lastSentAt.value = null
        lastSent = null
        lastError.value = ''
        permissionDenied.value = false
        sending.value = false
    }

    return {
        latitude, longitude, accuracy, lastSentAt, lastError, sending, watching, permissionDenied,
        hasFix, watchPosition, stopWatching, requestOnce, send, reset,
    }
})
