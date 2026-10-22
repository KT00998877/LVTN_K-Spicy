/**
 * Types cho Orders (đơn hàng) của khách hàng đã đăng nhập.
 * Đồng bộ với OrderController.php + OnlineOrderService.presentation().
 */

export type OrderStatus =
    | 'PENDING'
    | 'CONFIRMED'
    | 'PREPARING'
    | 'READY'
    | 'READY_NOTIFY_SHIPPER'
    | 'DELIVERING'
    | 'DELIVERED'
    | 'SERVED'
    | 'COMPLETED'
    | 'CANCELLED'

export type OrderType = 'DINE_IN' | 'TAKEAWAY' | 'ONLINE'

export type OrderItemStatus = 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED'

/** Rút gọn cho danh sách (list view) */
export interface OrderSummary {
    id: number
    code: string
    order_type: OrderType
    status: OrderStatus
    subtotal: number
    discount: number
    total_amount: number
    item_count: number
    placed_at: string | null
    completed_at: string | null
    /** Trạng thái thanh toán rút gọn (UNPAID / PAID / ...). Có thể null nếu không phải ONLINE. */
    payment_status?: InvoiceStatus | null
    /** Thông tin người nhận (chỉ có với đơn ONLINE). */
    recipient_name?: string | null
    recipient_phone?: string | null
    delivery_address?: string | null
}

/** Món trong đơn (chi tiết) */
export interface OrderItemDetail {
    id: number
    menu_item_id: number
    name: string
    unit_price: number
    quantity: number
    subtotal: number
    note: string | null
    spicy_level: number | null       // spicy_level hiệu lực (override hoặc của menu)
    status: OrderItemStatus
    started_at: string | null
    ready_at: string | null
    served_at: string | null
    cancellation_reason: string | null
    /** Topping kèm theo (nếu có) */
    toppings?: Array<{
        id: number
        name: string
        unit_price: number
        quantity: number
        subtotal: number
    }>
}

/** Thông tin hóa đơn gắn với đơn (chỉ áp dụng cho ONLINE) */
export interface OrderPaymentInfo {
    invoice_id: number
    invoice_code: string
    status: InvoiceStatus
    total: number
    paid: number
    is_simulated: boolean
}

/** Sự kiện trong đời số đơn hàng (timeline audit) */
export interface OrderEvent {
    event: string
    status: OrderStatus
    note: string | null
    created_at: string
}

/** Đầy đủ cho chi tiết (detail view) */
export interface OrderDetail {
    id: number
    code: string
    order_type: OrderType
    status: OrderStatus
    subtotal: number
    discount: number
    total_amount: number
    note: string | null
    placed_at: string | null
    completed_at: string | null
    table_session_id: number | null
    waiter_id: number | null
    items: OrderItemDetail[]

    // Trường online / delivery (từ OnlineOrderService.presentation())
    recipient_name?: string | null
    recipient_phone?: string | null
    delivery_address?: string | null
    shipping_fee?: number
    payment_method?: 'COD' | 'PREPAID'
    delivery_latitude?: string | number | null
    delivery_longitude?: string | number | null
    delivery_distance_km?: string | number | null
    points_redeemed?: number
    confirmed_at?: string | null
    handed_over_at?: string | null
    picked_up_at?: string | null
    arrived_at?: string | null
    delivered_at?: string | null
    /**
     * Thời điểm khách bấm "Tôi đã nhận hàng".
     * Sau khi khách xác nhận → status = COMPLETED, completed_at = customer_received_at.
     */
    customer_received_at?: string | null
    /** Số điểm thưởng đã cộng cho đơn này (sau khi khách xác nhận). */
    points_awarded?: number
    shipper_id?: number | null
    shipper_name?: string | null
    delivery_issue?: string | null
    payment?: OrderPaymentInfo | null
    events?: OrderEvent[]
}

export interface OrderListMeta {
    current_page: number
    last_page: number
    per_page: number
    total: number
}

export interface OrderListResponse {
    success: boolean
    data: OrderSummary[]
    meta: OrderListMeta
}

export interface OrderDetailResponse {
    success: boolean
    data: OrderDetail
}

/** Trạng thái hóa đơn (đồng bộ với backend) */
export type InvoiceStatus = 'UNPAID' | 'PENDING' | 'PAID' | 'FAILED' | 'REFUNDED' | 'PARTIAL'

/** Phương thức thanh toán mô phỏng mà khách được chọn khi thanh toán online */
export type SimulatedPaymentMethod = 'CASH' | 'TRANSFER' | 'QR' | 'ONLINE_GATEWAY' | 'OTHER'

/** Query params cho filter danh sách */
export interface OrderListQuery {
    status?: OrderStatus
    page?: number
    per_page?: number
}

/** Payload cho khách gọi thanh toán mô phỏng */
export interface SimulatePaymentPayload {
    method: SimulatedPaymentMethod
}

/**
 * Phản hồi khi khách xác nhận đã nhận hàng.
 * Trả về order đã cập nhật + tổng điểm hiện tại để header hiển thị lại.
 */
export interface ConfirmReceiptResponse {
    success: boolean
    data: OrderDetail
    loyalty_points: number
    points_awarded: number
}
