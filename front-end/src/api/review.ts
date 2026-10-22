import apiClient from './client'
import type {
    Review,
    ReviewCreatePayload,
    ReviewEligibility,
    ReviewListResponse,
    ReviewSummary,
    ReviewUpdatePayload,
} from '@/types/review'

/**
 * Lấy reviews của 1 món (kèm summary).
 * Endpoint: GET /api/menu-items/{id}/reviews
 */
export async function fetchReviews(menuItemId: number, page = 1, perPage = 10): Promise<ReviewListResponse> {
    const { data } = await apiClient.get<{ success: boolean; data: ReviewListResponse }>(
        `/menu-items/${menuItemId}/reviews`,
        { params: { page, per_page: perPage } },
    )
    return data.data
}

/**
 * Lấy summary rating (avg + breakdown) của 1 món.
 * Endpoint: GET /api/menu-items/{id}/reviews/summary
 */
export async function fetchReviewSummary(menuItemId: number): Promise<ReviewSummary> {
    const { data } = await apiClient.get<{ success: boolean; data: ReviewSummary }>(
        `/menu-items/${menuItemId}/reviews/summary`,
    )
    return data.data
}

/**
 * Kiểm tra khách có đủ điều kiện review món không.
 * Endpoint: GET /api/reviews/menu-item/{id}/eligibility (auth)
 */
export async function fetchReviewEligibility(menuItemId: number): Promise<ReviewEligibility> {
    const { data } = await apiClient.get<{ success: boolean; data: ReviewEligibility }>(
        `/reviews/menu-item/${menuItemId}/eligibility`,
    )
    return data.data
}

/**
 * Khách tạo review mới (multipart/form-data, có ảnh).
 * Endpoint: POST /api/reviews (auth)
 */
export async function createReview(payload: ReviewCreatePayload): Promise<Review> {
    const fd = new FormData()
    fd.append('menu_item_id', String(payload.menu_item_id))
    fd.append('rating', String(payload.rating))
    if (payload.comment != null) fd.append('comment', payload.comment)
    ;(payload.photos ?? []).forEach((file) => fd.append('photos[]', file, file.name))

    const { data } = await apiClient.post<{ success: boolean; data: Review }>('/reviews', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data.data
}

/**
 * Khách cập nhật review của mình.
 * Endpoint: POST /api/reviews/{id} (auth)
 * Lưu ý: nếu muốn GIỮ ảnh cũ, đặt keepPhotos=true (sẽ không gửi field photos).
 *        Mặc định (keepPhotos=false) sẽ gửi `photos=[]` để xóa hết ảnh cũ,
 *        hoặc `photos=[files]` để thay bằng ảnh mới.
 */
export async function updateReview(id: number, payload: ReviewUpdatePayload, keepPhotos = false): Promise<Review> {
    const fd = new FormData()
    if (payload.rating !== undefined) fd.append('rating', String(payload.rating))
    if (payload.comment !== undefined && payload.comment !== null) fd.append('comment', payload.comment)
    if (!keepPhotos) {
        ;(payload.photos ?? []).forEach((file) => fd.append('photos[]', file, file.name))
    }
    const { data } = await apiClient.post<{ success: boolean; data: Review }>(
        `/reviews/${id}`,
        fd,
        {
            headers: { 'Content-Type': 'multipart/form-data' },
        },
    )
    return data.data
}

/**
 * Khách xóa review của mình.
 * Endpoint: DELETE /api/reviews/{id} (auth)
 */
export async function deleteReview(id: number): Promise<void> {
    await apiClient.delete(`/reviews/${id}`)
}

/**
 * Lấy review của user hiện tại (cho AccountView).
 * Endpoint: GET /api/reviews/me (auth)
 */
export async function fetchMyReviews(page = 1, perPage = 20) {
    const { data } = await apiClient.get<{
        success: boolean
        data: { items: Review[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
    }>('/reviews/me', { params: { page, per_page: perPage } })
    return data.data
}