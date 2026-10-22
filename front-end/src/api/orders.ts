import apiClient from './client'
import type {
    ConfirmReceiptResponse,
    OrderDetail,
    OrderDetailResponse,
    OrderListQuery,
    OrderListResponse,
    OrderSummary,
    SimulatedPaymentMethod,
} from '@/types/orders'
import type { DeliveryRoute } from './staff'

const ENDPOINTS = {
    myList:   '/orders/me',
    myDetail: (id: number | string) => `/orders/me/${id}`,
    simulatePayment: (id: number | string) => `/orders/me/${id}/simulate-payment`,
    confirmReceipt:  (id: number | string) => `/orders/me/${id}/confirm-receipt`,
    myRoute:        (id: number | string) => `/orders/me/${id}/route`,
} as const

/**
 * GET /api/orders/me — danh sách đơn hàng của customer hiện tại.
 */
export async function fetchMyOrders(query: OrderListQuery = {}): Promise<OrderListResponse> {
    const params: Record<string, string | number> = {}
    if (query.status) params.status = query.status
    if (query.page) params.page = query.page
    if (query.per_page) params.per_page = query.per_page

    const { data } = await apiClient.get<OrderListResponse>(ENDPOINTS.myList, { params })
    return data
}

/**
 * GET /api/orders/me/{id} — chi tiết đơn hàng của customer hiện tại.
 */
export async function fetchMyOrderDetail(id: number | string): Promise<OrderDetail> {
    const { data } = await apiClient.get<OrderDetailResponse>(ENDPOINTS.myDetail(id))
    return data.data
}

/**
 * POST /api/orders/me/{id}/simulate-payment — khách gọi thanh toán mô phỏng
 * cho đơn ONLINE (chỉ áp dụng khi đơn chưa thanh toán và ở trạng thái PENDING).
 */
export async function simulateOrderPayment(
    id: number | string,
    method: SimulatedPaymentMethod,
): Promise<OrderDetail> {
    const { data } = await apiClient.post<{ success: boolean; data: OrderDetail }>(
        ENDPOINTS.simulatePayment(id),
        { method },
    )
    return data.data
}

/**
 * POST /api/orders/me/{id}/confirm-receipt — khách xác nhận đã nhận hàng.
 *   - DELIVERED → COMPLETED, tích điểm (nếu đơn > 0).
 *   - COMPLETED  → idempotent, không cộng thêm điểm.
 * Trả về order đã cập nhật + tổng điểm mới.
 */
export async function confirmOrderReceipt(id: number | string): Promise<ConfirmReceiptResponse> {
    const { data } = await apiClient.post<{
        success: boolean
        data: OrderDetail
        loyalty_points: number
        points_awarded?: number
    }>(ENDPOINTS.confirmReceipt(id))
    return {
        success: data.success,
        data: data.data,
        loyalty_points: data.loyalty_points ?? 0,
        points_awarded: data.points_awarded ?? data.data.points_awarded ?? 0,
    }
}

/** Helper lấy danh sách phẳng (unwrap meta) */
export function unwrapOrderList(payload: OrderListResponse): {
    items: OrderSummary[]
    meta: OrderListResponse['meta']
} {
    return { items: payload.data, meta: payload.meta }
}

/**
 * GET /api/orders/me/{id}/route — khách xem hướng dẫn đường đi của shipper đến nhà mình (ONLINE).
 */
export async function getOrderRoute(id: number | string): Promise<DeliveryRoute> {
    const { data } = await apiClient.get<{ success: boolean; data: DeliveryRoute }>(
        ENDPOINTS.myRoute(id),
    )
    return data.data
}
