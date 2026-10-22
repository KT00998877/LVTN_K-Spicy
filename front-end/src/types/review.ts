/**
 * Types cho module Reviews (đồng bộ với ReviewService + ReviewController).
 */

export interface ReviewPhoto {
    id: number
    url: string
}

export interface ReviewUser {
    id: number
    name: string | null
}

export interface ReviewMenuItem {
    id: number
    name: string
    slug: string
    image_url: string | null
}

export interface Review {
    id: number
    rating: number
    comment: string | null
    is_hidden: boolean
    created_at: string | null
    updated_at: string | null
    photos: ReviewPhoto[]
    user: ReviewUser
    menu_item_id: number
    menu_item?: ReviewMenuItem
}

/** Phân bố rating theo số sao (1..5). */
export interface ReviewBreakdown {
    1: number
    2: number
    3: number
    4: number
    5: number
}

export interface ReviewSummary {
    count: number
    average: number
    breakdown: ReviewBreakdown
}

export interface ReviewListPayload {
    items: Review[]
    meta: {
        current_page: number
        last_page: number
        per_page: number
        total: number
    }
}

export interface ReviewListResponse {
    items: Review[]
    meta: ReviewListPayload['meta']
    summary: ReviewSummary
}

export interface ReviewEligibility {
    can_review: boolean
    has_reviewed: boolean
    order_id: number | null
    order_code: string | null
    completed_at: string | null
    existing_review: {
        id: number
        rating: number
        comment: string | null
        photos: ReviewPhoto[]
    } | null
}

export interface ReviewCreatePayload {
    menu_item_id: number
    rating: number
    comment?: string | null
    /** File ảnh từ input type=file */
    photos?: File[]
}

export interface ReviewUpdatePayload {
    rating?: number
    comment?: string | null
    /** File ảnh mới (không truyền → giữ nguyên ảnh cũ) */
    photos?: File[]
}