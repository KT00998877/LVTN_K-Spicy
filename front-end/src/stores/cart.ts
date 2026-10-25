import { beginRead } from '@/utils/realtime'
import { defineStore } from 'pinia'
import * as cartApi from '@/api/cart'
import type { Cart, CartItem, CartAddPayload, CheckoutPayload } from '@/types/cart'
import type { MenuSuggestion } from '@/types/menuSuggestion'
import { useAuthStore } from './auth'
import { useOrderStore } from './orders'

interface CartError extends Error {
    response?: { status?: number; data?: { message?: string } }
}

type MenuSelection = {
    id: number
    name: string
    price: number
    price_formatted: string
    default_spicy: number
    image_url: string
    /** Gợi ý combo nước uống (Phase 3). */
    suggestions?: MenuSuggestion[]
}
type CheckoutInput = Omit<CheckoutPayload, 'idempotency_key' | 'cart_version'>

function sessionGuard() {
    const auth = useAuthStore()
    const revision = auth.sessionRevision
    const token = auth.token
    return () => auth.isCurrentSession(revision, token)
}

export const useCartStore = defineStore('cart', {
    state: () => ({
        cart: null as Cart | null,
        loadingCart: false,
        loadingItem: false,
        loadingCheckout: false,
        error: null as string | null,
        modalItem: null as CartItem | null,
        modalMenuItem: null as MenuSelection | null,
        modalVisible: false,
        pendingCheckout: null as CheckoutPayload | null,
        fetchRevision: 0,
    }),
    getters: {
        isLoggedIn: () => useAuthStore().isAuthenticated,
        itemCount: (state) => state.cart?.items.length ?? 0,
        totalQuantity: (state) => state.cart?.total_quantity ?? 0,
        subtotal: (state) => state.cart?.subtotal ?? 0,
        isEmpty: (state) => !state.cart || state.cart.items.length === 0,
        isLoading: (state) => state.loadingCart || state.loadingItem || state.loadingCheckout,
        canMutate: (state) => !state.loadingItem && !state.loadingCheckout && !state.pendingCheckout,
    },
    actions: {
        pendingStorageKey(): string {
            return `checkout_pending:${useAuthStore().user?.id}`
        },
        restoreCheckout(): void {
            if (!this.isLoggedIn || this.pendingCheckout) return
            try {
                const raw = sessionStorage.getItem(this.pendingStorageKey())
                if (!raw) return
                const value = JSON.parse(raw) as CheckoutPayload
                if (typeof value.idempotency_key !== 'string' || !Number.isInteger(value.cart_version)
                    || !['TAKEAWAY', 'ONLINE'].includes(value.order_type)
                    || !['COD', 'PREPAID'].includes(value.payment_method)) throw new Error('Invalid checkout')
                this.pendingCheckout = value
            } catch {
                sessionStorage.removeItem(this.pendingStorageKey())
            }
        },
        clearPendingCheckout(): void {
            sessionStorage.removeItem(this.pendingStorageKey())
            this.pendingCheckout = null
        },
        async fetchCart(force = false, quiet = false): Promise<void> {
            if (!this.isLoggedIn) { this.$reset(); return }
            this.restoreCheckout()
            if (!force && this.cart !== null) return
            const current = sessionGuard()
            if (!current()) return
            const request = ++this.fetchRevision
            const valid = () => current() && request === this.fetchRevision
            if (!quiet) this.loadingCart = true
            const fresh = beginRead(this, 'realtime', quiet)
            try {
                const cart = await cartApi.fetchCart()
                if (valid() && fresh()) this.cart = cart
            } catch (err) {
                if (quiet) throw err
                if (valid()) {
                    this.cart = null
                    this.error = (err as CartError).response?.data?.message ?? 'Không thể tải giỏ hàng.'
                }
            } finally {
                if (valid() && !quiet) this.loadingCart = false
            }
        },
        openAddModal(menuItem: MenuSelection): void {
            if (!this.isLoggedIn) { window.location.href = '/dang-nhap?redirect=/thuc-don'; return }
            this.restoreCheckout()
            if (!this.canMutate) {
                window.location.href = '/thanh-toan'
                return
            }
            this.modalMenuItem = menuItem
            this.modalItem = null
            this.modalVisible = true
        },
        openEditModal(item: CartItem): void {
            if (!this.isLoggedIn || !this.canMutate) return
            this.modalItem = item
            this.modalMenuItem = {
                id: item.menu_item_id, name: item.menu_item.name, price: item.menu_item.price,
                price_formatted: item.menu_item.price_formatted, default_spicy: item.spicy_level,
                image_url: item.menu_item.image_url,
            }
            this.modalVisible = true
        },
        closeModal(): void {
            this.modalVisible = false
            this.modalItem = null
            this.modalMenuItem = null
        },
        async mutate(operation: () => Promise<unknown>): Promise<boolean> {
            this.restoreCheckout()
            if (!this.isLoggedIn || !this.canMutate) return false
            const current = sessionGuard()
            if (!current()) return false
            this.loadingItem = true
            this.error = null
            ++this.fetchRevision // Ignore any cart read started before this write.
            this.loadingCart = false
            try {
                await operation()
                if (!current()) return false
                this.cart = null
                await this.fetchCart(true)
                if (!current()) return false
                this.closeModal()
                return true
            } catch (err) {
                if (current()) this.error = (err as CartError).response?.data?.message ?? 'Không thể cập nhật giỏ hàng.'
                return false
            } finally {
                if (current()) this.loadingItem = false
            }
        },
        addItem(payload: CartAddPayload): Promise<boolean> {
            return this.mutate(() => cartApi.addToCart(payload))
        },
        /**
         * Thêm nhanh combo: món chính (theo tùy chỉnh hiện tại) + món gợi ý (Phase 3).
         * Cả 2 dùng chung 1 lần mutate → modal đóng 1 lần, giỏ refresh 1 lần.
         */
        async addCombo(mainPayload: CartAddPayload, sideItemId: number): Promise<boolean> {
            // Dùng holder object để giữ lỗi của lần thêm thứ 2 (TS không narrow biến gán trong closure).
            const holder: { error: CartError | null } = { error: null }

            const ok = await this.mutate(async () => {
                await cartApi.addToCart(mainPayload)
                try {
                    await cartApi.addToCart({
                        menu_item_id: sideItemId,
                        quantity: 1,
                        spicy_level: 0,
                        note: '',
                        topping_ids: [],
                    })
                } catch (err) {
                    // Món đi kèm vừa hết hàng → giữ món chính đã thêm, báo lỗi nhẹ.
                    holder.error = err as CartError
                }
            })

            if (holder.error) {
                await this.fetchCart(true)
                this.error = holder.error.response?.data?.message ?? 'Không thêm được món đi kèm.'
                return false
            }

            return ok
        },
        updateItem(id: number, payload: Partial<CartAddPayload>): Promise<boolean> {
            return this.mutate(() => cartApi.updateCartItem(id, payload))
        },
        async removeItem(id: number): Promise<void> {
            await this.mutate(() => cartApi.removeFromCart(id))
        },
        async clearCart(): Promise<void> {
            await this.mutate(() => cartApi.clearCart())
        },
        async checkout(payload: CheckoutInput): Promise<{ order_id: number; order_code: string } | null> {
            if (!this.isLoggedIn || this.isLoading) return null
            this.restoreCheckout()
            if (!this.pendingCheckout && this.isEmpty) return null
            const current = sessionGuard()
            if (!current()) return null
            this.loadingCheckout = true
            this.error = null
            try {
                if (!this.pendingCheckout) {
                    const request: CheckoutPayload = {
                        ...payload, cart_version: this.cart!.version,
                        idempotency_key: crypto.randomUUID(),
                    }
                    // Persist before sending: a reload/timeout retries exactly the same request.
                    sessionStorage.setItem(this.pendingStorageKey(), JSON.stringify(request))
                    this.pendingCheckout = request
                }
                const result = await cartApi.checkout({ ...this.pendingCheckout })
                if (!current()) return null
                this.clearPendingCheckout()
                this.cart = null
                useOrderStore().resetList()
                return result
            } catch (err) {
                if (!current()) return null
                const status = (err as CartError).response?.status
                if (status === 409 || status === 422) {
                    this.clearPendingCheckout()
                    await this.fetchCart(true)
                    if (!current()) return null
                    this.error = status === 409
                        ? 'Giỏ hàng đã thay đổi. Vui lòng kiểm tra lại và xác nhận đặt hàng.'
                        : (err as CartError).response?.data?.message ?? 'Vui lòng kiểm tra lại giỏ hàng.'
                } else {
                    this.error = this.pendingCheckout
                        ? 'Chưa xác định được kết quả đặt hàng. Bấm kiểm tra lại để nhận kết quả của lần đặt này.'
                        : 'Không thể lưu yêu cầu đặt hàng. Vui lòng kiểm tra trình duyệt và thử lại.'
                }
                return null
            } finally {
                if (current()) this.loadingCheckout = false
            }
        },
    },
})
