<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue'
import api from '@/api/client'
import { createVNPay } from '@/api/payments'
import DeliveryLocationPicker from '@/components/DeliveryLocationPicker.vue'
import type { CheckoutPayload } from '@/types/cart'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useCartStore } from '@/stores/cart'
import { useAppDialog } from '@/composables/useAppDialog'
import { useAuthStore } from '@/stores/auth'
import type { CartItem } from '@/types/cart'
import { imageUrl } from '@/utils/image'

import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import FoodDetailModal from '@/components/menu/FoodDetailModal.vue'
import VoucherInput from '@/components/VoucherInput.vue'
import type { VoucherPreview } from '@/types/voucher'

const router = useRouter()
const cartStore = useCartStore()
const appDialog = useAppDialog()
const authStore = useAuthStore()

const {
    cart,
    modalVisible,
    modalMenuItem,
    modalItem,
    loadingItem,
    loadingCheckout,
    error,
} = storeToRefs(cartStore)

const orderType = ref<'DINE_IN' | 'TAKEAWAY' | 'ONLINE'>('ONLINE')
const note = ref('')
const submitting = ref(false)
const successMessage = ref<string | null>(null)

/** Các trường cần cho đơn ONLINE và TAKEAWAY */
const recipientName = ref('')
const recipientPhone = ref('')
const deliveryAddress = ref('')
const location = ref<{ latitude: number; longitude: number } | null>(null)
const points = ref(0)
const checkoutConfig = ref<{ restaurant_address: string; latitude: number; longitude: number } | null>(null)
const quote = ref<{ shipping_fee: number; distance_km: number; discount: number; total: number; max_points: number; balance: number; combo_discount?: number; voucher?: VoucherPreview | null; voucher_error?: string | null } | null>(null)
const quoteLoading = ref(false)
const quoteError = ref('')
/** Voucher đã chọn (null nếu chưa áp dụng / đã bỏ). */
const voucherCode = ref<string | null>(null)
const voucherPreview = ref<VoucherPreview | null>(null)
/** Mã voucher đến từ banner/chuông ưu đãi (?voucher=CODE) — tự áp dụng 1 lần. */
const route = useRoute()
const voucherFromLink = computed(() => {
    const raw = route.query.voucher
    return typeof raw === 'string' ? raw.trim().toUpperCase() : null
})
let quoteRevision = 0
let quoteTimer: ReturnType<typeof setTimeout> | undefined
const money = (value: number) => `${value.toLocaleString('vi-VN')}đ`
watch([orderType, location, points, () => cart.value?.version, voucherCode], () => {
    const revision = ++quoteRevision
    clearTimeout(quoteTimer)
    quote.value = null
    quoteError.value = ''
    quoteLoading.value = false
    if (!cart.value || (orderType.value === 'ONLINE' && !location.value)) return
    quoteLoading.value = true
    quoteTimer = setTimeout(async () => {
        try {
            const { data } = await api.post('/checkout/quote', {
                order_type: orderType.value, points_redeemed: points.value,
                delivery_latitude: location.value?.latitude, delivery_longitude: location.value?.longitude,
                voucher_code: voucherCode.value,
            })
            if (revision === quoteRevision) quote.value = data
        } catch (e) {
            if (revision === quoteRevision) quoteError.value = (e as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Không lấy được phí giao hàng. Hãy thử lại.'
        } finally { if (revision === quoteRevision) quoteLoading.value = false }
    }, 300)
})
onBeforeUnmount(() => { quoteRevision++; clearTimeout(quoteTimer) })

/**
 * Backend cart checkout hỗ trợ 2 luồng giao hàng:
 *  - ONLINE: giao tận nơi → cần tên + SĐT + địa chỉ
 *  - TAKEAWAY: khách đến quán lấy → chỉ cần tên + SĐT
 * Đơn DINE_IN (tại bàn) được tạo trực tiếp qua giao diện nhân viên phục vụ.
 *
 * Thanh toán:
 *  - COD (mặc định): shipper thu tiền khi giao
 *  - PREPAID: khách thanh toán online trước
 */
const ORDER_TYPE_OPTIONS = [
    { value: 'TAKEAWAY', label: 'Mang đi', desc: 'Đóng gói, khách đến quán nhận' },
    { value: 'ONLINE', label: 'Giao hàng', desc: 'Shipper giao tận nơi theo địa chỉ' },
] as const

const PAYMENT_METHOD_OPTIONS: { value: 'COD' | 'PREPAID'; label: string; desc: string }[] = [
    { value: 'COD',     label: 'Thanh toán khi nhận hàng (COD)',  desc: 'Trả tiền mặt cho shipper khi nhận / đến quán lấy.' },
    { value: 'PREPAID', label: 'VNPay', desc: 'Thanh toán qua ngân hàng / QR trên VNPay Sandbox.' },
]

const paymentMethod = ref<'COD' | 'PREPAID'>('COD')

const phoneOk = computed(() => /^(0[0-9]{9}|\+84[0-9]{9})$/.test(recipientPhone.value.trim()))
const addressOk = computed(() => deliveryAddress.value.trim().length >= 10)
const recipientOk = computed(() => recipientName.value.trim().length > 0)
const isEmpty = computed(() => !cart.value || cart.value.items.length === 0)
const subtotalFormatted = computed(() => cart.value?.subtotal_formatted ?? '0 đ')

const canCheckout = computed(() => {
    if (isEmpty.value) return false
    if (!quote.value || quoteLoading.value) return false
    if (orderType.value === 'ONLINE') {
        return recipientOk.value && phoneOk.value && addressOk.value
    }
    if (orderType.value === 'TAKEAWAY') {
        return recipientOk.value && phoneOk.value
    }
    return false // DINE_IN
})

function showAddressField(): boolean {
    return orderType.value === 'ONLINE'
}

onMounted(async () => {
    if (!authStore.isAuthenticated) {
        router.replace({ name: 'login', query: { redirect: '/thanh-toan' } })
        return
    }
    await cartStore.fetchCart(true)
    try { checkoutConfig.value = (await api.get('/checkout/config')).data }
    catch { quoteError.value = 'Không tải được cấu hình giao hàng. Vui lòng tải lại trang.' }
    recipientName.value = authStore.user?.name ?? ''
    // Tự động lấy GPS ngay khi mount nếu đang ở chế độ Giao hàng và chưa có vị trí.
    autoLocateOnOnline()
})

/** Tự động lấy vị trí hiện tại của khách khi chọn chế độ giao hàng lần đầu. */
function autoLocateOnOnline(): void {
    if (orderType.value !== 'ONLINE') return
    // Preserve an uncertain checkout and its idempotency key across reloads.
    if (cartStore.pendingCheckout) return
    if (location.value) return
    if (!navigator.geolocation) return
    navigator.geolocation.getCurrentPosition(
        position => {
            location.value = { latitude: position.coords.latitude, longitude: position.coords.longitude }
        },
        () => { /* Không có quyền → khách vẫn có thể chọn thủ công hoặc dùng nút GPS */ },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
}
watch(orderType, () => autoLocateOnOnline(), { immediate: true })

/** Khi toạ độ thay đổi (GPS / click / kéo ghim). */
function onLocationChange(point: { latitude: number; longitude: number; address: string }) {
    location.value = { latitude: point.latitude, longitude: point.longitude }
    // Không tự điền khi user đang gõ tay vào ô địa chỉ:
    // chỉ điền nếu ô trống HOẶC user đang xem địa chỉ từ geocode trước đó (tức là không có gì khác).
}

/** Khi geocode xong → tự điền địa chỉ vào ô input, nhưng không ghi đè nếu user đã sửa thủ công. */
function onResolvedAddress(address: string) {
    // Auto-fill vào ô địa chỉ; chỉ ghi đè khi:
    //   1. Ô đang trống, hoặc
    //   2. Ô hiện tại trùng với địa chỉ geocode trước đó (nghĩa là user chưa sửa).
    const current = deliveryAddress.value.trim()
    if (!current || lastGeocodedAddress.value === current) {
        deliveryAddress.value = address
        lastGeocodedAddress.value = address
    }
}
// Theo dõi để biết khi user gõ tay địa chỉ → từ đó không bị geocode ghi đè.
const lastGeocodedAddress = ref('')

function onVoucherApplied(preview: VoucherPreview) {
    voucherPreview.value = preview
    voucherCode.value = preview.code
}
function onVoucherCleared() {
    voucherPreview.value = null
    voucherCode.value = null
}
watch(deliveryAddress, value => {
    if (value.trim() && value.trim() !== lastGeocodedAddress.value) {
        // User đang sửa tay — đánh dấu là user-controlled từ bây giờ.
        lastGeocodedAddress.value = '' // reset → lần geocode kế tiếp sẽ ghi đè (user muốn cập nhật vị trí mới).
    }
})

function spicyLabel(level: number): string {
    if (level === 0) return 'Không cay'
    return `Cấp ${level}`
}

const itemImageUrl = (item: CartItem) => item.menu_item.image_url

function handleEditItem(item: CartItem) {
    cartStore.openEditModal(item)
}

async function handleRemoveItem(item: CartItem) {
    if (!await appDialog.confirm({ title: 'Xóa món khỏi giỏ?', message: `“${item.menu_item.name}” sẽ được bỏ khỏi giỏ hàng.`, confirmLabel: 'Xóa món', tone: 'danger' })) return
    await cartStore.removeItem(item.id)
}

async function handleModalSubmit(payload: {
    menu_item_id: number
    quantity: number
    spicy_level: number
    note: string
    topping_ids: number[]
}) {
    if (modalItem.value) {
        await cartStore.updateItem(modalItem.value.id, {
            quantity: payload.quantity,
            spicy_level: payload.spicy_level,
            note: payload.note,
            topping_ids: payload.topping_ids,
        })
    }
}

async function handleSubmitOrder() {
    if (submitting.value || cartStore.isLoading || (isEmpty.value && !cartStore.pendingCheckout)) return
    if (!canCheckout.value && !cartStore.pendingCheckout) {
        successMessage.value = null
        if (orderType.value === 'ONLINE') {
            if (!recipientOk.value) cartStore.error = 'Vui lòng nhập tên người nhận.'
            else if (!phoneOk.value) cartStore.error = 'Số điện thoại chưa đúng định dạng (vd: 0912345678).'
            else if (!addressOk.value) cartStore.error = 'Vui lòng nhập địa chỉ giao hàng chi tiết (ít nhất 10 ký tự).'
        } else if (orderType.value === 'TAKEAWAY') {
            if (!recipientOk.value) cartStore.error = 'Vui lòng nhập tên người nhận.'
            else if (!phoneOk.value) cartStore.error = 'Số điện thoại chưa đúng định dạng (vd: 0912345678).'
        } else {
            cartStore.error = 'Đơn Tại bàn hiện được phục vụ tạo trực tiếp tại nhà hàng. Hãy liên hệ quán.'
        }
        return
    }
    const revision = authStore.sessionRevision
    const token = authStore.token
    submitting.value = true
    successMessage.value = null

    const payload: Omit<CheckoutPayload, 'idempotency_key' | 'cart_version'> = {
        order_type: orderType.value as 'TAKEAWAY' | 'ONLINE',
        payment_method: paymentMethod.value,
        points_redeemed: points.value,
        recipient_name: recipientName.value.trim(),
        recipient_phone: recipientPhone.value.trim(),
        note: note.value.trim() || undefined,
        voucher_code: voucherCode.value,
    }
    if (orderType.value === 'ONLINE') {
        payload.delivery_address = deliveryAddress.value.trim()
        payload.delivery_latitude = location.value?.latitude
        payload.delivery_longitude = location.value?.longitude
    }

    const actualMethod = cartStore.pendingCheckout?.payment_method ?? paymentMethod.value
    const result = await cartStore.checkout(payload)

    submitting.value = false
    if (!authStore.isCurrentSession(revision, token)) return

    if (result) {
        if (actualMethod === 'PREPAID') {
            submitting.value = true
            try {
                const payment = await createVNPay(result.order_id)
                if (authStore.isCurrentSession(revision, token)) window.location.assign(payment.payment_url)
            } catch {
                if (authStore.isCurrentSession(revision, token)) {
                    await router.push({ name: 'order-detail', params: { id: result.order_id }, query: { payment_error: '1' } })
                }
            } finally { submitting.value = false }
            return
        }
        successMessage.value = `Đặt món thành công! Mã đơn: #${result.order_code}. Đang chuyển đến chi tiết đơn hàng…`
        note.value = ''
        setTimeout(() => {
            if (authStore.isCurrentSession(revision, token)) {
                router.push({ name: 'order-detail', params: { id: result.order_id } })
            }
        }, 1200)
    }
}

function continueShopping() {
    router.push({ name: 'menu' })
}
</script>

<template>
    <NavbarHome />

    <main class="checkout-page">
        <div class="checkout-container">
            <header class="page-header">
                <h1 class="page-title">Giỏ hàng & Thanh toán</h1>
                <p class="page-subtitle">Kiểm tra lại các món ăn và chọn hình thức nhận hàng.</p>
            </header>

            <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success" role="status">{{ successMessage }}</div>
            <p v-if="submitting" role="status">Đang xử lý đặt món và thanh toán…</p>
            <div v-if="cartStore.pendingCheckout" class="alert" role="status">
                Lần đặt hàng trước đang chờ xác định kết quả. Hãy kiểm tra lại trước khi sửa giỏ hoặc đặt đơn mới.
                <button type="button" class="btn btn-primary" :disabled="submitting || cartStore.isLoading" @click="handleSubmitOrder">
                    {{ submitting ? 'Đang kiểm tra...' : 'Kiểm tra lại kết quả đặt hàng' }}
                </button>
            </div>

            <!-- ===== Loading ===== -->
            <div v-if="cartStore.loadingCart && !cart" class="state-box">
                <div class="spinner"></div>
                <p>Đang tải giỏ hàng...</p>
            </div>

            <!-- ===== Empty ===== -->
            <div v-else-if="isEmpty" class="state-box empty">
                <div class="empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </div>
                <h3>Giỏ hàng của bạn đang trống</h3>
                <p>Khám phá các món ăn hấp dẫn trong thực đơn của chúng tôi ngay hôm nay.</p>
                <button class="btn btn-primary" @click="continueShopping">Khám phá thực đơn</button>
            </div>

            <!-- ===== Cart ===== -->
            <div v-else class="checkout-grid">
                <!-- ===== Danh sách món ===== -->
                <section class="cart-items">
                    <header class="cart-header">
                        <h2>Món đã chọn ({{ cart?.total_items ?? 0 }})</h2>
                        <button
                            type="button"
                            class="btn-clear"
                            :disabled="!cartStore.canMutate"
                            @click="cartStore.clearCart()"
                        >
                            Xóa tất cả
                        </button>
                    </header>

                    <div class="items-list">
                        <article
                            v-for="item in cart?.items"
                            :key="item.id"
                            class="cart-item"
                        >
                            <img
                                :src="imageUrl(itemImageUrl(item))"
                                :alt="item.menu_item.name"
                                class="item-img"
                                @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/100x100/f5f5f5/999?text=No+Image'"
                            />

                            <div class="item-info">
                                <h3 class="item-name">
                                    {{ item.menu_item.name }}
                                </h3>

                                <div class="item-meta">
                                    <span class="meta-chip spicy" v-if="item.spicy_level > 0">
                                        {{ spicyLabel(item.spicy_level) }}
                                    </span>
                                    <span class="meta-chip" v-else>
                                        Không cay
                                    </span>
                                    <span
                                        v-if="item.toppings.length"
                                        class="meta-chip topping"
                                    >
                                        + {{ item.toppings.map(t => t.name).join(', ') }}
                                    </span>
                                </div>

                                <p v-if="item.note" class="item-note">
                                    Ghi chú: {{ item.note }}
                                </p>
                            </div>

                            <div class="item-quantity">
                                <span class="qty-badge">x{{ item.quantity }}</span>
                            </div>

                            <div class="item-price">
                                <div class="subtotal">{{ item.subtotal_formatted }}</div>
                                <div class="unit-price" v-if="item.topping_price > 0">
                                    {{ item.menu_item.price_formatted }} + {{ new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(item.topping_price) }} (topping)
                                </div>
                            </div>

                            <div class="item-actions">
                                <button
                                    type="button"
                                    class="btn-icon"
                                    title="Sửa món"
                                    aria-label="Sửa món"
                                    :disabled="!cartStore.canMutate"
                                    @click="handleEditItem(item)"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button
                                    type="button"
                                    class="btn-icon btn-danger"
                                    title="Xóa món"
                                    aria-label="Xóa món"
                                    :disabled="!cartStore.canMutate"
                                    @click="handleRemoveItem(item)"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </article>

                        <!-- Tiết kiệm combo (Phase 3) -->
                        <div v-if="cart && cart.combo_discount > 0" class="combo-summary">
                            <div class="combo-summary-head">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M8 2h8l-1 6H9L8 2z"></path>
                                    <path d="M12 8v13"></path>
                                    <path d="M7 21h10"></path>
                                </svg>
                                <span>Tiết kiệm combo</span>
                                <strong>−{{ cart.combo_discount_formatted }}</strong>
                            </div>
                            <ul class="combo-summary-list">
                                <li v-for="line in cart.combo_lines" :key="line.suggestion_id">
                                    {{ line.title }}
                                    <span v-if="line.quantity > 1">×{{ line.quantity }}</span>
                                    <span class="combo-saving">−{{ line.saving.toLocaleString('vi-VN') }}đ</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- ===== Tóm tắt & Submit ===== -->
                <aside class="cart-summary-wrapper">
                    <div class="cart-summary">
                        <h2 class="summary-title">Thông tin đơn hàng</h2>

                        <!-- Loại đơn -->
                        <div class="form-group">
                            <label class="form-label">Hình thức nhận hàng</label>
                            <div class="order-type-grid">
                                <button
                                    v-for="opt in ORDER_TYPE_OPTIONS"
                                    :key="opt.value"
                                    type="button"
                                    class="type-btn"
                                    :class="{ active: orderType === opt.value }"
                                    :disabled="loadingCheckout || !!cartStore.pendingCheckout"
                                    :aria-pressed="orderType === opt.value"
                                    @click="orderType = opt.value"
                                >
                                    <span class="type-radio" :class="{ active: orderType === opt.value }" aria-hidden="true"></span>
                                    <span class="type-content">
                                        <span class="type-label">{{ opt.label }}</span>
                                        <span class="type-desc">{{ opt.desc }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Ghi chú -->
                        <div class="form-group">
                            <label class="form-label" for="order-note">Ghi chú thêm (Tùy chọn)</label>
                            <textarea
                                id="order-note"
                                v-model="note"
                                :disabled="loadingCheckout || !!cartStore.pendingCheckout"
                                class="note-input"
                                placeholder="Ghi chú về đơn hàng, dị ứng, yêu cầu đặc biệt..."
                                rows="3"
                                maxlength="500"
                            ></textarea>
                            <div class="note-hint">{{ note.length }}/500</div>
                        </div>

                        <!-- Thông tin người nhận (cho cả ONLINE và TAKEAWAY) -->
                        <div class="form-group recipient-block">
                            <label class="form-label">
                                {{ orderType === 'ONLINE' ? 'Thông tin giao hàng' : 'Thông tin người nhận (mang đi)' }}
                            </label>
                            <p class="recipient-help">
                                <template v-if="orderType === 'ONLINE'">
                                    Vui lòng nhập chính xác để shipper có thể giao đến bạn nhanh nhất.
                                </template>
                                <template v-else>
                                    Món sẽ được đóng gói và bàn giao cho shipper. Khách đến quán nhận món.
                                </template>
                            </p>
                            <input
                                v-model="recipientName"
                                type="text"
                                class="recipient-input"
                                placeholder="Họ và tên người nhận"
                                maxlength="100"
                                :disabled="loadingCheckout || !!cartStore.pendingCheckout"
                            />
                            <input
                                v-model="recipientPhone"
                                type="tel"
                                class="recipient-input"
                                placeholder="Số điện thoại (vd: 0912345678)"
                                maxlength="20"
                                :disabled="loadingCheckout || !!cartStore.pendingCheckout"
                            />
                            <textarea
                                v-if="showAddressField()"
                                v-model="deliveryAddress"
                                class="recipient-input address"
                                placeholder="Địa chỉ chi tiết (số nhà, đường, phường/xã, quận/huyện, tỉnh/thành)"
                                maxlength="500"
                                rows="2"
                                :disabled="loadingCheckout || !!cartStore.pendingCheckout"
                            />
                            <p class="recipient-warn" v-if="recipientName || recipientPhone || deliveryAddress">
                                <template v-if="!recipientOk">Vui lòng nhập tên người nhận.</template>
                                <template v-else-if="!phoneOk">Số điện thoại chưa đúng định dạng.</template>
                                <template v-else-if="orderType === 'ONLINE' && !addressOk">Địa chỉ cần ít nhất 10 ký tự.</template>
                            </p>
                        </div>

                        <!-- Vị trí giao hàng (chỉ khi Giao hàng) -->
                        <div class="form-group" v-if="orderType === 'ONLINE'">
                            <label class="form-label">Vị trí giao hàng</label>
                            <p class="recipient-help">
                                
                            </p>
                            <DeliveryLocationPicker
                                :selected="location"
                                :initial-address="deliveryAddress"
                                :disabled="submitting || !!cartStore.pendingCheckout"
                                @change="onLocationChange"
                                @update:address="onResolvedAddress"
                            />
                            <p v-if="quote && orderType === 'ONLINE'" class="recipient-help">
                                Khoảng cách : <strong>{{ quote.distance_km.toFixed(2) }} km</strong> · phí ship {{ money(quote.shipping_fee) }}.
                            </p>
                        </div>
                        <div class="form-group">
                            <VoucherInput
                                v-model="voucherCode"
                                :subtotal="cart?.subtotal ?? 0"
                                :initial-code="voucherFromLink"
                                :disabled="submitting || !!cartStore.pendingCheckout"
                                @applied="onVoucherApplied"
                                @cleared="onVoucherCleared"
                            />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="redeem-points">Dùng điểm giảm giá</label>
                            <p class="recipient-help">
                                Bạn có {{ quote?.balance ?? authStore.user?.loyalty_points ?? 0 }} điểm.
                                1 điểm = 1.000đ, giảm tối đa 30% tiền món.
                                <template v-if="authStore.user?.tier && authStore.user.tier.point_multiplier !== 1">
                                    <br />
                                    <span class="text-chili" role="status">
                                        🌶 Hạng {{ authStore.user.tier.name }} — điểm bạn nhận ở đơn này được nhân
                                        <strong>x{{ authStore.user.tier.point_multiplier.toFixed(1) }}</strong>.
                                    </span>
                                </template>
                            </p>
                            <input id="redeem-points" v-model.number="points" type="number" min="0" step="1" class="recipient-input" :disabled="submitting || !!cartStore.pendingCheckout" />
                            <p v-if="quote" class="recipient-help">Đơn này dùng tối đa {{ quote.max_points }} điểm.</p>
                            <p v-if="quoteError" role="alert" class="recipient-warn">{{ quoteError }}</p>
                            <p v-if="quoteLoading" role="status">Đang tính tổng tiền…</p>
                            <p v-if="orderType === 'ONLINE' && !location" class="recipient-help">Ghim vị trí để tính phí ship và tiếp tục đặt hàng.</p>
                        </div>
                        <div class="form-group payment-method-block">
                            <label class="form-label">Hình thức thanh toán</label>
                            <p class="recipient-help">
                                <span v-if="paymentMethod === 'COD'">Shipper sẽ thu tiền khi giao hàng. Không cần thanh toán trước.</span>
                                <span v-else>Bạn sẽ được chuyển đến VNPay để thanh toán trước khi cửa hàng xác nhận đơn.</span>
                            </p>
                            <div class="payment-options">
                                <label
                                    v-for="opt in PAYMENT_METHOD_OPTIONS"
                                    :key="opt.value"
                                    class="payment-option"
                                    :class="{ active: paymentMethod === opt.value }"
                                >
                                    <input
                                        type="radio"
                                        name="payment-method"
                                        :value="opt.value"
                                        v-model="paymentMethod"
                                    />
                                    <div class="payment-option-content">
                                        <strong>{{ opt.label }}</strong>
                                        <span>{{ opt.desc }}</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Tổng -->
                        <div class="summary-total">
                            <div class="total-row"><span>Tiền món</span><span>{{ subtotalFormatted }}</span></div>
                            <div v-if="quote && quote.combo_discount && quote.combo_discount > 0" class="total-row combo-row">
                                <span>Tiết kiệm combo</span>
                                <span class="discount-amount">−{{ money(quote.combo_discount) }}</span>
                            </div>
                            <div v-if="quote && quote.discount > 0" class="total-row voucher-row">
                                <span>
                                    <span v-if="voucherPreview">Voucher <strong>{{ voucherPreview.code }}</strong></span>
                                    <span v-else>Giảm giá</span>
                                    <span v-if="voucherPreview && points > 0"> + điểm</span>
                                </span>
                                <span class="discount-amount">−{{ quote ? money(quote.discount) : '—' }}</span>
                            </div>
                            <div class="total-row"><span>Phí ship<span v-if="quote && orderType === 'ONLINE'"> ({{ quote.distance_km }} km)</span></span><span>{{ quote ? money(quote.shipping_fee) : 'Chờ vị trí' }}</span></div>
                            <div class="total-row">
                                <span>Tổng cộng ({{ cart?.total_quantity ?? 0 }} món)</span>
                                <span class="total-amount">{{ quote ? money(quote.total) : '—' }}</span>
                            </div>
                        </div>

                        <!-- Thông báo -->

                        <!-- Submit -->
                        <button
                            type="button"
                            class="btn btn-primary btn-submit"
                            :disabled="!canCheckout || submitting || cartStore.isLoading || !!cartStore.pendingCheckout"
                            @click="handleSubmitOrder"
                        >
                            <span v-if="submitting || loadingCheckout">Đang xử lý...</span>
                            <span v-else>Xác nhận đặt hàng</span>
                        </button>

                        <button
                            type="button"
                            class="btn-continue"
                            @click="continueShopping"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Tiếp tục chọn món
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <FooterHome />

    <FoodDetailModal
        v-model:visible="modalVisible"
        :menu-item="modalMenuItem"
        :cart-item="modalItem"
        :loading="loadingItem"
        @submit="handleModalSubmit"
    />
</template>

<style scoped>
.checkout-page {
    min-height: 100vh;
    background: var(--color-gray-50);
    padding: var(--navbar-height) 20px 80px;
}
@media (max-width: 768px) {
    .checkout-page {
        padding: var(--navbar-height-mobile) 20px 80px;
    }
}

.checkout-container {
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 40px;
}

.page-title {
    font-size: 36px;
    color: var(--color-brand-ink);
    margin: 0 0 12px;
    font-weight: 800;
    letter-spacing: -1px;
}

.page-subtitle {
    color: var(--color-gray-500);
    font-size: 16px;
    margin: 0;
}

/* ===== State boxes ===== */
.state-box {
    background: var(--color-white);
    border-radius: var(--radius-2xl);
    padding: 80px 20px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    color: var(--color-gray-500);
    border: 1px solid var(--color-gray-100);
}

.empty-icon {
    color: var(--color-gray-300);
    margin-bottom: 24px;
}

.state-box h3 {
    font-size: 24px;
    color: var(--color-charcoal-black);
    margin: 0 0 12px;
    font-weight: 800;
}

.state-box p {
    color: var(--color-gray-500);
    margin: 0 0 32px;
    font-size: 16px;
}

.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-gray-200);
    border-top-color: var(--color-chili-red);
    border-radius: 50%;
    margin: 0 auto 24px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== Grid ===== */
.checkout-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 32px;
    align-items: flex-start;
}

@media (max-width: 992px) {
    .checkout-grid {
        grid-template-columns: 1fr;
    }
}

/* ===== Cart Items ===== */
.cart-items {
    background: var(--color-white);
    border-radius: var(--radius-2xl);
    padding: 32px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--color-gray-100);
}

.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--color-gray-200);
}

.cart-header h2 {
    font-size: 20px;
    color: var(--color-charcoal-black);
    margin: 0;
    font-weight: 800;
}

.btn-clear {
    background: none;
    border: none;
    color: var(--color-chili-red);
    padding: 8px 16px;
    border-radius: var(--radius-full);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-clear:hover:not(:disabled) {
    background: rgba(215, 25, 32, 0.1);
}

.btn-clear:disabled { opacity: 0.4; cursor: not-allowed; }

.items-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.cart-item {
    display: grid;
    grid-template-columns: 80px 1fr auto auto auto;
    gap: 20px;
    align-items: center;
    padding: 20px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-xl);
    background: var(--color-white);
    transition: all 0.2s;
}

.cart-item:hover {
    border-color: var(--color-gray-300);
    box-shadow: var(--shadow-sm);
}

.item-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: var(--radius-lg);
    background: var(--color-gray-100);
}

.item-info {
    min-width: 0;
}

.item-name {
    font-size: 16px;
    font-weight: 700;
    color: var(--color-charcoal-black);
    margin: 0 0 8px;
}

.item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.meta-chip {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    background: var(--color-gray-100);
    color: var(--color-gray-600);
    font-weight: 600;
}

.meta-chip.spicy {
    background: rgba(215, 25, 32, 0.1);
    color: var(--color-chili-red);
}

.item-note {
    font-size: 13px;
    color: var(--color-gray-500);
    margin: 0;
}

.item-quantity {
    text-align: center;
}

.qty-badge {
    background: var(--color-gray-100);
    color: var(--color-charcoal-black);
    padding: 6px 12px;
    border-radius: var(--radius-full);
    font-size: 14px;
    font-weight: 700;
}

.item-price {
    text-align: right;
    white-space: nowrap;
    min-width: 100px;
}

.subtotal {
    font-size: 16px;
    font-weight: 800;
    color: var(--color-charcoal-black);
}

.unit-price {
    font-size: 12px;
    color: var(--color-gray-500);
    margin-top: 4px;
}

.item-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.btn-icon {
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-full);
    width: 36px;
    height: 36px;
    cursor: pointer;
    color: var(--color-gray-600);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.btn-icon:hover { 
    background: var(--color-gray-100); 
    color: var(--color-charcoal-black);
}

.btn-icon.btn-danger:hover {
    background: var(--color-chili-red);
    border-color: var(--color-chili-red);
    color: var(--color-white);
}

/* ===== Combo saving (Phase 3) ===== */
.combo-summary {
    margin-top: 16px;
    padding: 16px 18px;
    border: 1px dashed var(--color-golden-yellow, #f5b301);
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, rgba(245, 179, 1, 0.08), rgba(229, 57, 53, 0.06));
}

.combo-summary-head {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 700;
    color: var(--color-charcoal-black);
}

.combo-summary-head svg {
    color: var(--color-golden-yellow, #f5b301);
    flex-shrink: 0;
}

.combo-summary-head strong {
    margin-left: auto;
    color: var(--color-chili-red);
    font-weight: 800;
}

.combo-summary-list {
    list-style: none;
    margin: 12px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.combo-summary-list li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--color-gray-600);
}

.combo-summary-list li span {
    color: var(--color-gray-400);
}

.combo-summary-list .combo-saving {
    margin-left: auto;
    color: var(--color-chili-red);
    font-weight: 700;
}

@media (max-width: 768px) {
    .cart-item {
        grid-template-columns: 80px 1fr auto;
        grid-template-areas: 
            "img info actions"
            "img meta actions"
            "qty price price";
    }
    .item-img { grid-area: img; }
    .item-info { grid-area: info; }
    .item-actions { grid-area: actions; flex-direction: row; }
    .item-quantity { grid-area: qty; text-align: left; margin-top: 12px;}
    .item-price { grid-area: price; text-align: right; margin-top: 12px;}
}

/* ===== Summary ===== */
.cart-summary-wrapper {
    position: sticky;
    top: 100px;
}

.cart-summary {
    background: var(--color-white);
    border-radius: var(--radius-2xl);
    padding: 32px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--color-gray-100);
}

.summary-title {
    font-size: 20px;
    color: var(--color-charcoal-black);
    margin: 0 0 24px;
    font-weight: 800;
}

.form-group {
    margin-bottom: 24px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 700;
    color: var(--color-gray-600);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.order-type-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.type-btn {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 16px;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-xl);
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
}

.type-btn:hover { 
    border-color: var(--color-gray-300);
    background: var(--color-gray-50);
}

.type-btn.active {
    background: rgba(215, 25, 32, 0.05);
    border-color: var(--color-chili-red);
}

.type-radio {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid var(--color-gray-300);
    flex-shrink: 0;
    margin-top: 2px;
    position: relative;
    transition: border-color var(--dur-fast) var(--ease-out);
}

.type-radio::after {
    content: '';
    position: absolute;
    inset: 4px;
    background: var(--color-white);
    border-radius: 50%;
    transition: background-color var(--dur-fast) var(--ease-out);
}

.type-radio.active {
    border-color: var(--color-chili-red);
}

.type-radio.active::after {
    background: var(--color-chili-red);
}

.type-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.type-label {
    font-size: 15px;
    font-weight: 700;
    color: var(--color-charcoal-black);
    margin-bottom: 2px;
}

.type-desc {
    font-size: 13px;
    color: var(--color-gray-500);
    line-height: 1.5;
}

.note-input {
    width: 100%;
    padding: 16px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font-size: 15px;
    font-family: inherit;
    resize: vertical;
    min-height: 100px;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: all 0.2s;
}

.note-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.1);
}

.note-hint {
    font-size: 12px;
    color: var(--color-gray-500);
    text-align: right;
    margin-top: 8px;
}

.recipient-block { display: flex; flex-direction: column; gap: var(--space-3); }
.payment-method-block { display: flex; flex-direction: column; gap: var(--space-3); }
.payment-options { display: flex; flex-direction: column; gap: var(--space-2); }
.payment-option {
    display: flex;
    gap: var(--space-3);
    align-items: center;
    padding: 14px 16px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: border-color var(--dur-fast) var(--ease-out),
                background-color var(--dur-fast) var(--ease-out);
    background: var(--color-white);
}
.payment-option:hover {
    border-color: var(--color-gray-300);
}
.payment-option input {
    margin: 0;
    flex-shrink: 0;
    accent-color: var(--color-chili-red);
}
.payment-option.active {
    border-color: var(--color-chili-red);
    background: var(--color-spicy-bg, rgba(215, 25, 32, 0.04));
}
.payment-option-content { display: flex; flex-direction: column; gap: 2px; }
.payment-option-content strong {
    color: var(--color-charcoal-black);
    font-size: 14px;
    font-weight: 700;
}
.payment-option-content span {
    color: var(--color-gray-500);
    font-size: 12.5px;
    line-height: 1.5;
}
.recipient-help {
    color: var(--color-gray-500);
    font-size: 12.5px;
    margin: 0;
    line-height: 1.5;
}
.recipient-input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font: inherit;
    font-size: 14px;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: border-color var(--dur-fast) var(--ease-out),
                box-shadow var(--dur-fast) var(--ease-out);
}
.recipient-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.12);
}
.recipient-input.address { resize: vertical; min-height: 60px; }
.recipient-warn {
    color: var(--color-error);
    font-size: 12px;
    margin: 4px 0 0;
    font-weight: 500;
}

.info-block {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: var(--radius-lg);
    padding: 14px;
    color: #9a3412;
    font-size: 13px;
    line-height: 1.6;
}
.info-block p { margin: 0; }

.summary-total {
    padding: 24px 0;
    border-top: 1px dashed var(--color-gray-200);
    border-bottom: 1px dashed var(--color-gray-200);
    margin-bottom: 24px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 16px;
    font-weight: 600;
    color: var(--color-gray-600);
}

.total-amount {
    font-size: 24px;
    font-weight: 800;
    color: var(--color-chili-red);
}

.total-row.voucher-row {
    color: #15803d;
}
.total-row.combo-row {
    color: #15803d;
}
.total-row.voucher-row strong {
    color: #15803d;
    background: rgba(22, 163, 74, 0.08);
    padding: 1px 8px;
    border-radius: var(--radius-full);
    margin-left: 4px;
}
.discount-amount {
    color: #15803d;
    font-weight: 700;
}

.alert {
    padding: 16px;
    border-radius: var(--radius-lg);
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 16px;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.btn-submit {
    width: 100%;
    padding: 16px;
    font-size: 16px;
    margin-bottom: 16px;
}

.btn-continue {
    width: 100%;
    padding: 12px;
    background: transparent;
    color: var(--color-gray-500);
    border: none;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-continue:hover {
    color: var(--color-charcoal-black);
}
</style>
