<script setup lang="ts">
import { useStaffLiveRefresh } from '@/composables/useStaffLiveRefresh'
import { useNotificationOrderTarget } from '@/composables/useNotificationOrderTarget'
/**
 * ShipperHomeView — Trang chính cho nhân viên giao hàng.
 *
 * Luồng giao:
 *   1. Đã lấy món — bấm "Đã lấy món" khi nhận hàng từ bếp
 *   2. Đã tới điểm giao — (chỉ ONLINE) bấm khi đến nơi giao
 *   3. Đã giao xong (chờ khách xác nhận) — bấm khi hoàn tất giao/COD thu tiền
 *      → status = DELIVERED (chờ khách bấm "Tôi đã nhận hàng" để tích điểm)
 *
 * Chỉ đường:
 *   - Khi đơn ONLINE được bàn giao → modal hiển thị địa chỉ + bản đồ + chỉ dẫn tiếng Việt
 *   - Shipper xem trước đường đi (ô tô) từ quán đến khách, dùng OSRM
 *
 * Thanh toán:
 *   - COD: hóa đơn UNPAID → shipper thu tiền khi giao → tự động ghi nhận PAID ở bước 3
 *   - PREPAID: đơn đã thanh toán trước, shipper chỉ xác nhận đã giao
 *
 * Không dùng icon — chỉ text + badge màu.
 */

import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useDeliveriesStore } from '@/stores/onlineOrders'
import { useStaffStore } from '@/stores/staff'
import { formatVND, formatTime, formatDateTime, useToast } from '@/composables/useStaffUtils'
import { onlineOrders as deliveriesApi, deliveries as deliveriesDeliveryApi, type DeliveryRoute, type DeliveryOrder } from '@/api/staff'
// Sử dụng `deliveriesDeliveryApi.route()` vì endpoint nằm trong nhóm deliveries.
// `onlineOrders` là nhóm cho manager/cashier; `deliveries` là nhóm cho shipper.
import ShipperRouteMap from '@/components/ShipperRouteMap.vue'

const deliveriesStore = useDeliveriesStore()
const staffStore = useStaffStore()
const { session } = storeToRefs(staffStore)

const toast = useToast()
const tab = ref<'assigned' | 'active' | 'awaiting' | 'recent'>('active')
const issueOrder = ref<{ id: number; code: string } | null>(null)
const issueReason = ref('')

/** Modal chỉ đường — mở sau khi bấm "Đã lấy món từ bếp". */
const routeOrder = ref<DeliveryOrder | null>(null)
const routeData = ref<DeliveryRoute | null>(null)
const routeLoading = ref(false)
const routeError = ref('')

const { list, loading, busyId, error } = storeToRefs(deliveriesStore)

const active = computed(() => list.value.filter(o => o.status === 'DELIVERING'))
const awaiting = computed(() => list.value.filter(o => o.status === 'DELIVERED'))
const recent = computed(() => list.value.filter(o => o.status === 'COMPLETED'))

const assigned = computed(() => list.value.filter(order => ['CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER'].includes(order.status)))
const route = useNotificationOrderTarget(() => list.value, order => {
    if (['CONFIRMED', 'PREPARING', 'READY', 'READY_NOTIFY_SHIPPER'].includes(order.status)) tab.value = 'assigned'
    else if (order.status === 'DELIVERING') tab.value = 'active'
    else if (order.status === 'DELIVERED') tab.value = 'awaiting'
    else tab.value = 'recent'
})

async function refresh() {
    if (busyId.value !== null) return
    await deliveriesStore.fetch()
}

async function handlePickup(id: number) {
    const ok = await deliveriesStore.pickup(id)
    if (ok) {
        toast.success('Đã xác nhận lấy món từ bếp.')
        // Sau khi lấy món → tự động mở modal chỉ đường (chỉ cho đơn ONLINE có toạ độ).
        const order = list.value.find(o => o.id === id)
        if (order && order.order_type === 'ONLINE' && order.delivery_latitude != null && order.delivery_longitude != null) {
            openRouteModal(order)
        }
    } else if (error.value) {
        toast.error(error.value)
    }
}

/**
 * Mở modal chỉ đường + load route từ backend (OSRM).
 * - Backend route: /api/staff/deliveries/{id}/route (đã cache 24h).
 * - Polyline sẽ được giải mã trong ShipperRouteMap để vẽ lên Leaflet.
 */
async function openRouteModal(order: DeliveryOrder) {
    routeOrder.value = order
    routeData.value = null
    routeError.value = ''
    routeLoading.value = true
    try {
        console.info('[route] requesting route for order', order.id)
        routeData.value = await deliveriesDeliveryApi.route(order.id)
        console.info('[route] success:', routeData.value)
    } catch (e) {
        const err = e as { response?: { status?: number; data?: { message?: string } }; message?: string }
        console.error('[route API] failed:', err.response?.status, err.response?.data, err.message)
        // Fallback: vẽ modal với đường thẳng nối — dùng dữ liệu đơn đã có.
        if (order.delivery_latitude != null && order.delivery_longitude != null) {
            routeData.value = {
                restaurant: {
                    name: 'Mì Cay Đại học Cần Thơ',
                    address: 'Đại học Cần Thơ, Khu II, đường 3/2, phường Ninh Kiều, TP. Cần Thơ',
                    latitude: 10.0308, longitude: 105.7689,
                },
                customer: {
                    name: order.recipient_name ?? null,
                    phone: order.recipient_phone ?? null,
                    address: order.delivery_address ?? null,
                    latitude: Number(order.delivery_latitude),
                    longitude: Number(order.delivery_longitude),
                    note: order.note ?? null,
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
    routeOrder.value = null
    routeData.value = null
    routeError.value = ''
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

async function handleArrived(id: number) {
    const ok = await deliveriesStore.arrived(id)
    if (ok) toast.success('Đã xác nhận đã tới điểm giao.')
    else if (error.value) toast.error(error.value)
}

async function handleComplete(id: number, paymentMethod: string) {
    const ok = await deliveriesStore.complete(id)
    if (ok) {
        if (paymentMethod === 'COD') {
            toast.success('Đã thu COD và xác nhận giao xong. Đang chờ khách xác nhận đã nhận.')
        } else {
            toast.success('Đã giao xong. Đang chờ khách xác nhận đã nhận.')
        }
    } else if (error.value) toast.error(error.value)
}

function openIssue(id: number, code: string) {
    issueOrder.value = { id, code }
    issueReason.value = ''
}

async function submitIssue() {
    if (!issueOrder.value) return
    if (!issueReason.value.trim()) {
        toast.warning('Vui lòng nhập lý do sự cố để manager theo dõi.')
        return
    }
    const ok = await deliveriesStore.reportIssue(issueOrder.value.id, issueReason.value.trim())
    if (ok) {
        toast.success('Đã báo cáo sự cố giao hàng.')
        issueOrder.value = null
    } else if (error.value) {
        toast.error(error.value)
    }
}

function pillFor(status: string): { label: string; cls: string } {
    switch (status) {
        case 'CONFIRMED': return { label: 'Chờ chế biến', cls: 'pill pill-amber' }
        case 'PREPARING': return { label: 'Đang chế biến', cls: 'pill pill-orange' }
        case 'READY': return { label: 'Món đã xong', cls: 'pill pill-cyan' }
        case 'READY_NOTIFY_SHIPPER': return { label: 'Vui lòng đến lấy hàng', cls: 'pill pill-cyan' }
        case 'DELIVERING': return { label: 'Đang giao', cls: 'pill pill-blue' }
        case 'DELIVERED':  return { label: 'Chờ khách xác nhận', cls: 'pill pill-cyan' }
        case 'COMPLETED':  return { label: 'Đã hoàn tất', cls: 'pill pill-green' }
        case 'CANCELLED':  return { label: 'Đã hủy', cls: 'pill pill-red' }
        default:           return { label: status, cls: 'pill pill-gray' }
    }
}

function typeLabel(t: string | undefined): string {
    return t === 'TAKEAWAY' ? 'MANG ĐI' : 'GIAO TẬN NƠI'
}
function typeBadgeCls(t: string | undefined): string {
    return t === 'TAKEAWAY' ? 'pill pill-amber' : 'pill pill-blue'
}
function paymentMethodLabel(m: string | undefined): string {
    return m === 'COD' ? 'COD - Thu tiền khi giao' : 'Đã thanh toán trước'
}

onMounted(() => {
    void refresh()

})
useStaffLiveRefresh(() => deliveriesStore.fetch(true), () => !loading.value && busyId.value === null)
</script>

<template>
    <section class="shipper-home">
        <header class="page-head">
            <div>
                <span class="eyebrow">NHÂN VIÊN GIAO HÀNG</span>
                <h1>Xin chào, {{ session?.name }}</h1>
                <p class="subtitle">Theo dõi đơn được bàn giao và xác nhận từng bước giao hàng.</p>
            </div>
        </header>

        <div class="summary">
            <div class="card card-orange">
                <span>Đang giao</span>
                <strong>{{ active.length }}</strong>
            </div>
            <div class="card card-cyan">
                <span>Chờ khách xác nhận</span>
                <strong>{{ awaiting.length }}</strong>
            </div>
            <div class="card card-green">
                <span>Đã hoàn tất (gần đây)</span>
                <strong>{{ recent.length }}</strong>
            </div>
            <div class="card card-blue">
                <span>Thời gian</span>
                <strong>{{ formatTime(new Date().toISOString()) }}</strong>
            </div>
        </div>

        <nav class="tabs" aria-label="Lọc trạng thái đơn">
            <button :class="{ active: tab === 'assigned' }" @click="tab = 'assigned'">
                Được phân công ({{ assigned.length }})
            </button>
            <button :class="{ active: tab === 'active' }" @click="tab = 'active'">
                Đang giao ({{ active.length }})
            </button>
            <button :class="{ active: tab === 'awaiting' }" @click="tab = 'awaiting'">
                Chờ khách xác nhận ({{ awaiting.length }})
            </button>
            <button :class="{ active: tab === 'recent' }" @click="tab = 'recent'">
                Đã hoàn tất ({{ recent.length }})
            </button>
        </nav>

        <p v-if="error" class="alert error" role="alert">{{ error }}</p>
        <p v-if="loading && !list.length" class="empty">Đang tải đơn giao hàng…</p>

        <div v-else-if="tab === 'assigned' && !assigned.length" class="empty"><p>Chưa có đơn đang chờ chế biến hoặc lấy hàng.</p></div>
        <div v-else-if="tab === 'active' && !active.length" class="empty">
            <p>Hiện chưa có đơn nào được bàn giao cho bạn. Hãy chờ quản lý / thu ngân phân công.</p>
        </div>

        <div v-else-if="tab === 'awaiting' && !awaiting.length" class="empty">
            <p>Chưa có đơn nào đang chờ khách xác nhận đã nhận hàng.</p>
        </div>

        <div v-else-if="tab === 'recent' && !recent.length" class="empty">
            <p>Chưa có đơn giao thành công nào trong danh sách gần đây.</p>
        </div>

        <div v-else class="orders">
            <article
                v-for="o in (tab === 'assigned' ? assigned : tab === 'active' ? active : tab === 'awaiting' ? awaiting : recent)"
                :key="o.id"
                class="order-card" :id="`staff-order-${o.id}`" tabindex="-1"
                :class="{ linked: Number(route.query.order_id) === o.id, 'order-done': o.status === 'COMPLETED', 'order-awaiting': o.status === 'DELIVERED' }"
            >
                <header class="order-head">
                    <div>
                        <span class="order-code">{{ o.code }}</span>
                        <span :class="pillFor(o.status).cls">{{ pillFor(o.status).label }}</span>
                        <span :class="typeBadgeCls(o.order_type)">{{ typeLabel(o.order_type) }}</span>
                        <span class="pill pill-purple">{{ paymentMethodLabel(o.payment_method) }}</span>
                        <span v-if="o.delivery_issue" class="pill pill-amber">Sự cố</span>
                    </div>
                    <div class="amount">
                        {{ formatVND(o.total_amount) }}
                    </div>
                </header>

                <!-- Timeline 3 bước (chỉ hiển thị khi còn đang xử lý) -->
                <section v-if="o.status === 'DELIVERING'" class="ship-steps">
                    <div class="step" :class="{ done: !!o.picked_up_at, active: !o.picked_up_at }">
                        <div class="step-dot">{{ o.picked_up_at ? '✓' : '1' }}</div>
                        <div class="step-label">
                            <strong>Đã lấy món</strong>
                            <span v-if="o.picked_up_at">Lúc {{ formatTime(o.picked_up_at) }}</span>
                        </div>
                    </div>
                    <div class="step-line" :class="{ done: !!o.arrived_at }"></div>
                    <div class="step" :class="{ done: !!o.arrived_at, disabled: !o.picked_up_at && o.order_type === 'ONLINE' }">
                        <div class="step-dot">{{ o.arrived_at ? '✓' : '2' }}</div>
                        <div class="step-label">
                            <strong>Đã tới điểm giao</strong>
                            <span v-if="o.arrived_at">Lúc {{ formatTime(o.arrived_at) }}</span>
                            <span v-else-if="o.order_type === 'TAKEAWAY'">Chỉ với đơn giao tận nơi</span>
                        </div>
                    </div>
                    <div class="step-line" :class="{ done: !!o.delivered_at }"></div>
                    <div class="step" :class="{ done: !!o.delivered_at }">
                        <div class="step-dot">{{ o.delivered_at ? '✓' : '3' }}</div>
                        <div class="step-label">
                            <strong>Đã giao xong</strong>
                            <span v-if="o.delivered_at">Lúc {{ formatTime(o.delivered_at) }}</span>
                        </div>
                    </div>
                </section>

                <!-- Banner trạng thái chờ khách xác nhận -->
                <section v-else-if="o.status === 'DELIVERED'" class="awaiting-banner" role="status">
                    <strong>Đã giao xong · chờ khách xác nhận</strong>
                    <span>
                        Đơn sẽ hoàn tất khi khách bấm <em>"Tôi đã nhận hàng"</em>.
                        Nếu khách không xác nhận, đơn vẫn ở trạng thái này và không được tích điểm.
                    </span>
                </section>

                <section class="recipient">
                    <h3>Thông tin người nhận</h3>
                    <p>
                        <strong>{{ o.recipient_name || '—' }}</strong>
                        <a v-if="o.recipient_phone" :href="'tel:' + o.recipient_phone" class="phone">
                            {{ o.recipient_phone }}
                        </a>
                    </p>
                    <p v-if="o.order_type === 'ONLINE'" class="address">
                        {{ o.delivery_address || '—' }}
                    </p>
                    <p v-else class="address muted">
                        Khách sẽ đến quán nhận — không cần giao tận nơi.
                    </p>
                    <p v-if="o.note" class="note">Ghi chú khách: {{ o.note }}</p>
                    <div class="recipient-actions" v-if="o.order_type === 'ONLINE' && o.delivery_latitude != null && o.delivery_longitude != null">
                        <button type="button" class="btn-ghost-sm" @click="openRouteModal(o)">
                            🗺️ Xem đường đi
                        </button>
                        <a class="btn-ghost-sm"
                            :href="googleMapsUrl(Number(o.delivery_latitude), Number(o.delivery_longitude))"
                            target="_blank" rel="noopener noreferrer">
                            🚗 Mở Google Maps
                        </a>
                    </div>
                </section>

                <section class="items">
                    <h3>Món trong đơn ({{ o.items.length }})</h3>
                    <ul>
                        <li v-for="it in o.items.filter(i => i.status !== 'CANCELLED')" :key="it.id">
                            <span>
                                {{ it.menu_item_name }}
                                <span v-if="it.spicy_level" class="spicy">Cấp {{ it.spicy_level }}</span>
                            </span>
                            <span>x {{ it.quantity }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="o.payment" class="payment">
                    <span>
                        Thanh toán:
                        <strong>{{ o.payment.status === 'PAID' ? 'Đã thanh toán' : 'Chưa thanh toán' }}</strong>
                    </span>
                    <span>{{ formatVND(o.payment.total) }}</span>
                </section>

                <section v-if="o.delivery_issue" class="issue">
                    Sự cố: {{ o.delivery_issue }}
                </section>

                <footer class="meta">
                    <span>Bàn giao: {{ formatDateTime(o.handed_over_at) }}</span>
                    <span v-if="o.delivered_at">Giao lúc: {{ formatDateTime(o.delivered_at) }}</span>
                </footer>

                <!-- Actions: 3 bước -->
                <footer v-if="o.status === 'DELIVERING'" class="actions">
                    <!-- Chưa lấy món -->
                    <template v-if="!o.picked_up_at">
                        <button class="btn-ghost" :disabled="busyId === o.id" @click="openIssue(o.id, o.code)">
                            Báo cáo sự cố
                        </button>
                        <button class="btn-primary" :disabled="busyId === o.id" @click="handlePickup(o.id)">
                            Xác nhận đã lấy món 
                        </button>
                    </template>

                    <!-- Đã lấy, chưa tới (ONLINE: cần bấm arrived; TAKEAWAY: skip sang complete -->
                    <template v-else-if="o.picked_up_at && !o.arrived_at && o.order_type === 'ONLINE'">
                        <button class="btn-ghost" :disabled="busyId === o.id" @click="openIssue(o.id, o.code)">
                            Báo cáo sự cố
                        </button>
                        <button class="btn-primary" :disabled="busyId === o.id" @click="handleArrived(o.id)">
                            Đã tới điểm giao
                        </button>
                    </template>

                    <!-- Đã tới, chưa giao -->
                    <template v-else-if="o.picked_up_at && (o.arrived_at || o.order_type === 'TAKEAWAY') && !o.delivered_at">
                        <button class="btn-ghost" :disabled="busyId === o.id" @click="openIssue(o.id, o.code)">
                            Báo cáo sự cố
                        </button>
                        <button class="btn-primary" :disabled="busyId === o.id" @click="handleComplete(o.id, o.payment_method)">
                            {{ o.payment_method === 'COD' ? 'Thu COD và đã giao xong' : 'Đã giao xong (chờ khách xác nhận)' }}
                        </button>
                    </template>

                    <!-- Tất cả bước xong -->
                    <template v-else>
                        <span class="done-label">Đã giao xong · chờ khách xác nhận</span>
                    </template>
                </footer>

                <!-- Actions: đơn đang chờ khách xác nhận (DELIVERED) -->
                <footer v-else-if="o.status === 'DELIVERED'" class="actions">
                    <span class="done-label">
                        Chờ khách bấm "Tôi đã nhận hàng" để hoàn tất
                    </span>
                </footer>
            </article>
        </div>

        <Teleport to="body">
            <div v-if="issueOrder" class="modal-layer" @click.self="issueOrder = null">
                <form class="modal" @submit.prevent="submitIssue">
                    <h3>Báo cáo sự cố - {{ issueOrder.code }}</h3>
                    <p class="modal-sub">Mô tả ngắn gọn để quản lý theo dõi và hỗ trợ (tối đa 500 ký tự).</p>
                    <textarea
                        v-model="issueReason"
                        rows="4"
                        maxlength="500"
                        placeholder="VD: Khách không nghe máy, sai địa chỉ, đóng cửa không nhận..."
                        required
                    />
                    <p class="counter">{{ issueReason.length }}/500</p>
                    <footer class="modal-actions">
                        <button type="button" class="btn-ghost" @click="issueOrder = null">Hủy</button>
                        <button type="submit" class="btn-primary" :disabled="busyId !== null">Gửi báo cáo</button>
                    </footer>
                </form>
            </div>

            <!-- Modal chỉ đường cho shipper -->
            <div v-if="routeOrder" class="modal-layer" @click.self="closeRouteModal">
                <div class="modal route-modal" role="dialog" aria-modal="true" :aria-label="'Chỉ đường đến khách - ' + routeOrder.code">
                    <header class="modal-head">
                        <div>
                            <h3>🗺️ Chỉ đường đến khách · {{ routeOrder.code }}</h3>
                            <p class="modal-sub">{{ routeOrder.recipient_name }} · <a v-if="routeOrder.recipient_phone" :href="'tel:' + routeOrder.recipient_phone">{{ routeOrder.recipient_phone }}</a></p>
                        </div>
                        <button type="button" class="close-btn" aria-label="Đóng" @click="closeRouteModal">✕</button>
                    </header>

                    <section class="route-addresses">
                        <div class="route-row">
                            <span class="route-pin-icon">🍜</span>
                            <div>
                                <strong>Xuất phát</strong>
                                <p v-if="routeData">{{ routeData.restaurant.name }} · {{ routeData.restaurant.address }}</p>
                                <p v-else class="muted">Đang tải…</p>
                            </div>
                        </div>
                        <div class="route-divider" aria-hidden="true"></div>
                        <div class="route-row">
                            <span class="route-pin-icon">📍</span>
                            <div>
                                <strong>Điểm giao</strong>
                                <p>{{ routeOrder.delivery_address || '—' }}</p>
                                <p v-if="routeData" class="muted small">{{ routeData.customer.latitude.toFixed(5) }}, {{ routeData.customer.longitude.toFixed(5) }}</p>
                            </div>
                        </div>
                    </section>

                    <div v-if="routeLoading" class="loading">Đang tính đường đi bằng ô tô…</div>
                    <p v-else-if="routeError" class="alert error" role="alert">{{ routeError }}</p>

                    <template v-else-if="routeData">
                        <section class="route-summary">
                            <div class="metric">
                                <span>Khoảng cách đường bộ</span>
                                <strong>{{ routeData.route.distance_km.toFixed(2) }} km</strong>
                            </div>
                            <div class="metric">
                                <span>Thời gian ước tính</span>
                                <strong>{{ routeData.route.duration_min > 0 ? routeData.route.duration_min + ' phút' : '—' }}</strong>
                            </div>
                            <a class="btn-primary external"
                                :href="googleMapsUrl(routeData.customer.latitude, routeData.customer.longitude)"
                                target="_blank" rel="noopener noreferrer">
                                Mở Google Maps để dẫn đường
                            </a>
                        </section>

                        <ShipperRouteMap :route-data="routeData" />

                        <section v-if="routeData.route.steps.length" class="route-steps">
                            <h4>Chỉ dẫn đường đi (ô tô)</h4>
                            <ol>
                                <li v-for="(step, idx) in routeData.route.steps" :key="idx">
                                    <span class="step-idx">{{ idx + 1 }}</span>
                                    <div class="step-body">
                                        <p class="step-instruction">{{ step.instruction }}</p>
                                        <p class="step-meta">
                                            {{ distanceText(step.distance_m) }} · {{ durationText(step.duration_s) }}
                                            <span v-if="step.name" class="step-road">· {{ step.name }}</span>
                                        </p>
                                    </div>
                                </li>
                            </ol>
                        </section>
                        <p v-else class="muted small">Không có chỉ dẫn chi tiết. Bạn có thể mở Google Maps để dẫn đường.</p>
                    </template>

                    <footer class="modal-actions">
                        <button type="button" class="btn-ghost" @click="closeRouteModal">Đóng</button>
                    </footer>
                </div>
            </div>
        </Teleport>
    </section>
</template>

<style scoped>
.order-card.linked { outline: 3px solid var(--color-spicy-2, #ff7a00); outline-offset: 3px; }
.shipper-home { padding: 24px; max-width: 1200px; margin: 0 auto; }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.page-head h1 { margin: 4px 0; font-size: 28px; color: var(--color-charcoal-black, #222); }
.eyebrow { display: inline-block; font-size: 11px; letter-spacing: .12em; color: var(--color-chili-red, #d71920); font-weight: 700; }
.subtitle { margin: 0; color: var(--color-gray-600, #555); font-size: 14px; }

.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.card { background: white; border-radius: 12px; padding: 18px; border: 1px solid var(--color-gray-200, #eee); display: flex; flex-direction: column; gap: 4px; }
.card span { font-size: 13px; color: var(--color-gray-600, #555); }
.card strong { font-size: 26px; color: var(--color-charcoal-black, #222); }
.card-orange { border-top: 4px solid #f59e0b; }
.card-cyan { border-top: 4px solid #06b6d4; }
.card-green { border-top: 4px solid #10b981; }
.card-blue { border-top: 4px solid #3b82f6; }

.tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid var(--color-gray-200, #eee); }
.tabs button { padding: 12px 20px; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--color-gray-600, #555); border-bottom: 3px solid transparent; margin-bottom: -2px; }
.tabs button.active { color: var(--color-chili-red, #d71920); border-bottom-color: var(--color-chili-red, #d71920); }

.alert { padding: 12px 16px; border-radius: 8px; }
.alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.empty { text-align: center; padding: 48px 24px; color: var(--color-gray-500, #777); }

.orders { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 16px; }
.order-card { background: white; border-radius: 12px; padding: 18px; border: 1px solid var(--color-gray-200, #eee); display: flex; flex-direction: column; gap: 14px; }
.order-card.order-done { opacity: .85; background: #f9fafb; }
.order-card.order-awaiting { border-color: #67e8f9; background: linear-gradient(180deg, #ecfeff 0%, #ffffff 60%); }
.order-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.order-head > div { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.order-code { font-weight: 700; font-size: 15px; }
.amount { font-size: 18px; font-weight: 700; color: var(--color-chili-red, #d71920); }

/* 3-step timeline */
.ship-steps {
    display: flex; align-items: center; gap: 0; padding: 12px; background: #f9fafb;
    border-radius: 8px; border: 1px solid var(--color-gray-200, #eee); overflow-x: auto;
}
.step {
    display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 80px;
}
.step.disabled { opacity: .4; }
.step-dot {
    width: 32px; height: 32px; border-radius: 50%; background: var(--color-gray-200, #eee);
    color: var(--color-gray-600, #777); display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px;
}
.step.done .step-dot { background: var(--color-chili-red, #d71920); color: white; }
.step.active .step-dot { background: white; border: 2px solid var(--color-chili-red, #d71920); color: var(--color-chili-red, #d71920); }
.step-label { text-align: center; font-size: 11px; color: var(--color-gray-600, #777); }
.step-label strong { display: block; color: var(--color-charcoal-black, #222); font-size: 12px; margin-bottom: 2px; }
.step-line {
    flex: 1; min-width: 24px; height: 2px; background: var(--color-gray-200, #eee); margin: 0 -2px; align-self: flex-start; margin-top: 15px;
}
.step-line.done { background: var(--color-chili-red, #d71920); }

.pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-green { background: #d1fae5; color: #065f46; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-purple { background: #f3e8ff; color: #6b21a8; }
.pill-gray { background: var(--color-gray-200, #eee); color: var(--color-gray-600, #555); }

.recipient h3, .items h3 { margin: 0 0 8px; font-size: 13px; color: var(--color-gray-600, #555); text-transform: uppercase; letter-spacing: .08em; }
.recipient p { margin: 4px 0; font-size: 14px; }
.recipient .phone { color: var(--color-chili-red, #d71920); text-decoration: none; margin-left: 8px; font-weight: 600; }
.recipient .address { color: var(--color-gray-700, #333); }
.recipient .address.muted { color: var(--color-gray-500, #888); font-style: italic; }
.recipient .note { background: #fffbeb; padding: 6px 10px; border-radius: 6px; font-size: 13px; color: #78350f; }

.items ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.items li { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; border-bottom: 1px dashed var(--color-gray-200, #eee); }
.items li:last-child { border-bottom: none; }
.spicy { background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 4px; font-size: 11px; margin-left: 4px; }

.payment { display: flex; justify-content: space-between; background: #f3f4f6; padding: 10px 12px; border-radius: 8px; font-size: 14px; }

.issue { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; border: 1px solid #fecaca; font-size: 13px; }

.awaiting-banner {
    background: #ecfeff;
    border: 1px solid #a5f3fc;
    color: #155e75;
    padding: 12px 14px;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    line-height: 1.5;
}
.awaiting-banner strong { color: #0e7490; font-size: 14px; }
.awaiting-banner em { font-style: normal; font-weight: 700; }

.meta { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--color-gray-500, #777); }

.actions { display: flex; gap: 8px; }
.done-label { font-size: 13px; color: #065f46; background: #d1fae5; padding: 10px 14px; border-radius: 8px; font-weight: 600; }
.btn-ghost, .btn-primary { flex: 1; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer; border: 1px solid var(--color-gray-200, #eee); background: white; }
.btn-primary { background: var(--color-chili-red, #d71920); color: white; border-color: var(--color-chili-red, #d71920); }
.btn-primary:disabled, .btn-ghost:disabled { opacity: .6; cursor: not-allowed; }

.modal-layer { position: fixed; inset: 0; background: rgba(0, 0, 0, .45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: white; border-radius: 12px; padding: 24px; max-width: 480px; width: 90%; }
.modal h3 { margin: 0 0 8px; }
.modal-sub { color: var(--color-gray-600, #555); font-size: 13px; margin: 0 0 12px; }
.modal textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--color-gray-200, #eee); font: inherit; resize: vertical; box-sizing: border-box; }
.counter { text-align: right; font-size: 12px; color: var(--color-gray-500, #888); margin: 4px 0 0; }
.modal-actions { display: flex; gap: 8px; margin-top: 16px; justify-content: flex-end; }

/* Route modal */
.modal.route-modal { max-width: 640px; max-height: 90vh; overflow-y: auto; padding: 20px; }
.modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.close-btn { background: none; border: 0; font-size: 20px; cursor: pointer; color: var(--color-gray-600, #555); padding: 4px 10px; }
.route-addresses { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: grid; gap: 8px; }
.route-row { display: flex; gap: 10px; align-items: flex-start; }
.route-row strong { display: block; font-size: 13px; color: var(--color-charcoal-black, #222); }
.route-row p { margin: 2px 0; font-size: 13px; color: var(--color-gray-700, #333); }
.route-pin-icon { font-size: 22px; line-height: 1; padding-top: 2px; }
.route-divider { border-left: 2px dashed #d71920; margin-left: 11px; min-height: 16px; }

.route-summary { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center; padding: 12px; background: #f9fafb; border-radius: 10px; margin-bottom: 14px; }
.metric { display: flex; flex-direction: column; gap: 2px; }
.metric span { font-size: 12px; color: var(--color-gray-600, #555); }
.metric strong { font-size: 18px; color: var(--color-chili-red, #d71920); }
.btn-primary.external { padding: 10px 14px; font-size: 13px; }

.route-steps { margin-top: 16px; }
.route-steps h4 { margin: 0 0 10px; font-size: 14px; color: var(--color-gray-700, #333); }
.route-steps ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; counter-reset: step; }
.route-steps li { display: flex; gap: 10px; padding: 10px; background: #f9fafb; border-radius: 8px; border: 1px solid var(--color-gray-200, #eee); }
.step-idx { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: var(--color-chili-red, #d71920); color: white; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.step-body { flex: 1; }
.step-instruction { margin: 0 0 4px; font-size: 13px; color: var(--color-charcoal-black, #222); font-weight: 500; }
.step-meta { margin: 0; font-size: 12px; color: var(--color-gray-500, #777); }
.step-road { color: var(--color-gray-700, #333); font-weight: 500; }

.recipient-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.btn-ghost-sm { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--color-gray-200, #eee); background: white; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; color: var(--color-charcoal-black, #222); }
.btn-ghost-sm:hover { background: #f9fafb; }
.loading { padding: 16px; text-align: center; color: var(--color-gray-600, #555); font-size: 13px; }
.muted { color: var(--color-gray-500, #888); }
.small { font-size: 11px; }

@media (max-width: 640px) {
    .shipper-home { padding: 16px; }
    .page-head h1 { font-size: 22px; }
    .orders { grid-template-columns: 1fr; }
    .ship-steps { gap: 0; }
    .step { min-width: 60px; }
}
</style>
