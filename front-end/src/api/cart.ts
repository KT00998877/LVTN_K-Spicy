/**
 * API cho Cart (giỏ hàng).
 * Tất cả endpoints yêu cầu đăng nhập (auth:sanctum).
 */
import apiClient from './client'
import type {
    CartAddPayload,
    CartItem,
    CartItemResponse,
    CartResponse,
    CheckoutPayload,
    CheckoutResponse,
    ToppingItem,
    ToppingsResponse,
} from '@/types/cart'

const ENDPOINTS = {
    cart:          '/cart',
    cartItem:      (id: number) => `/cart/items/${id}`,
    toppings:      '/cart/toppings',
    checkout:      '/cart/checkout',
    itemCount:     '/cart/count',
} as const

// ===== Toppings =====

/**
 * GET /api/cart/toppings — lấy danh sách topping khả dụng.
 */
export async function fetchToppings(): Promise<ToppingItem[]> {
    const { data } = await apiClient.get<ToppingsResponse>(ENDPOINTS.toppings)
    return data.data
}

// ===== Cart =====

/**
 * GET /api/cart — lấy toàn bộ cart của user.
 */
export async function fetchCart(): Promise<CartResponse['data']> {
    const { data } = await apiClient.get<CartResponse>(ENDPOINTS.cart)
    return data.data
}

/**
 * GET /api/cart/count — lấy số lượng item trong cart (dùng cho badge).
 */
export async function fetchCartCount(): Promise<number> {
    const { data } = await apiClient.get<{ success: boolean; data: { count: number } }>(ENDPOINTS.itemCount)
    return data.data.count
}

// ===== Cart Items =====

/**
 * POST /api/cart — thêm 1 món vào cart.
 */
export async function addToCart(payload: CartAddPayload): Promise<CartItem> {
    const { data } = await apiClient.post<CartItemResponse>(ENDPOINTS.cart, payload)
    return data.data
}

/**
 * PUT /api/cart/items/{id} — cập nhật số lượng / spicy_level / note / topping.
 */
export async function updateCartItem(
    id: number,
    payload: Partial<CartAddPayload>,
): Promise<CartItem> {
    const { data } = await apiClient.put<CartItemResponse>(ENDPOINTS.cartItem(id), payload)
    return data.data
}

/**
 * DELETE /api/cart/items/{id} — xóa 1 item khỏi cart.
 */
export async function removeFromCart(id: number): Promise<void> {
    await apiClient.delete(ENDPOINTS.cartItem(id))
}

/**
 * DELETE /api/cart — xóa toàn bộ cart.
 */
export async function clearCart(): Promise<void> {
    await apiClient.delete(ENDPOINTS.cart)
}

// ===== Checkout =====

/**
 * POST /api/cart/checkout — tạo Order từ cart hiện tại.
 */
export async function checkout(payload: CheckoutPayload): Promise<CheckoutResponse['data']> {
    const { data } = await apiClient.post<CheckoutResponse>(ENDPOINTS.checkout, payload)
    return data.data
}
