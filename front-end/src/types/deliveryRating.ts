/**
 * Types cho Phase 4 — đánh giá dịch vụ giao hàng.
 */

export interface DeliveryPhoto {
    id: number
    url: string
}

/** Phần đánh giá giao hàng gắn với 1 đơn. */
export interface DeliveryRating {
    rating: number | null
    comment: string | null
    rated_at: string | null
    photos: DeliveryPhoto[]
}

export interface DeliveryRatingPayload {
    rating: number
    comment?: string | null
    /** File ảnh từ input type=file (tối đa 5) */
    photos?: File[]
}

/** Thống kê rating giao hàng theo từng shipper (Manager). */
export interface DeliveryShipperStat {
    shipper_id: number | null
    shipper_name: string | null
    count: number
    average: number
}

export interface DeliveryRatingRecent {
    order_id: number
    order_code: string
    shipper_id: number | null
    shipper_name: string | null
    rating: number
    comment: string | null
    rated_at: string | null
}

export interface DeliveryRatingSummary {
    count: number
    average: number
    breakdown: Record<number, number>
    shipper_stats: DeliveryShipperStat[]
    recent: DeliveryRatingRecent[]
}
