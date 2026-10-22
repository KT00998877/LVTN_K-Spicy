<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
/**
 * OrderDetailView — Trang chi tiết đơn hàng cho khách.
 *
 * Hỗ trợ 3 loại đơn:
 *  - DINE_IN: tại bàn
 *  - TAKEAWAY: mang đi (chuẩn bị xong → shipper xác nhận đã lấy)
 *  - ONLINE: giao tận nơi (chuẩn bị xong → shipper giao tận nơi)
 *
 * Đơn TAKEAWAY/ONLINE có banner thanh toán mô phỏng + modal chọn phương thức.
 *
 * Không dùng icon — chỉ text + badge màu.
 */

import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useOrderStore } from '@/stores/orders'
import type { OrderItemStatus, OrderStatus } from '@/types/orders'
import { createVNPay } from '@/api/payments'
import { useAuthStore } from '@/stores/auth'
import { getOrderRoute } from '@/api/orders'
import type { DeliveryRoute } from '@/api/staff'
import ShipperRouteMap from '@/components/ShipperRouteMap.vue'

import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import ReviewSection from '@/components/review/ReviewSection.vue'
import ReviewFormModal from '@/components/review/ReviewFormModal.vue'
import { fetchReviewEligibility } from '@/api/review'
import type { ReviewEligibility } from '@/types/review'

const route = useRoute()
const router = useRouter()
const orderStore = useOrderStore()
const authStore = useAuthStore()
const { current, loadingDetail, loadingPayment, error } = storeToRefs(orderStore)

/* ---------------- Status maps ---------------- */

const STATUS_LABELS: Record<OrderStatus, { label: string; cls: string }> = {
    PENDING:             { label: 'Chờ xác nhận',    cls: 'pill pill-amber'  },
    CONFIRMED:           { label: 'Đã xác nhận',     cls: 'pill pill-blue'   },
    PREPARING:           { label: 'Đang chế biến',   cls: 'pill pill-orange' },
    READY:               { label: 'Sẵn sàng',        cls: 'pill pill-cyan'   },
    READY_NOTIFY_SHIPPER:{ label: 'Sẵn sàng giao',   cls: 'pill pill-cyan'   },
    SERVED:              { label: 'Đã phục vụ',      cls: 'pill pill-green'  },
    DELIVERING:          { label: 'Đang giao',       cls: 'pill pill-blue'   },
    DELIVERED:           { label: 'Chờ bạn xác nhận', cls: 'pill pill-cyan'  },
    COMPLETED:           { label: 'Hoàn thành',       cls: 'pill pill-green'  },
    CANCELLED:           { label: 'Đã hủy',          cls: 'pill pill-red'    },
}

const ITEM_STATUS_LABELS: Record<OrderItemStatus, { label: string; cls: string }> = {
    PENDING:    { label: 'Chờ chế biến',    cls: 'pill pill-amber'  },
    PREPARING:  { label: 'Đang chế biến',    cls: 'pill pill-orange' },
    READY:      { label: 'Đã hoàn thành',    cls: 'pill pill-cyan'   },
    SERVED:     { label: 'Đã lên món',       cls: 'pill pill-green'  },
    CANCELLED:  { label: 'Đã hủy',           cls: 'pill pill-red'    },
}

const TYPE_LABELS = {
    DINE_IN: 'Tại bàn',
    TAKEAWAY: 'Mang đi',
    ONLINE: 'Giao hàng (Online)',
} as const

const PAYMENT_METHOD_OPTIONS = [{ value: 'VNPAY', label: 'VNPay', desc: 'Thanh toán qua ngân hàng / QR trên VNPay Sandbox.' }]

/* ---------------- Data fetching ---------------- */

const orderId = computed(() => String(route.params.id || ''))

watch(orderId, async () => {
    if (!orderId.value) {
        router.replace('/tai-khoan')
        return
    }
    await orderStore.fetchDetail(orderId.value)
    await loadReviewEligibilityForItems()
}, { immediate: true })

/* ---------------- Helpers ---------------- */

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(n)
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('vi-VN', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

/**
 * Timeline cho đơn ONLINE/TAKEAWAY:
 *   Chờ xác nhận → Đã xác nhận → Đang chế biến → Sẵn sàng → Đang giao
 *     → Đã giao (chờ khách xác nhận) → Hoàn thành
 *
 * Đơn DINE_IN: PENDING → CONFIRMED → PREPARING → READY → SERVED → COMPLETED
 */
const TIMELINE_DINEIN: OrderStatus[] = ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'SERVED', 'COMPLETED']
const TIMELINE_DELIVERY: OrderStatus[] = ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'DELIVERING', 'DELIVERED', 'COMPLETED']

const timeline = computed<OrderStatus[]>(() => {
    const t = current.value?.order_type
    return t === 'DINE_IN' ? TIMELINE_DINEIN : TIMELINE_DELIVERY
})
const timelineIndex = computed(() => {
    const status = current.value?.status
    if (!status) return -1
    if (status === 'CANCELLED') return -1
    return timeline.value.indexOf(status === 'READY_NOTIFY_SHIPPER' ? 'READY' : status)
})

function stepState(idx: number): 'done' | 'active' | 'todo' {
    if (timelineIndex.value < 0) return 'todo'
    if (current.value?.status === 'COMPLETED') return 'done'
    if (idx < timelineIndex.value) return 'done'
    if (idx === timelineIndex.value) return 'active'
    return 'todo'
}

function timelineStepLabel(idx: number): string {
    const step = timeline.value[idx]
    if (!step) return ''
    if (step === 'READY' && current.value?.status === 'READY_NOTIFY_SHIPPER') {
        return STATUS_LABELS.READY_NOTIFY_SHIPPER.label
    }
    if (step === 'DELIVERING') {
        return current.value?.order_type === 'TAKEAWAY' ? 'Shipper giữ hàng' : 'Đang giao'
    }
    if (step === 'DELIVERED') {
        return 'Đã giao - chờ khách xác nhận'
    }
    if (step === 'COMPLETED') {
        return current.value?.order_type === 'TAKEAWAY' ? 'Khách đã lấy' : 'Hoàn thành'
    }
    return STATUS_LABELS[step].label
}

function backToList() {
    router.push({ name: 'account', query: { tab: 'orders' } })
}

/* ---------------- Payment modal ---------------- */

const paymentModalOpen = ref(false)
const chosenMethod = ref('VNPAY')

const needsPayment = computed(() => {
    const order = current.value
    if (!order) return false
    // Cả ONLINE và TAKEAWAY đều cần thanh toán trước khi cửa hàng xác nhận
    if (order.order_type !== 'ONLINE' && order.order_type !== 'TAKEAWAY') return false
    if (order.status !== 'PENDING' || order.payment_method !== 'PREPAID') return false
    return order.payment?.status !== 'PAID'
})

function openPaymentModal() {
    paymentModalOpen.value = true
}
function closePaymentModal() {
    paymentModalOpen.value = false
}

async function confirmPayment() {
    if (!current.value || loadingPayment.value) return
    const auth = useAuthStore()
    const revision = auth.sessionRevision
    const token = auth.token
    orderStore.loadingPayment = true
    orderStore.error = null
    try {
        const payment = await createVNPay(current.value.id)
        if (auth.isCurrentSession(revision, token)) window.location.assign(payment.payment_url)
    } catch (e) {
        if (auth.isCurrentSession(revision, token)) orderStore.error = (e as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Không tạo được thanh toán VNPay. Vui lòng thử lại.'
    } finally { if (auth.isCurrentSession(revision, token)) orderStore.loadingPayment = false }
}

/* ---------------- Receipt confirmation (khách bấm "Tôi đã nhận hàng") ----------------
 * Chỉ áp dụng cho đơn ONLINE/TAKEAWAY khi status = DELIVERED (shipper đã giao xong).
 * Khi bấm:
 *  - Backend chuyển status = COMPLETED, cộng điểm thưởng (nếu đơn > 0).
 *  - Trả về `points_awarded` + `loyalty_points` (tổng) → toast thông báo.
 *
 * Nếu khách không bấm: đơn vẫn ở DELIVERED, KHÔNG tích điểm (theo yêu cầu đồ án).
 */
const { loadingConfirm } = storeToRefs(orderStore)
const receiptModalOpen = ref(false)
const lastReceiptSummary = ref<{ pointsAwarded: number; totalPoints: number } | null>(null)

/** Theo dõi shipper: chỉ bật khi đơn ONLINE đã DELIVERING/COMPLETED */
const routeData = ref<DeliveryRoute | null>(null)
const routeLoading = ref(false)
const routeError = ref('')
const routeModalOpen = ref(false)

async function openRouteModal() {
    if (!current.value || !isOnlineOrder.value) return
    routeModalOpen.value = true
    if (routeData.value) return // đã load rồi
    routeLoading.value = true
    routeError.value = ''
    try {
        console.info('[route] requesting route for order', current.value.id)
        routeData.value = await getOrderRoute(current.value.id)
        console.info('[route] success:', routeData.value)
    } catch (e) {
        const err = e as { response?: { status?: number; data?: { message?: string } }; message?: string }
        console.error('[getOrderRoute] failed:', err.response?.status, err.response?.data, err.message)
        // Bỏ qua lỗi — luôn vẽ modal tối thiểu với restaurant/customer từ current.value
        // và đường thẳng nối giữa 2 marker.
        if (current.value?.delivery_latitude != null && current.value?.delivery_longitude != null) {
            // Hardcode theo config/checkout.php — fallback khi API không trả được.
            routeData.value = {
                restaurant: {
                    name: 'Mì Cay Đại học Cần Thơ',
                    address: 'Đại học Cần Thơ, Khu II, đường 3/2, phường Ninh Kiều, TP. Cần Thơ',
                    latitude: 10.0308, longitude: 105.7689,
                },
                customer: {
                    name: current.value.recipient_name ?? null,
                    phone: current.value.recipient_phone ?? null,
                    address: current.value.delivery_address ?? null,
                    latitude: Number(current.value.delivery_latitude),
                    longitude: Number(current.value.delivery_longitude),
                    note: current.value.note ?? null,
                },
                route: {
                    distance_km: 0, duration_min: 0, polyline: null, steps: [], source: 'haversine_fallback',
                },
            }
            routeError.value = 'Không tính được đường đi. Hiển thị đường thẳng nối 2 điểm — bạn có thể bấm "Mở Google Maps" để dẫn đường thực tế.'
            return
        }
        routeError.value = err.response?.data?.message
            ?? (err.response?.status === 401 ? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'
            : err.response?.status === 403 ? 'Bạn không có quyền xem đường đi của đơn này.'
            : err.response?.status === 422 ? 'Đơn này không có toạ độ giao hàng.'
            : 'Không tải được chỉ đường. Bạn vẫn có thể dùng Google Maps.')
    } finally {
        routeLoading.value = false
    }
}
function closeRouteModal() {
    routeModalOpen.value = false
}
function googleMapsUrl(lat: number, lng: number): string {
    return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`
}
function distanceText(meters: number): string {
    return meters >= 1000 ? `${(meters / 1000).toFixed(2)} km` : `${Math.round(meters)} m`
}
function durationText(seconds: number): string {
    if (seconds < 60) return `${Math.round(seconds)} giây`
    const m = Math.round(seconds / 60)
    return m < 60 ? `${m} phút` : `${Math.floor(m / 60)}h ${m % 60}p`
}

const canConfirmReceipt = computed(() => {
    const o = current.value
    if (!o) return false
    return o.status === 'DELIVERED' && (o.order_type === 'ONLINE' || o.order_type === 'TAKEAWAY')
})

/** Ước tính điểm sẽ nhận = 1 điểm / 10.000đ (làm tròn xuống, tối thiểu 1). */
const estimatedPoints = computed(() => {
    const o = current.value
    if (!o) return 0
    const total = (o.total_amount ?? 0)
    if (total <= 0) return 0
    return Math.max(1, Math.floor(total / 10000))
})

function openReceiptModal() {
    receiptModalOpen.value = true
}
function closeReceiptModal() {
    receiptModalOpen.value = false
}

async function submitReceiptConfirmation() {
    if (!current.value) return
    const res = await orderStore.confirmReceipt(current.value.id)
    if (res.ok) {
        lastReceiptSummary.value = {
            pointsAwarded: res.pointsAwarded,
            totalPoints: res.totalPoints,
        }
        closeReceiptModal()
        // Tự đóng toast sau 6 giây
        setTimeout(() => {
            if (lastReceiptSummary.value
                && lastReceiptSummary.value.pointsAwarded === res.pointsAwarded
                && lastReceiptSummary.value.totalPoints === res.totalPoints) {
                lastReceiptSummary.value = null
            }
        }, 6000)
    }
}

/* ---------------- Order events ---------------- */

const eventLabels: Record<string, string> = {
    PLACED: 'Khách đặt đơn',
    SIMULATED_PAYMENT: 'Khách thanh toán mô phỏng',
    CONFIRMED: 'Cửa hàng xác nhận đơn',
    HANDED_OVER: 'Bàn giao cho shipper',
    DELIVERED: 'Shipper xác nhận đã giao xong',
    DELIVERY_ISSUE: 'Báo cáo sự cố',
    REJECTED: 'Cửa hàng từ chối',
    CUSTOMER_RECEIVED: 'Khách xác nhận đã nhận hàng',
    POINTS_AWARDED: 'Cộng điểm thưởng',
    COD_COLLECTED: 'Shipper thu tiền COD',
    PICKED_UP: 'Shipper đã lấy món',
    ARRIVED: 'Shipper đã tới điểm giao',
}
function formatEvent(name: string): string {
    return eventLabels[name] ?? name
}

const isDeliveryOrder = computed(() => {
    const t = current.value?.order_type
    return t === 'ONLINE' || t === 'TAKEAWAY'
})
const isOnlineOrder = computed(() => current.value?.order_type === 'ONLINE')
const isTakeawayOrder = computed(() => current.value?.order_type === 'TAKEAWAY')

/* ---------------- Review per item (chỉ hiển thị khi đơn COMPLETED) ---------------- */
const reviewEligibilityMap = ref<Record<number, ReviewEligibility>>({})
const reviewModalOpen = ref(false)
const reviewMenuItem = ref<{ id: number; name: string; image_url?: string | null } | null>(null)
const reviewExisting = ref<{ id: number; rating: number; comment: string | null; photos: { id: number; url: string }[] } | null>(null)

async function loadReviewEligibilityForItems() {
    if (!current.value || current.value.status !== 'COMPLETED') return
    if (!authStore.isAuthenticated) return
    const items = current.value.items
    for (const it of items) {
        try {
            const data = await fetchReviewEligibility(it.menu_item_id)
            reviewEligibilityMap.value[it.menu_item_id] = data
        } catch {
            // ignore
        }
    }
}

async function openReviewFor(itemId: number, name: string, imageUrl: string | null) {
    reviewMenuItem.value = { id: itemId, name, image_url: imageUrl }
    const eligibility = reviewEligibilityMap.value[itemId]
    reviewExisting.value = eligibility?.existing_review
        ? {
            id: eligibility.existing_review.id,
            rating: eligibility.existing_review.rating,
            comment: eligibility.existing_review.comment,
            photos: eligibility.existing_review.photos,
        }
        : null
    reviewModalOpen.value = true
}

async function onReviewSubmitted() {
    await loadReviewEligibilityForItems()
    // Refresh order detail để cập nhật COMPLETED + trạng thái.
    if (orderId.value) await orderStore.fetchDetail(orderId.value, true)
}
useRealtimeRefresh(() => orderStore.fetchDetail(orderId.value, true), () => !!orderId.value && !orderStore.loadingDetail && !orderStore.loadingPayment && !orderStore.loadingConfirm)
</script>

<template>
    <NavbarHome />

    <main style="min-height: 70vh; background: #fdfdfb; padding: var(--navbar-height) 20px 40px;">
        <p v-if="route.query.payment_error === '1' && needsPayment" role="alert">
            Đơn hàng đã được tạo nhưng chưa mở được VNPAY. Bạn có thể thanh toán lại cho đơn này; không cần đặt đơn mới.
        </p>
        <div style="max-width: 880px; margin: 0 auto;">
            <button class="back-link" @click="backToList">Quay lại đơn hàng của tôi</button>

            <div v-if="loadingDetail" class="state-box">Đang tải chi tiết đơn hàng...</div>

            <div v-else-if="!current" class="state-box">
                <p>{{ error || 'Không tìm thấy đơn hàng.' }}</p>
                <button class="au-btn au-btn-primary" @click="backToList">Về danh sách đơn</button>
            </div>

            <template v-else>
                <!-- ====== Header ====== -->
                <div class="detail-header">
                    <div>
                        <h1 style="margin: 0 0 4px; color: var(--hp-dark); font-size: 24px;">
                            Đơn hàng #{{ current.code }}
                        </h1>
                        <div style="color: var(--hp-muted); font-size: 14px;">
                            Đặt lúc {{ formatDate(current.placed_at) }}
                        </div>
                    </div>
                    <span :class="STATUS_LABELS[current.status].cls" style="font-size: 14px; padding: 8px 14px;">
                        {{ STATUS_LABELS[current.status].label }}
                    </span>
                </div>

                <!-- ====== Payment banner (chỉ cho ONLINE/TAKEAWAY chưa hủy) ====== -->
                <div v-if="isDeliveryOrder && current.status !== 'CANCELLED'" class="payment-banner" :class="current.payment?.status === 'PAID' ? 'paid' : 'unpaid'">
                    <div>
                        <strong>
                            <template v-if="current.payment?.status === 'PAID'">Đã thanh toán</template>
                            <template v-else>Chờ thanh toán</template>
                        </strong>
                        <span v-if="current.payment">
                            · Hóa đơn <code>{{ current.payment.invoice_code }}</code>
                            · Tổng {{ formatCurrency(current.payment.total) }}
                            <span v-if="current.payment.is_simulated"> · (mô phỏng)</span>
                        </span>
                        <span v-else>Hóa đơn sẽ được tạo khi đơn hoàn tất.</span>
                    </div>
                    <button
                        v-if="needsPayment"
                        class="au-btn au-btn-primary"
                        :disabled="loadingPayment"
                        @click="openPaymentModal"
                    >
                        {{ loadingPayment ? 'Đang xử lý…' : 'Thanh toán ngay' }}
                    </button>
                </div>

                <!-- ====== Timeline (cho đơn chưa hủy) ====== -->
                <section v-if="current.status !== 'CANCELLED'" class="detail-card">
                    <h2 class="card-title">Tiến trình đơn hàng</h2>
                    <div class="timeline">
                        <div
                            v-for="(step, idx) in timeline"
                            :key="step"
                            class="timeline-step"
                            :class="stepState(idx)"
                        >
                            <div class="step-dot">
                                {{ stepState(idx) === 'done' ? '✓' : idx + 1 }}
                            </div>
                            <div class="step-label">{{ timelineStepLabel(idx) }}</div>
                            <div v-if="idx < timeline.length - 1" class="step-line" :class="stepState(idx)"></div>
                        </div>
                    </div>
                </section>

                <!-- ====== Tracking card (đơn ONLINE chờ bàn giao hoặc đang giao) ====== -->
                <section v-if="isOnlineOrder && (current.status === 'DELIVERING' || current.status === 'READY_NOTIFY_SHIPPER')" class="tracking-card" role="region" aria-label="Theo dõi shipper">
                    <div class="tracking-head">
                        <strong>{{ current.status === 'READY_NOTIFY_SHIPPER' ? 'Món đã sẵn sàng, chờ bàn giao cho shipper' : '🛵 Shipper đang giao đơn của bạn' }}</strong>
                        <button type="button" class="tracking-btn" @click="openRouteModal">
                            Xem đường đi
                        </button>
                    </div>
                    <p class="muted small">Bấm "Xem đường đi" để xem khoảng cách và hướng dẫn đường đi bằng ô tô từ quán đến nhà bạn.</p>
                </section>

                <!-- ====== Receipt confirmation CTA (chỉ DELIVERED) ====== -->
                <section v-if="canConfirmReceipt" class="receipt-card" role="region" aria-label="Xác nhận đã nhận hàng">
                    <div class="receipt-copy">
                        <strong>Shipper đã giao đơn của bạn.</strong>
                        <span>
                            Bấm <em>"Tôi đã nhận hàng"</em> để hoàn tất đơn
                            và nhận <strong>{{ estimatedPoints }}</strong> điểm thưởng.
                        </span>
                        <small>Nếu không bấm, đơn vẫn được tính là đã giao nhưng không được tích điểm.</small>
                    </div>
                    <button
                        class="au-btn au-btn-primary"
                        :disabled="loadingConfirm"
                        @click="openReceiptModal"
                    >
                        {{ loadingConfirm ? 'Đang xử lý…' : 'Tôi đã nhận hàng' }}
                    </button>
                </section>

                <!-- ====== Điểm thưởng sau khi xác nhận ====== -->
                <section
                    v-else-if="isDeliveryOrder && current.status === 'COMPLETED' && (current.points_awarded ?? 0) > 0"
                    class="points-card"
                    role="status"
                >
                    <div class="points-icon" aria-hidden="true">★</div>
                    <div>
                        <strong>+{{ current.points_awarded }} điểm thưởng</strong>
                        <span>Bạn đã nhận điểm cho đơn này. Điểm dùng để đổi ưu đãi ở lần sau.</span>
                    </div>
                </section>

                <!-- ====== Thông tin giao hàng / người nhận ====== -->
                <section v-if="isDeliveryOrder" class="detail-card">
                    <h2 class="card-title">
                        {{ isOnlineOrder ? 'Thông tin giao hàng' : 'Thông tin người nhận (mang đi)' }}
                    </h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Người nhận</label>
                            <span>{{ current.recipient_name || '—' }}</span>
                        </div>
                        <div class="info-item">
                            <label>Số điện thoại</label>
                            <span>{{ current.recipient_phone || '—' }}</span>
                        </div>
                        <div v-if="isOnlineOrder" class="info-item" style="grid-column: span 2;">
                            <label>Địa chỉ giao hàng</label>
                            <span>{{ current.delivery_address || '—' }}</span>
                            <a v-if="current.delivery_latitude != null && current.delivery_longitude != null"
                                :href="`https://www.google.com/maps/search/?api=1&query=${current.delivery_latitude},${current.delivery_longitude}`"
                                target="_blank" rel="noopener noreferrer">Xem vị trí đã ghim</a>
                        </div>
                        <div v-else class="info-item" style="grid-column: span 2;">
                            <label>Hình thức nhận</label>
                            <span>Khách đến quán nhận món — không cần giao tận nơi</span>
                        </div>
                        <div class="info-item">
                            <label>Xác nhận lúc</label>
                            <span>{{ formatDate(current.confirmed_at) }}</span>
                        </div>
                        <div class="info-item">
                            <label>Bàn giao shipper</label>
                            <span>{{ formatDate(current.handed_over_at) }}</span>
                        </div>
                        <div class="info-item">
                            <label>Shipper</label>
                            <span>{{ current.shipper_name || '—' }}</span>
                        </div>
                        <div class="info-item">
                            <label>{{ isTakeawayOrder ? 'Khách đã lấy' : 'Giao thành công' }}</label>
                            <span>{{ formatDate(current.delivered_at) }}</span>
                        </div>
                        <div class="info-item" v-if="current.customer_received_at">
                            <label>Khách xác nhận nhận hàng</label>
                            <span>{{ formatDate(current.customer_received_at) }}</span>
                        </div>
                        <div class="info-item" v-if="current.points_awarded">
                            <label>Điểm thưởng đã cộng</label>
                            <span style="color: var(--color-success, #16a34a); font-weight: 700;">
                                +{{ current.points_awarded }} điểm
                            </span>
                        </div>
                        <div v-if="current.delivery_issue" class="info-item" style="grid-column: span 2;">
                            <label>Sự cố giao hàng</label>
                            <span style="color:#b91c1c;">{{ current.delivery_issue }}</span>
                        </div>
                    </div>
                </section>

                <!-- ====== Thông tin chung ====== -->
                <section class="detail-card">
                    <h2 class="card-title">Thông tin đơn</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Mã đơn</label>
                            <span>#{{ current.code }}</span>
                        </div>
                        <div class="info-item">
                            <label>Loại đơn</label>
                            <span>{{ TYPE_LABELS[current.order_type] }}</span>
                        </div>
                        <div class="info-item">
                            <label>Thời gian đặt</label>
                            <span>{{ formatDate(current.placed_at) }}</span>
                        </div>
                        <div class="info-item">
                            <label>Hoàn thành</label>
                            <span>{{ formatDate(current.completed_at) }}</span>
                        </div>
                        <div v-if="current.note" class="info-item" style="grid-column: span 2;">
                            <label>Ghi chú</label>
                            <span>{{ current.note }}</span>
                        </div>
                    </div>
                </section>

                <!-- ====== Danh sách món ====== -->
                <section class="detail-card">
                    <h2 class="card-title">Danh sách món ({{ current.items.length }})</h2>
                    <div class="item-list">
                        <article v-for="item in current.items" :key="item.id" class="item-row">
                            <div class="item-main">
                                <div class="item-name">
                                    {{ item.name }}
                                    <span v-if="item.quantity > 1" class="qty">× {{ item.quantity }}</span>
                                </div>

                                <div v-if="item.spicy_level !== null && item.spicy_level !== undefined" class="item-meta-row">
                                    <span class="spicy-chip">
                                        {{ item.spicy_level === 0 ? 'Không cay' : 'Cấp ' + item.spicy_level }}
                                    </span>
                                </div>

                                <div v-if="item.toppings && item.toppings.length > 0" class="item-meta-row">
                                    <span class="topping-chip">
                                        Topping: {{ item.toppings.map(t => t.name).join(', ') }}
                                    </span>
                                </div>

                                <div v-if="item.note" class="item-note">Ghi chú: {{ item.note }}</div>
                                <div v-if="item.cancellation_reason" class="item-note item-note-cancel">
                                    Lý do hủy: {{ item.cancellation_reason }}
                                </div>
                                

                                <!-- Review button per item -->
                                <div v-if="current.status === 'COMPLETED' && authStore.isAuthenticated" class="item-review-row">
                                    <button
                                        v-if="reviewEligibilityMap[item.menu_item_id]?.has_reviewed"
                                        type="button"
                                        class="btn-review btn-review-edit"
                                        @click="openReviewFor(item.menu_item_id, item.name, null)"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                        Sửa đánh giá ({{ reviewEligibilityMap[item.menu_item_id]?.existing_review?.rating }}★)
                                    </button>
                                    <button
                                        v-else-if="reviewEligibilityMap[item.menu_item_id]?.can_review"
                                        type="button"
                                        class="btn-review btn-review-create"
                                        @click="openReviewFor(item.menu_item_id, item.name, null)"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                        Đánh giá món này
                                    </button>
                                    <span v-else-if="!reviewEligibilityMap[item.menu_item_id]" class="btn-review btn-review-disabled">Đang kiểm tra…</span>
                                </div>
                            </div>
                            <div class="item-price">
                                <div>{{ formatCurrency(item.unit_price) }}</div>
                                <div style="color: var(--hp-muted); font-size: 13px;">
                                    = {{ formatCurrency(item.subtotal) }}
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <!-- ====== Review Section (chỉ đơn COMPLETED) ====== -->
                <section v-if="current.status === 'COMPLETED'" class="detail-card">
                    <ReviewSection
                        v-if="current.items.length > 0 && current.items[0]"
                        :menu-item="{ id: current.items[0]!.menu_item_id, name: current.items[0]!.name }"
                        @changed="onReviewSubmitted"
                    />
                </section>

                <!-- ====== Tổng tiền ====== -->
                <section class="detail-card">
                    <h2 class="card-title">Tổng thanh toán</h2>
                    <div class="total-block">
                        <div class="total-row">
                            <span>Tạm tính</span>
                            <span>{{ formatCurrency(current.subtotal) }}</span>
                        </div>
                        <div v-if="current.discount > 0" class="total-row" style="color: #16a34a;">
                            <span>Giảm giá</span>
                            <span>− {{ formatCurrency(current.discount) }}</span>
                        </div>
                        <div v-if="isOnlineOrder && current.shipping_fee" class="total-row">
                            <span>Phí giao hàng</span>
                            <span>{{ formatCurrency(current.shipping_fee) }}</span>
                        </div>
                        <div class="total-row total-final">
                            <span>Tổng cộng</span>
                            <span style="color: #e63946;">
                                {{ formatCurrency(current.total_amount) }}
                            </span>
                        </div>
                    </div>
                </section>
                
            </template>
        </div>

        <!-- ========== Payment Modal ========== -->
        <Teleport to="body">
            <div v-if="paymentModalOpen" class="modal-overlay" @click.self="closePaymentModal">
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title">
                    <header class="modal-head">
                        <h3 id="payment-modal-title">Chọn phương thức thanh toán</h3>
                        <button class="close-btn" aria-label="Đóng" @click="closePaymentModal">Đóng</button>
                    </header>
                    <p class="modal-sub">
                        Đơn <strong>#{{ current?.code }}</strong> ·
                        Tổng <strong style="color:#e63946;">{{ current?.payment ? formatCurrency(current.payment.total) : formatCurrency((current?.total_amount ?? 0)) }}</strong>
                        <br />
                        Bạn sẽ được chuyển đến VNPay Sandbox để thanh toán. Kết quả được xác nhận bởi VNPay trước khi nhà hàng ghi nhận đã thanh toán.
                    </p>

                    <fieldset class="method-list">
                        <legend class="visually-hidden">Phương thức</legend>
                        <label v-for="opt in PAYMENT_METHOD_OPTIONS" :key="opt.value" class="method-card" :class="{ active: chosenMethod === opt.value }">
                            <input type="radio" name="pm-method" :value="opt.value" v-model="chosenMethod" />
                            <span class="method-meta">
                                <strong>{{ opt.label }}</strong>
                                <span>{{ opt.desc }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <p v-if="error" class="modal-error" role="alert">{{ error }}</p>

                    <footer class="modal-actions">
                        <button type="button" class="au-btn au-btn-ghost" :disabled="loadingPayment" @click="closePaymentModal">
                            Huỷ
                        </button>
                        <button
                            type="button"
                            class="au-btn au-btn-primary"
                            :disabled="loadingPayment"
                            @click="confirmPayment"
                        >
                            {{ loadingPayment ? 'Đang xử lý…' : 'Xác nhận thanh toán' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Teleport>

        <!-- ========== Receipt confirmation modal ========== -->
        <Teleport to="body">
            <div v-if="receiptModalOpen" class="modal-overlay" @click.self="closeReceiptModal">
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="receipt-modal-title">
                    <header class="modal-head">
                        <h3 id="receipt-modal-title">Xác nhận đã nhận hàng</h3>
                        <button class="close-btn" aria-label="Đóng" @click="closeReceiptModal">Đóng</button>
                    </header>
                    <p class="modal-sub">
                        Đơn <strong>#{{ current?.code }}</strong> đã được shipper giao xong.
                        Sau khi bạn xác nhận, đơn sẽ hoàn tất và bạn được
                        <strong style="color: #16a34a;">+{{ estimatedPoints }} điểm thưởng</strong>.
                    </p>

                    <ul class="receipt-summary">
                        <li>
                            <span>Tổng đơn</span>
                            <strong>{{ formatCurrency((current?.total_amount ?? 0)) }}</strong>
                        </li>
                        <li>
                            <span>Điểm ước tính</span>
                            <strong>+{{ estimatedPoints }} điểm</strong>
                        </li>
                    </ul>

                    <p v-if="error" class="modal-error" role="alert">{{ error }}</p>

                    <footer class="modal-actions">
                        <button type="button" class="au-btn au-btn-ghost" :disabled="loadingConfirm" @click="closeReceiptModal">
                            Để sau
                        </button>
                        <button
                            type="button"
                            class="au-btn au-btn-primary"
                            :disabled="loadingConfirm"
                            @click="submitReceiptConfirmation"
                        >
                            {{ loadingConfirm ? 'Đang xử lý…' : 'Tôi đã nhận hàng' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Teleport>

        <!-- ========== Receipt success toast (auto hide) ========== -->
        <Teleport to="body">
            <transition name="toast-fade">
                <div v-if="lastReceiptSummary" class="receipt-toast" role="status">
                    <div class="receipt-toast-icon">✓</div>
                    <div>
                        <strong>Đã ghi nhận đơn hoàn tất</strong>
                        <span>
                            Bạn vừa nhận <strong>+{{ lastReceiptSummary.pointsAwarded }} điểm</strong>.
                            Tổng điểm hiện tại: <strong>{{ lastReceiptSummary.totalPoints }}</strong>.
                        </span>
                    </div>
                    <button class="receipt-toast-close" aria-label="Đóng" @click="lastReceiptSummary = null">×</button>
                </div>
            </transition>
        </Teleport>

        <!-- ========== Tracking route modal (customer xem shipper) ========== -->
        <Teleport to="body">
            <div v-if="routeModalOpen" class="modal-overlay" @click.self="closeRouteModal">
                <div class="modal route-modal" role="dialog" aria-modal="true" aria-label="Đường đi của shipper">
                    <header class="modal-head">
                        <h3>🗺️ Đường đi của shipper</h3>
                        <button class="close-btn" aria-label="Đóng" @click="closeRouteModal">Đóng</button>
                    </header>
                    <div v-if="routeLoading" class="state-inline">Đang tính đường đi bằng ô tô…</div>
                    <p v-else-if="routeError" class="modal-error" role="alert">{{ routeError }}</p>
                    <template v-else-if="routeData">
                        <section class="route-addresses">
                            <div class="route-row">
                                <span class="route-pin-icon">🍜</span>
                                <div>
                                    <strong>Xuất phát</strong>
                                    <p>{{ routeData.restaurant.name }} · {{ routeData.restaurant.address }}</p>
                                </div>
                            </div>
                            <div class="route-divider" aria-hidden="true"></div>
                            <div class="route-row">
                                <span class="route-pin-icon">📍</span>
                                <div>
                                    <strong>Điểm giao</strong>
                                    <p>{{ routeData.customer.address || '—' }}</p>
                                    <p class="muted small">{{ routeData.customer.latitude.toFixed(5) }}, {{ routeData.customer.longitude.toFixed(5) }}</p>
                                </div>
                            </div>
                        </section>
                        <section class="route-summary">
                            <div class="metric">
                                <span>Khoảng cách đường bộ</span>
                                <strong>{{ routeData.route.distance_km.toFixed(2) }} km</strong>
                            </div>
                            <div class="metric">
                                <span>Thời gian ước tính</span>
                                <strong>{{ routeData.route.duration_min > 0 ? routeData.route.duration_min + ' phút' : '—' }}</strong>
                            </div>
                            <a class="au-btn au-btn-primary external"
                                :href="googleMapsUrl(routeData.customer.latitude, routeData.customer.longitude)"
                                target="_blank" rel="noopener noreferrer">
                                Mở Google Maps
                            </a>
                        </section>
                        <ShipperRouteMap :route-data="routeData" />
                        <section v-if="routeData.route.steps.length" class="route-steps">
                            <h4>Chỉ dẫn đường đi (ô tô)</h4>
                            <ol>
                                <li v-for="(step, idx) in routeData.route.steps" :key="idx">
                                    <span class="step-idx">{{ idx + 1 }}</span>
                                    <div>
                                        <p class="step-instruction">{{ step.instruction }}</p>
                                        <p class="step-meta">
                                            {{ distanceText(step.distance_m) }} · {{ durationText(step.duration_s) }}
                                            <span v-if="step.name" class="step-road">· {{ step.name }}</span>
                                        </p>
                                    </div>
                                </li>
                            </ol>
                        </section>
                    </template>
                    <footer class="modal-actions">
                        <button type="button" class="au-btn au-btn-ghost" @click="closeRouteModal">Đóng</button>
                    </footer>
                </div>
            </div>
        </Teleport>

        <!-- ========== Review Modal (cho từng item trong đơn COMPLETED) ========== -->
        <ReviewFormModal
            v-model:visible="reviewModalOpen"
            :menu-item="reviewMenuItem"
            :existing="reviewExisting ? {
                id: reviewExisting.id,
                rating: reviewExisting.rating,
                comment: reviewExisting.comment,
                photos: reviewExisting.photos,
                user: { id: 0, name: '' },
                menu_item_id: reviewMenuItem?.id ?? 0,
                is_hidden: false,
                created_at: null,
                updated_at: null,
            } : null"
            @submitted="onReviewSubmitted"
        />
    </main>

    <FooterHome />
</template>

<style scoped>
.back-link {
    background: transparent;
    border: 0;
    color: var(--hp-muted);
    cursor: pointer;
    font-size: 14px;
    margin-bottom: 14px;
    padding: 0;
}
.back-link:hover { color: #e63946; }

.state-box {
    background: #fff;
    border-radius: 14px;
    padding: 60px 20px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    color: var(--hp-muted);
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    gap: 12px;
    flex-wrap: wrap;
}

/* ===== Payment banner ===== */
.payment-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    background: #fff;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    border: 1px solid;
}
.payment-banner.paid    { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
.payment-banner.unpaid  { background: #fef3c7; border-color: #fde68a; color: #92400e; }
.payment-banner strong { display: inline-block; margin-right: 8px; }
.payment-banner code { background: rgba(0,0,0,.06); padding: 2px 6px; border-radius: 4px; font-size: 12px; }

/* ===== Cards ===== */
.detail-card {
    background: #fff;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}

.card-title {
    margin: 0 0 16px;
    font-size: 16px;
    color: var(--hp-dark);
}

/* ===== Timeline ===== */
.timeline {
    display: flex;
    gap: 0;
    overflow-x: auto;
    padding-bottom: 8px;
}
.timeline-step {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    min-width: 90px;
}
.step-dot {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--color-gray-200, #eee);
    color: var(--color-gray-600, #777);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    z-index: 2;
    position: relative;
}
.step-label {
    font-size: 12px;
    margin-top: 8px;
    color: var(--color-gray-600, #555);
    text-align: center;
}
.step-line {
    position: absolute;
    top: 18px;
    left: 50%;
    width: 100%;
    height: 2px;
    background: var(--color-gray-200, #eee);
    z-index: 1;
}
.timeline-step.done .step-dot { background: var(--color-chili-red, #d71920); color: white; }
.timeline-step.done .step-line { background: var(--color-chili-red, #d71920); }
.timeline-step.done .step-label { color: var(--color-chili-red, #d71920); font-weight: 600; }
.timeline-step.active .step-dot { background: white; border: 3px solid var(--color-chili-red, #d71920); color: var(--color-chili-red, #d71920); }
.timeline-step.active .step-label { color: var(--color-chili-red, #d71920); font-weight: 700; }

/* ===== Info grid ===== */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.info-item label {
    font-size: 12px;
    color: var(--hp-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.info-item span {
    font-size: 14px;
    color: var(--hp-dark);
    font-weight: 500;
}

/* ===== Items ===== */
.item-list { display: flex; flex-direction: column; gap: 12px; }
.item-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 14px;
    background: #f9fafb;
    border-radius: 10px;
}
.item-main { display: flex; flex-direction: column; gap: 6px; flex: 1; }
.item-name { font-weight: 600; color: var(--hp-dark); font-size: 15px; }
.qty { color: var(--hp-muted); margin-left: 8px; }
.item-meta-row { display: flex; flex-wrap: wrap; gap: 6px; }
.spicy-chip { background: #fee2e2; color: #991b1b; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
.topping-chip { background: #dbeafe; color: #1e40af; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
.item-note { font-size: 13px; color: var(--hp-muted); background: #fffbeb; padding: 4px 10px; border-radius: 6px; }
.item-note-cancel { background: #fee2e2; color: #991b1b; }
.item-status-row { display: flex; }
.item-price { text-align: right; min-width: 120px; }

/* ===== Pills ===== */
.pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-cyan { background: #cffafe; color: #155e75; }
.pill-green { background: #d1fae5; color: #065f46; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-orange { background: #ffedd5; color: #9a3412; }
.pill-gray { background: #f3f4f6; color: #374151; }

/* ===== Receipt confirmation CTA ===== */
.receipt-card {
    background: linear-gradient(135deg, #ecfeff 0%, #cffafe 100%);
    border: 1px solid #67e8f9;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 12px rgba(8, 145, 178, 0.12);
    flex-wrap: wrap;
}
.receipt-copy { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 240px; }
.receipt-copy strong { font-size: 16px; color: #0e7490; }
.receipt-copy span { font-size: 14px; color: #155e75; }
.receipt-copy small { font-size: 12px; color: #475569; margin-top: 2px; font-style: italic; }
.receipt-copy em { font-style: normal; font-weight: 700; color: #0e7490; }

/* ===== Points awarded banner (sau khi xác nhận) ===== */
.points-card {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
}
.points-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: #f59e0b; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 800;
}
.points-card strong { display: block; color: #92400e; font-size: 16px; }
.points-card span { color: #78350f; font-size: 13px; }

/* ===== Receipt modal ===== */
.receipt-summary {
    list-style: none; padding: 12px; margin: 0 0 16px;
    background: #f9fafb; border-radius: 10px; border: 1px solid #e5e7eb;
    display: flex; flex-direction: column; gap: 6px;
}
.receipt-summary li { display: flex; justify-content: space-between; font-size: 14px; }
.receipt-summary li:first-child { color: var(--hp-muted, #555); }
.receipt-summary li:last-child strong { color: #16a34a; font-size: 16px; }

/* ===== Receipt toast ===== */
.receipt-toast {
    position: fixed; top: 24px; right: 24px;
    background: var(--color-cream, #fff8ef);
    border: 1px solid #16a34a;
    border-left: 5px solid #16a34a;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 10px 25px rgba(22, 163, 74, 0.18);
    z-index: 1100;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    max-width: 380px;
}
.receipt-toast-icon {
    width: 32px; height: 32px;
    background: #16a34a; color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 16px;
    flex-shrink: 0;
}
.receipt-toast strong { display: block; color: #166534; font-size: 14px; margin-bottom: 4px; }
.receipt-toast span { color: #15803d; font-size: 13px; line-height: 1.5; }
.receipt-toast-close {
    background: transparent; border: 0; cursor: pointer;
    color: #166534; font-size: 18px; line-height: 1; padding: 0;
    margin-left: auto; flex-shrink: 0;
}
.toast-fade-enter-active, .toast-fade-leave-active {
    transition: all 250ms ease;
}
.toast-fade-enter-from, .toast-fade-leave-to {
    opacity: 0;
    transform: translateX(20px);
}

/* ===== Total ===== */
.total-block { display: flex; flex-direction: column; gap: 10px; }
.total-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed var(--color-gray-200, #eee); }
.total-final { font-size: 18px; font-weight: 700; border-bottom: none; }

/* ===== Events ===== */
.events { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.events li { display: flex; gap: 12px; padding: 10px; background: #f9fafb; border-radius: 8px; align-items: center; flex-wrap: wrap; }
.event-label { font-weight: 600; color: var(--hp-dark); }
.event-time { color: var(--hp-muted); font-size: 13px; }
.event-note { color: var(--hp-muted); font-size: 13px; font-style: italic; }

/* ===== Buttons ===== */
.au-btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; border: 1px solid var(--color-gray-200, #eee); background: white; }
.au-btn-primary { background: var(--color-chili-red, #d71920); color: white; border-color: var(--color-chili-red, #d71920); }
.au-btn-ghost { background: white; color: var(--hp-dark); }
.au-btn:disabled { opacity: .6; cursor: not-allowed; }

.detail-actions { display: flex; gap: 12px; justify-content: space-between; margin-top: 24px; }

/* ===== Modal ===== */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.modal {
    background: white; border-radius: 14px; padding: 24px;
    max-width: 520px; width: 92%; max-height: 90vh; overflow-y: auto;
}
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.modal-head h3 { margin: 0; }
.close-btn { background: transparent; border: 0; cursor: pointer; color: var(--hp-muted); font-size: 14px; padding: 4px 10px; }
.modal-sub { color: var(--hp-muted); font-size: 13px; margin-bottom: 16px; line-height: 1.5; }
.method-list { border: 0; padding: 0; margin: 0 0 16px; display: flex; flex-direction: column; gap: 8px; }
.method-card {
    display: flex; gap: 12px; align-items: center;
    padding: 12px 14px; border: 1px solid var(--color-gray-200, #eee);
    border-radius: 10px; cursor: pointer; transition: all .15s;
}
.method-card:hover { background: #fef9f9; }
.method-card.active { border-color: var(--color-chili-red, #d71920); background: #fef9f9; }
.method-card input { margin: 0; }
.method-meta { display: flex; flex-direction: column; gap: 2px; }
.method-meta strong { color: var(--hp-dark); }
.method-meta span { color: var(--hp-muted); font-size: 12px; }
.modal-error { color: #991b1b; background: #fee2e2; padding: 8px 12px; border-radius: 8px; font-size: 13px; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; }

.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }

/* ===== Tracking card (đơn ONLINE đang giao) ===== */
.tracking-card {
    background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
    border: 1px solid #fdba74;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(251, 146, 60, 0.15);
}
.tracking-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.tracking-head strong { color: #9a3412; font-size: 15px; }
.tracking-btn {
    background: #ea580c; color: white;
    border: 0; padding: 8px 14px;
    border-radius: 8px; font-weight: 600; cursor: pointer;
    font-size: 13px;
}
.tracking-btn:hover { background: #c2410c; }

.state-inline { padding: 24px; text-align: center; color: var(--hp-muted, #555); font-size: 14px; }
.modal.route-modal { max-width: 640px; max-height: 90vh; overflow-y: auto; }

/* ===== Route modal shared ===== */
.route-addresses { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: grid; gap: 8px; }
.route-row { display: flex; gap: 10px; align-items: flex-start; }
.route-row strong { display: block; font-size: 13px; color: var(--hp-dark, #222); }
.route-row p { margin: 2px 0; font-size: 13px; color: var(--hp-muted, #555); }
.route-pin-icon { font-size: 22px; line-height: 1; padding-top: 2px; }
.route-divider { border-left: 2px dashed #d71920; margin-left: 11px; min-height: 16px; }
.route-summary { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center; padding: 12px; background: #f9fafb; border-radius: 10px; margin-bottom: 14px; }
.metric { display: flex; flex-direction: column; gap: 2px; }
.metric span { font-size: 12px; color: var(--hp-muted, #555); }
.metric strong { font-size: 18px; color: #d71920; }
.au-btn.external { padding: 10px 14px; font-size: 13px; text-decoration: none; text-align: center; }

.route-steps { margin-top: 16px; }
.route-steps h4 { margin: 0 0 10px; font-size: 14px; color: #333; }
.route-steps ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.route-steps li { display: flex; gap: 10px; padding: 10px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb; }
.step-idx { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #d71920; color: white; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.step-instruction { margin: 0 0 4px; font-size: 13px; color: #222; font-weight: 500; }
.step-meta { margin: 0; font-size: 12px; color: var(--hp-muted, #777); }
.step-road { color: #333; font-weight: 500; }

@media (max-width: 640px) {
    .timeline-step { min-width: 70px; }
    .step-label { font-size: 11px; }
    .info-grid { grid-template-columns: 1fr; }
}

/* ===== Per-item review button ===== */
.item-review-row {
    margin-top: 8px;
    display: flex;
    gap: 8px;
}
.btn-review {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid var(--color-gray-200, #eee);
    background: var(--color-white, #fff);
    color: var(--color-charcoal-black, #111);
    transition: all 0.15s ease;
}
.btn-review:hover {
    border-color: var(--color-chili-red, #d71920);
    color: var(--color-chili-red, #d71920);
}
.btn-review-create {
    background: rgba(215, 25, 32, 0.06);
    border-color: rgba(215, 25, 32, 0.2);
    color: var(--color-chili-red, #d71920);
}
.btn-review-edit {
    background: rgba(255, 176, 0, 0.08);
    border-color: rgba(255, 176, 0, 0.3);
    color: #B45309;
}
.btn-review-disabled {
    opacity: 0.6;
    cursor: default;
    color: var(--hp-muted);
    border-color: transparent;
    background: transparent;
}
</style>
