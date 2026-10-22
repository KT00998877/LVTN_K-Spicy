/**
 * Types cho module Cart (giỏ hàng).
 * Đồng bộ với CartController.php (back-end) và database schema.
 */

import type { MenuItemCard } from './home'

/** ===== Topping ===== */

/** Topping item được chọn trong modal */
export interface ToppingItem {
    id: number
    name: string
    price: number
    price_formatted: string
}

/** ===== Cart Item ===== */

/** Payload gửi lên BE khi thêm 1 món vào cart */
export interface CartAddPayload {
    menu_item_id: number
    quantity: number
    spicy_level: number      // 0..5, override spicy_level của menu_item
    note: string             // ghi chú của khách cho món này
    topping_ids: number[]    // danh sách topping được chọn
}

/** 1 dòng trong cart (trả về từ API) */
export interface CartItem {
    id: number
    menu_item_id: number
    menu_item: MenuItemCard & { spicy_level: number }
    quantity: number
    spicy_level: number      // spicy_level đã override (lưu trong cart)
    note: string | null
    toppings: ToppingItem[]
    /** Danh sách ID topping (cho ItemModal dùng) */
    topping_ids: number[]
    unit_price: number       // giá base của món
    topping_price: number    // tổng giá topping
    subtotal: number         // unit_price * quantity + topping_price * quantity
    subtotal_formatted: string
}

/** ===== Cart ===== */

/** Toàn bộ cart của user */
export interface Cart {
    version: number
    id: number
    user_id: number
    items: CartItem[]
    total_items: number      // tổng số dòng
    total_quantity: number   // tổng số lượng món
    subtotal: number
    subtotal_formatted: string
}

/** ===== API Response ===== */

export interface CartResponse {
    success: boolean
    message?: string
    data: Cart
}

export interface CartItemResponse {
    success: boolean
    message?: string
    data: CartItem
}

export interface ToppingsResponse {
    success: boolean
    message?: string
    data: ToppingItem[]
}

/** ===== Payload gửi BE khi checkout (ONLINE và TAKEAWAY) ===== */
export interface CheckoutPayload {
    delivery_latitude?: number
    delivery_longitude?: number
    points_redeemed?: number
    /** Mã giảm giá (optional). Không phân biệt HOA/thường. */
    voucher_code?: string | null
    idempotency_key: string
    cart_version: number
    order_type: 'TAKEAWAY' | 'ONLINE'
    /** 'COD' (mặc định) hoặc 'PREPAID' */
    payment_method: 'COD' | 'PREPAID'
    note?: string
    /** Thông tin giao hàng — bắt buộc với ONLINE */
    recipient_name?: string
    recipient_phone?: string
    delivery_address?: string
}

export interface CheckoutResponse {
    success: boolean
    message?: string
    data: {
        order_id: number
        order_code: string
    }
}
