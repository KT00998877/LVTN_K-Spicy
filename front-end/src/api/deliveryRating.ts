import apiClient from './client'
import type {
    DeliveryRating,
    DeliveryRatingPayload,
    DeliveryRatingSummary,
} from '@/types/deliveryRating'

/**
 * Gửi đánh giá dịch vụ giao hàng cho 1 đơn (multipart/form-data).
 * Endpoint: POST /api/orders/me/{id}/delivery-rating (auth)
 *
 * Chỉ áp dụng cho đơn ONLINE đã COMPLETED.
 */
export async function submitDeliveryRating(
    orderId: number | string,
    payload: DeliveryRatingPayload,
): Promise<DeliveryRating> {
    const fd = new FormData()
    fd.append('rating', String(payload.rating))
    if (payload.comment != null) fd.append('comment', payload.comment)
    ;(payload.photos ?? []).forEach((file) => fd.append('photos[]', file, file.name))

    const { data } = await apiClient.post<{ success: boolean; data: DeliveryRating }>(
        `/orders/me/${orderId}/delivery-rating`,
        fd,
        { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return data.data
}

/**
 * Thống kê đánh giá dịch vụ giao hàng (Manager).
 * Endpoint: GET /api/staff/manager/delivery-ratings
 */
export async function fetchDeliveryRatingSummary(
    params: { shipper_id?: number | null; limit?: number } = {},
): Promise<DeliveryRatingSummary> {
    const query: Record<string, number> = {}
    if (params.shipper_id != null) query.shipper_id = params.shipper_id
    if (params.limit != null) query.limit = params.limit

    const { data } = await apiClient.get<{ success: boolean; data: DeliveryRatingSummary }>(
        '/staff/manager/delivery-ratings',
        { params: query },
    )
    return data.data
}
