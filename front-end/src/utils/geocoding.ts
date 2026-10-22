/**
 * Geocoding service dùng Nominatim (OpenStreetMap).
 *
 * - Free tier, không cần API key.
 * - Usage policy: tối đa 1 request/giây, phải có User-Agent hợp lệ.
 *   https://operations.osmfoundation.org/policies/nominatim/
 *
 * Lưu ý tiếng Việt: ta dùng `accept-language=vi` để ưu tiên kết quả tiếng Việt
 * (số nhà, tên đường, phường/xã, quận/huyện, tỉnh/thành).
 */

export interface ReverseGeocodeResult {
    /** Địa chỉ hiển thị đầy đủ, dùng để điền vào ô "Địa chỉ giao hàng". */
    display: string
    /** Các thành phần địa chỉ rời rạc, dùng để debug / ghi log. */
    components: {
        house_number?: string
        road?: string
        neighbourhood?: string
        suburb?: string
        ward?: string
        city_district?: string
        city?: string
        state?: string
        country?: string
        postcode?: string
    }
}

/** Tách các phần địa chỉ từ raw Nominatim `address` object thành chuỗi dễ đọc tiếng Việt. */
function formatVietnameseAddress(raw: Record<string, string>): ReverseGeocodeResult {
    const house = raw.house_number?.trim()
    const road = raw.road || raw.pedestrian || raw.path || raw.footway
    const ward = raw.ward || raw.suburb || raw.quarter || raw.neighbourhood
    const district = raw.city_district || raw.county || raw.district
    const city = raw.city || raw.town || raw.state || raw.province
    const country = raw.country

    // Thứ tự ưu tiên: số nhà + đường → phường/xã → quận/huyện → tỉnh/thành
    const parts: string[] = []
    const street = [house, road].filter(Boolean).join(' ').trim()
    if (street) parts.push(street)
    if (ward) parts.push(ward)
    if (district && district !== city) parts.push(district)
    if (city) parts.push(city)
    if (country && country !== 'Việt Nam' && country !== 'Vietnam') parts.push(country)

    const display = parts.join(', ').replace(/\s+/g, ' ').trim()
    return {
        display: display || 'Vị trí đã chọn trên bản đồ',
        components: {
            house_number: house,
            road, neighbourhood: raw.neighbourhood, suburb: raw.suburb,
            ward, city_district: district, city, state: raw.state,
            country, postcode: raw.postcode,
        },
    }
}

let lastReverseAt = 0

/**
 * Reverse geocode: toạ độ → địa chỉ.
 *
 * - Tự debounce theo rate-limit Nominatim (≥ 1 giây giữa 2 request).
 * - Trả về null nếu lỗi mạng / API / không tìm thấy.
 */
export async function reverseGeocode(latitude: number, longitude: number, signal?: AbortSignal): Promise<ReverseGeocodeResult | null> {
    const wait = Math.max(0, 1100 - (Date.now() - lastReverseAt))
    await new Promise(resolve => setTimeout(resolve, wait))
    if (signal?.aborted) return null

    const url = new URL('https://nominatim.openstreetmap.org/reverse')
    url.searchParams.set('format', 'jsonv2')
    url.searchParams.set('lat', latitude.toString())
    url.searchParams.set('lon', longitude.toString())
    url.searchParams.set('accept-language', 'vi')
    url.searchParams.set('addressdetails', '1')
    url.searchParams.set('zoom', '18')

    try {
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            signal,
        })
        lastReverseAt = Date.now()
        if (!response.ok) return null
        const data = await response.json() as { display_name?: string; address?: Record<string, string> }
        if (!data.address) return null
        // Ưu tiên format tiếng Việt có cấu trúc; fallback về display_name của Nominatim.
        const formatted = formatVietnameseAddress(data.address)
        if (formatted.display && formatted.display !== 'Vị trí đã chọn trên bản đồ') return formatted
        if (data.display_name) return { display: data.display_name, components: {} }
        return null
    } catch {
        return null
    }
}

/**
 * Giải mã Google encoded polyline precision 5 (chuẩn OSRM cũng dùng).
 * Trả về danh sách [lat, lng] để vẽ lên Leaflet.
 *
 * Tham khảo: https://developers.google.com/maps/documentation/utilities/polylinealgorithm
 */
export function decodePolyline(encoded: string): [number, number][] {
    const points: [number, number][] = []
    let index = 0
    let lat = 0
    let lng = 0
    while (index < encoded.length) {
        let shift = 0
        let result = 0
        let byte: number
        do {
            byte = encoded.charCodeAt(index++) - 63
            result |= (byte & 0x1f) << shift
            shift += 5
        } while (byte >= 0x20)
        const dlat = (result & 1) ? ~(result >> 1) : (result >> 1)
        lat += dlat

        shift = 0
        result = 0
        do {
            byte = encoded.charCodeAt(index++) - 63
            result |= (byte & 0x1f) << shift
            shift += 5
        } while (byte >= 0x20)
        const dlng = (result & 1) ? ~(result >> 1) : (result >> 1)
        lng += dlng

        points.push([lat / 1e5, lng / 1e5])
    }
    return points
}
