/**
 * Helper build URL cho ảnh do backend serve.
 *
 * Backend lưu ảnh trong `storage/app/public/` và symlink qua `public/storage/`.
 * Khi backend trả relative path kiểu `/storage/menu/xxx.png`, helper này sẽ:
 *   - Trả về relative path `/storage/menu/xxx.png` (Vite proxy tự forward tới backend)
 *   - Fallback về http://127.0.0.1:8000 nếu proxy không hoạt động
 *
 * Nếu URL đã là http(s) hoặc data URI thì trả về nguyên bản.
 */

// Fallback: gọi thẳng backend khi không chạy qua Vite dev server.
// Production: VITE_API_BASE_URL = https://api.kspicy.vn/api  (backend phục vụ /storage trên cùng origin)
// Dev: VITE_API_BASE_URL = http://127.0.0.1:8000/api
const FALLBACK_API_ORIGIN = (import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000')
    .replace(/\/api\/?$/, '')   // strip /api suffix

export function imageUrl(path: string | null | undefined): string {
    if (!path) return ''
    // Đã là absolute URL
    if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:')) {
        return path
    }
    // Relative path bắt đầu bằng / — ưu tiên giữ nguyên để đi qua Vite proxy /storage
    // (cùng origin với frontend nên không bị CORS).
    if (path.startsWith('/storage/')) {
        return path
    }
    // Các relative path khác: fallback về backend origin
    return FALLBACK_API_ORIGIN + (path.startsWith('/') ? path : '/' + path)
}
