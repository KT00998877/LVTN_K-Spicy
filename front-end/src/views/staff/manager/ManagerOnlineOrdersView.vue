<script setup lang="ts">
import { useStaffLiveRefresh } from '@/composables/useStaffLiveRefresh'
import { useNotificationOrderTarget } from '@/composables/useNotificationOrderTarget'
/**
 * ManagerOnlineOrdersView — Trang quản lý đơn giao hàng cho quản lý / thu ngân.
 *
 * Đơn ONLINE/TAKEAWAY đi qua các bước:
 *   PENDING → (cashier xác nhận) → CONFIRMED → (bếp chế biến) →
 *   PREPARING/READY → (bếp bấm "Thông báo shipper") → READY_NOTIFY_SHIPPER →
 *   READY_NOTIFY_SHIPPER → (cashier bàn giao shipper) → DELIVERING →
 *   (shipper: pickup → arrived → complete) → DELIVERED → khách xác nhận → COMPLETED.
 *
 * Trang này cho phép:
 *  - Xác nhận đơn (PENDING) — COD: không cần PAID; PREPAID: cần PAID.
 *  - Từ chối đơn (chỉ khi PENDING).
 *  - Bàn giao shipper (chỉ khi READY_NOTIFY_SHIPPER).
 *
 * Không dùng icon — chỉ text + badge màu.
 */

import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useOnlineOrdersStore } from '@/stores/onlineOrders'
import { useStaffStore } from '@/stores/staff'
import { onlineOrders } from '@/api/staff'
import type { DeliveryOrder, NearestShipper } from '@/api/staff'
import { formatVND, formatDateTime, formatTime, useToast } from '@/composables/useStaffUtils'
import { useAppDialog } from '@/composables/useAppDialog'

const store = useOnlineOrdersStore()
const staffStore = useStaffStore()
const toast = useToast()
const appDialog = useAppDialog()

const { orders, shippers, loading, busyId, error } = storeToRefs(store)
const isManager = computed(() => staffStore.hasRole('MANAGER'))

const tab = ref<'pending' | 'preparing' | 'ready' | 'delivering' | 'delivered' | 'done'>('pending')
const typeFilter = ref<'ALL' | 'ONLINE' | 'TAKEAWAY'>('ALL')
const route = useNotificationOrderTarget(() => orders.value, order => {
    if (order.status === 'PENDING') tab.value = 'pending'
    else if (['CONFIRMED', 'PREPARING', 'READY'].includes(order.status)) tab.value = 'preparing'
    else if (order.status === 'READY_NOTIFY_SHIPPER') tab.value = 'ready'
    else if (order.status === 'DELIVERING') tab.value = 'delivering'
    else if (order.status === 'DELIVERED') tab.value = 'delivered'
    else tab.value = 'done'
    typeFilter.value = 'ALL'
})


const confirmTarget = ref<DeliveryOrder | null>(null)
/** null = để backend tự gán shipper gần nhất (Phase 2). */
const confirmShipperId = ref<number | null>(null)
/** Shipper đang rảnh + khoảng cách từ nhà hàng (Phase 2). */
const nearestShippers = ref<NearestShipper[]>([])

/** Khoảng cách của shipper từ nhà hàng (null nếu chưa có dữ liệu vị trí). */
function nearestDistance(shipperId: number): number | null {
    return nearestShippers.value.find(s => s.id === shipperId)?.distance_km ?? null
}

const rejectTarget = ref<DeliveryOrder | null>(null)
const rejectReason = ref('')

const handoverTarget = ref<DeliveryOrder | null>(null)
const handoverShipperId = ref<number | null>(null)

const filteredOrders = computed<DeliveryOrder[]>(() => {
    if (typeFilter.value === 'ALL') return orders.value
    return orders.value.filter(o => o.order_type === typeFilter.value)
})

const visibleOrders = computed<DeliveryOrder[]>(() => {
    const list = filteredOrders.value
    switch (tab.value) {
        case 'pending':    return list.filter(o => o.status === 'PENDING')
        case 'preparing':  return list.filter(o => ['CONFIRMED', 'PREPARING', 'READY'].includes(o.status))
        case 'ready':      return list.filter(o => o.status === 'READY_NOTIFY_SHIPPER')
        case 'delivering': return list.filter(o => o.status === 'DELIVERING')
        case 'delivered':  return list.filter(o => o.status === 'DELIVERED')
        case 'done':       return list.filter(o => ['COMPLETED', 'CANCELLED'].includes(o.status))
    }
})

const counts = computed(() => {
    const list = filteredOrders.value
    return {
        pending:    list.filter(o => o.status === 'PENDING').length,
        preparing:  list.filter(o => ['CONFIRMED', 'PREPARING', 'READY'].includes(o.status)).length,
        ready:     list.filter(o => o.status === 'READY_NOTIFY_SHIPPER').length,
        delivering: list.filter(o => o.status === 'DELIVERING').length,
        delivered: list.filter(o => o.status === 'DELIVERED').length,
        done:      list.filter(o => ['COMPLETED', 'CANCELLED'].includes(o.status)).length,
    }
})



async function handleConfirm(o: DeliveryOrder) {
    if (o.cancellation_request?.status === 'PENDING') {
        toast.warning('Khách đang yêu cầu hủy đơn này. Hãy xử lý yêu cầu hủy trước.')
        return
    }
    if (o.payment_method === 'PREPAID' && (!o.payment || o.payment.status !== 'PAID')) {
        toast.warning('Đơn chọn thanh toán trước (PREPAID) — cần thanh toán trước khi xác nhận.')
        return
    }
    if (o.order_type === 'ONLINE') {
        confirmTarget.value = o
        confirmShipperId.value = null
        if (isManager.value) await loadNearestShippers()
        else nearestShippers.value = []
        return
    }
    const ok = await store.confirm(o.id)
    if (ok) toast.success(`Đã xác nhận đơn ${ok.code}.`)
    else if (error.value) toast.error(error.value)
}

async function approveCancellation(o: DeliveryOrder) {
    const confirmed = await appDialog.confirm({
        title: `Duyệt hủy đơn ${o.code}?`,
        message: 'Đơn sẽ chuyển sang Đã hủy. Sau đó bạn cần xác nhận hoàn tiền VNPay Sandbox ở bước tiếp theo.',
        confirmLabel: 'Duyệt hủy đơn',
        cancelLabel: 'Giữ đơn',
        tone: 'danger',
    })
    if (!confirmed) return

    const updated = await store.approveCancellation(o.id)
    if (updated) toast.success(`Đã duyệt hủy đơn ${updated.code}. Vui lòng xác nhận hoàn tiền.`)
    else if (error.value) toast.error(error.value)
}

async function confirmRefund(o: DeliveryOrder) {
    const confirmed = await appDialog.confirm({
        title: `Xác nhận hoàn tiền ${o.code}?`,
        message: `Xác nhận đã hoàn ${formatVND(o.payment?.paid || o.total_amount)} trên VNPay Sandbox. Khách hàng sẽ nhận được thông báo ngay sau bước này.`,
        confirmLabel: 'Xác nhận hoàn tiền',
        cancelLabel: 'Để sau',
        tone: 'default',
    })
    if (!confirmed) return

    const updated = await store.confirmRefund(o.id)
    if (updated) toast.success(`Đã hoàn tiền và gửi thông báo cho khách của đơn ${updated.code}.`)
    else if (error.value) toast.error(error.value)
}

/** Tải shipper đang rảnh + khoảng cách từ nhà hàng (Phase 2). */
async function loadNearestShippers() {
    try {
        nearestShippers.value = await onlineOrders.nearestShippers()
    } catch {
        nearestShippers.value = []
    }
}

async function submitConfirm() {
    if (!confirmTarget.value || busyId.value !== null) return
    // confirmShipperId === null → backend tự gán shipper gần nhất.
    const shipperId = confirmShipperId.value ?? undefined
    const ok = await store.confirm(confirmTarget.value.id, shipperId)
    if (ok) {
        toast.success(
            shipperId
                ? `Đã duyệt và phân công shipper cho đơn ${ok.code}.`
                : `Đã duyệt đơn ${ok.code} — hệ thống tự gán shipper gần nhất.`,
        )
        confirmTarget.value = null
    } else if (error.value) toast.error(error.value)
}

function openReject(o: DeliveryOrder) {
    rejectTarget.value = o
    rejectReason.value = ''
}

async function submitReject() {
    if (!rejectTarget.value) return
    if (!rejectReason.value.trim()) {
        toast.warning('Vui lòng nhập lý do từ chối.')
        return
    }
    const ok = await store.reject(rejectTarget.value.id, rejectReason.value.trim())
    if (ok) {
        toast.success('�ã từ chối đơn.')
        rejectTarget.value = null
    } else if (error.value) {
        toast.error(error.value)
    }
}

function openHandover(o: DeliveryOrder) {
    handoverTarget.value = o
    handoverShipperId.value = o.order_type === 'ONLINE' && o.shipper_id ? o.shipper_id : null
}

async function submitHandover() {
    if (!handoverTarget.value || !handoverShipperId.value) return
    const ok = await store.handover(handoverTarget.value.id, handoverShipperId.value)
    if (ok) {
        toast.success('Đã bàn giao đơn cho shipper.')
        handoverTarget.value = null
    } else if (error.value) {
        toast.error(error.value)
    }
}

function pillFor(o: DeliveryOrder): { label: string; cls: string } {
    switch (o.status) {
        case 'PENDING':    return { label: 'Chờ xác nhận', cls: 'pill pill-amber' }
        case 'CONFIRMED':  return { label: 'Đã xác nhận', cls: 'pill pill-blue' }
        case 'PREPARING':  return { label: 'Đang chế biến', cls: 'pill pill-orange' }
        case 'READY_NOTIFY_SHIPPER': return { label: 'Sẵn sàng giao', cls: 'pill pill-cyan' }
        case 'DELIVERING': return { label: 'Đang giao', cls: 'pill pill-blue' }
        case 'DELIVERED':  return { label: 'Chờ khách xác nhận', cls: 'pill pill-cyan' }
        case 'COMPLETED':  return { label: 'Hoàn thành', cls: 'pill pill-green' }
        case 'CANCELLED':  return { label: 'Đã hủy', cls: 'pill pill-red' }
        default:           return { label: o.status, cls: 'pill pill-gray' }
    }
}

function paymentLabel(o: DeliveryOrder): string {
    if (!o.payment) return 'Không có hóa đơn'
    if (o.payment.status === 'REFUNDED') return 'Đã hoàn tiền'
    return o.payment.status === 'PAID' ? 'Đã thanh toán' : 'Chưa thanh toán'
}

function paymentMethodLabel(m: string): string {
    return m === 'COD' ? 'COD - Thu tiền khi giao' : 'Thanh toán trước'
}

function typeLabel(t: string): string {
    return t === 'TAKEAWAY' ? 'MANG ĐI' : 'GIAO TẬN NƠI'
}
function typeBadgeCls(t: string): string {
    return t === 'TAKEAWAY' ? 'pill pill-amber' : 'pill pill-blue'
}
function addressLine(o: DeliveryOrder): string {
    if (o.order_type === 'TAKEAWAY') return 'Khách tự đến quán nhận'
    return o.delivery_address || '—'
}

onMounted(async () => {
    await Promise.all([store.fetchOrders(), store.fetchShippers()])

})
useStaffLiveRefresh(() => Promise.all([store.fetchOrders(undefined, true), store.fetchShippers(true, true)]), () => !loading.value && busyId.value === null)
</script>

<template>
    <section class="online-orders">
        <header class="page-head">
            <div>
                <span class="eyebrow">ĐƠN GIAO HÀNG & MANG ĐI</span>
                <h1>Quản lý đơn giao hàng</h1>
                <p class="subtitle">
                    Xác nhận thanh toán → theo dõi bếp chế biến → bàn giao shipper → theo dõi giao hàng.
                </p>
            </div>
        </header>

        <div class="filter-row">
            <span class="filter-label">Loại đơn:</span>
            <button :class="{ active: typeFilter === 'ALL' }" @click="typeFilter = 'ALL'">Tất cả</button>
            <button :class="{ active: typeFilter === 'ONLINE' }" @click="typeFilter = 'ONLINE'">Giao tận nơi</button>
            <button :class="{ active: typeFilter === 'TAKEAWAY' }" @click="typeFilter = 'TAKEAWAY'">Mang đi</button>
        </div>

        <nav class="tabs">
            <button :class="{ active: tab === 'pending' }" @click="tab = 'pending'">
                Chờ xác nhận ({{ counts.pending }})
            </button>
            <button :class="{ active: tab === 'preparing' }" @click="tab = 'preparing'">
                Đã xác nhận / Chế biến ({{ counts.preparing }})
            </button>
            <button :class="{ active: tab === 'ready' }" @click="tab = 'ready'">
                Sẵn sàng giao ({{ counts.ready }})
            </button>
            <button :class="{ active: tab === 'delivering' }" @click="tab = 'delivering'">
                Đang giao ({{ counts.delivering }})
            </button>
            <button :class="{ active: tab === 'delivered' }" @click="tab = 'delivered'">
                Chờ khách xác nhận ({{ counts.delivered }})
            </button>
            <button :class="{ active: tab === 'done' }" @click="tab = 'done'">
                Hoàn tất / Đã hủy ({{ counts.done }})
            </button>
        </nav>

        <p v-if="error" class="alert error" role="alert">{{ error }}</p>
        <p v-if="loading && !orders.length" class="empty">Đang tải danh sách đơn giao hàng…</p>

        <div v-else-if="!visibleOrders.length" class="empty">
            <p>Chưa có đơn nào trong tab này.</p>
        </div>

        <div v-else class="orders">
            <article v-for="o in visibleOrders" :key="o.id" class="order-card" :id="`staff-order-${o.id}`" tabindex="-1" :class="{ linked: Number(route.query.order_id) === o.id }">
                <header class="order-head">
                    <div class="left">
                        <span class="order-code">{{ o.code }}</span>
                        <span :class="pillFor(o).cls">{{ pillFor(o).label }}</span>
                        <span :class="typeBadgeCls(o.order_type)">{{ typeLabel(o.order_type) }}</span>
                        <span class="pill" :class="o.payment_method === 'COD' ? 'pill-orange' : 'pill-blue'">
                            {{ paymentMethodLabel(o.payment_method) }}
                        </span>
                        <span
                            class="pill"
                            :class="o.payment?.status === 'PAID' ? 'pill-green' : 'pill-amber'"
                        >
                            {{ paymentLabel(o) }}
                        </span>
                    </div>
                    <strong class="amount">{{ formatVND(o.total_amount) }}</strong>
                </header>

                <section class="recipient">
                    <p><strong>{{ o.recipient_name || '—' }}</strong> · {{ o.recipient_phone || '—' }}</p>
                    <p class="address">{{ addressLine(o) }}</p>
                    <p v-if="o.note" class="note">{{ o.note }}</p>
                </section>

                <section class="info-grid">
                    <div><span>Khách</span><strong>{{ o.customer_name || 'Khách vãng lai' }}</strong></div>
                    <div><span>Đặt lúc</span><strong>{{ formatDateTime(o.placed_at) }}</strong></div>
                    <div><span>Xác nhận</span><strong>{{ formatTime(o.confirmed_at) }}</strong></div>
                    <div><span>Bàn giao</span><strong>{{ formatTime(o.handed_over_at) }}</strong></div>
                    <div><span>Shipper</span><strong>{{ o.shipper_name || '—' }}</strong></div>
                    <div><span>Sự cố</span><strong style="color:#b91c1c;">{{ o.delivery_issue || '—' }}</strong></div>
                </section>

                <section
                    v-if="o.cancellation_request"
                    class="cancellation-flow"
                    :class="`is-${o.cancellation_request.status.toLowerCase()}`"
                    aria-label="Trạng thái yêu cầu hủy đơn"
                >
                    <div>
                        <strong>
                            <template v-if="o.cancellation_request.status === 'PENDING'">Khách yêu cầu hủy đơn</template>
                            <template v-else-if="o.cancellation_request.status === 'APPROVED'">Đã duyệt hủy · Chờ hoàn tiền</template>
                            <template v-else>Hoàn tiền VNPay thành công</template>
                        </strong>
                        <p>{{ o.cancellation_request.reason }}</p>
                    </div>
                    <span class="cancellation-step">
                        {{ o.cancellation_request.status === 'PENDING' ? 'Bước 1/2' : o.cancellation_request.status === 'APPROVED' ? 'Bước 2/2' : 'Hoàn tất' }}
                    </span>
                </section>

                <section class="items">
                    <h3>Món ({{ o.items.length }})</h3>
                    <ul>
                        <li v-for="it in o.items.filter(i => i.status !== 'CANCELLED')" :key="it.id">
                            <span>
                                {{ it.menu_item_name }}
                                <span v-if="it.spicy_level" class="spicy">Cấp {{ it.spicy_level }}</span>
                            </span>
                            <span>x {{ it.quantity }} · {{ formatVND(it.subtotal) }}</span>
                        </li>
                    </ul>
                </section>

                <footer class="actions">
                    <template v-if="o.cancellation_request?.status === 'PENDING'">
                        <button v-if="isManager" class="btn-danger-solid" :disabled="busyId === o.id" @click="approveCancellation(o)">
                            {{ busyId === o.id ? 'Đang duyệt…' : 'Duyệt hủy đơn' }}
                        </button>
                        <span v-else class="note-info">Yêu cầu đang chờ quản lý duyệt.</span>
                    </template>
                    <template v-else-if="o.cancellation_request?.status === 'APPROVED'">
                        <button v-if="isManager" class="btn-primary" :disabled="busyId === o.id" @click="confirmRefund(o)">
                            {{ busyId === o.id ? 'Đang xác nhận…' : 'Xác nhận hoàn tiền' }}
                        </button>
                        <span v-else class="note-info">Quản lý đang xử lý hoàn tiền.</span>
                    </template>
                    <template v-else-if="o.cancellation_request?.status === 'REFUNDED'">
                        <span class="note-info note-info--success">Đã hoàn tiền và thông báo cho khách hàng.</span>
                    </template>
                    <template v-else-if="o.status === 'PENDING'">
                        <button class="btn-ghost danger" :disabled="busyId === o.id" @click="openReject(o)">
                            Từ chối
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="busyId === o.id"
                            @click="handleConfirm(o)"
                        >
                            Xác nhận đơn
                        </button>
                    </template>
                    <template v-else-if="o.status === 'READY_NOTIFY_SHIPPER'">
                        <button class="btn-primary" :disabled="busyId === o.id" @click="openHandover(o)">
                            Bàn giao shipper
                        </button>
                    </template>
                    <template v-else>
                        <span class="note-info">
                            <template v-if="o.status === 'DELIVERED'">
                                Shipper đã giao — đang chờ khách xác nhận đã nhận hàng.
                                <span v-if="o.points_awarded > 0">Đã cộng {{ o.points_awarded }} điểm.</span>
                            </template>
                            <template v-else>
                                {{ o.delivery_issue ? 'Sự cố: ' + o.delivery_issue : 'Đơn đang vận hành bình thường.' }}
                            </template>
                        </span>
                    </template>
                </footer>
            </article>
        </div>

        <Teleport to="body">
            <div v-if="rejectTarget" class="modal-layer" @click.self="rejectTarget = null">
                <form class="modal" @submit.prevent="submitReject">
                    <h3>Từ chối đơn {{ rejectTarget.code }}</h3>
                    <p class="modal-sub">Lý do sẽ được lưu lại trong lịch sử đơn và đơn được chuyển sang CANCELLED.</p>
                    <textarea
                        v-model="rejectReason"
                        rows="3"
                        maxlength="500"
                        placeholder="VD: Sai địa chỉ, khách đổi ý, thiếu thông tin…"
                        required
                    />
                    <p class="counter">{{ rejectReason.length }}/500</p>
                    <footer class="modal-actions">
                        <button type="button" class="btn-ghost" @click="rejectTarget = null">Hủy</button>
                        <button type="submit" class="btn-primary" :disabled="busyId !== null">Xác nhận từ chối</button>
                    </footer>
                </form>
            </div>

            <div v-if="confirmTarget" class="modal-layer" @click.self="busyId === null && (confirmTarget = null)">
                <form class="modal" @submit.prevent="submitConfirm">
                    <h3>Duyệt đơn {{ confirmTarget.code }}</h3>
                    <fieldset class="shipper-list" :disabled="busyId !== null">
                        <legend>Chọn shipper nhận đơn</legend>

                        <!-- Phase 2 — Tự động gán shipper gần nhất (theo vị trí GPS) -->
                        <label class="shipper-option shipper-option--auto">
                            <input type="radio" name="confirm-shipper" :value="null" v-model="confirmShipperId" />
                            <span>
                                <strong>Tự động gán shipper gần nhất</strong>
                                <small>Hệ thống chọn shipper đang rảnh gần nhà hàng nhất theo vị trí GPS.</small>
                            </span>
                        </label>

                        <label v-for="s in shippers" :key="s.id" class="shipper-option">
                            <input type="radio" name="confirm-shipper" :value="s.id" v-model="confirmShipperId" />
                            <span>
                                {{ s.name }}
                                <small v-if="nearestDistance(s.id) !== null" class="shipper-distance">
                                    cách quán {{ nearestDistance(s.id)!.toFixed(2) }} km
                                </small>
                            </span>
                        </label>
                        <p v-if="!shippers.length" class="empty small">Chưa có shipper đang hoạt động để phân công.</p>
                    </fieldset>
                    <p class="modal-sub">Đơn sẽ chuyển vào bếp sau khi duyệt. Bàn giao hàng thực hiện khi chế biến xong.</p>
                    <footer class="modal-actions">
                        <button type="button" class="btn-ghost" :disabled="busyId !== null" @click="confirmTarget = null">Hủy</button>
                        <button type="submit" class="btn-primary" :disabled="busyId !== null">
                            {{ busyId !== null ? 'Đang duyệt…' : confirmShipperId === null ? 'Duyệt & tự gán shipper' : 'Duyệt và phân công' }}
                        </button>
                    </footer>
                </form>
            </div>

            <div v-if="handoverTarget" class="modal-layer" @click.self="handoverTarget = null">
                <form class="modal" @submit.prevent="submitHandover">
                    <h3>Bàn giao đơn {{ handoverTarget.code }}</h3>
                    <p class="modal-sub">
                        {{ handoverTarget.order_type === 'ONLINE' && handoverTarget.shipper_id ? 'Bàn giao cho shipper đã được phân công.' : 'Chọn shipper để nhận đơn.' }}
                        Sau khi bàn giao, shipper sẽ tự đến bếp lấy món và {{ handoverTarget.order_type === 'TAKEAWAY' ? 'chờ khách đến nhận' : 'giao đến địa chỉ khách' }}.
                    </p>
                    <p v-if="handoverTarget.order_type === 'ONLINE' && handoverTarget.shipper_id" class="modal-sub">
                        Shipper: {{ handoverTarget.shipper_name || handoverTarget.shipper_id }}
                    </p>
                    <div class="shipper-list" v-else-if="shippers.length">
                        <label v-for="s in shippers" :key="s.id" class="shipper-option">
                            <input type="radio" name="shipper" :value="s.id" v-model="handoverShipperId" />
                            <span>{{ s.name }}</span>
                        </label>
                    </div>
                    <p v-else class="empty small">Chưa có shipper khả dụng.</p>
                    <footer class="modal-actions">
                        <button type="button" class="btn-ghost" @click="handoverTarget = null">Hủy</button>
                        <button type="submit" class="btn-primary" :disabled="!handoverShipperId || busyId !== null">Bàn giao</button>
                    </footer>
                </form>
            </div>
        </Teleport>
    </section>
</template>

<style scoped>
.order-card.linked { outline: 3px solid var(--color-spicy-2, #ff7a00); outline-offset: 3px; }
.online-orders { padding: 24px; max-width: 1400px; margin: 0 auto; }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.page-head h1 { margin: 4px 0; font-size: 26px; color: var(--color-charcoal-black, #222); }
.eyebrow { display: inline-block; font-size: 11px; letter-spacing: .12em; color: var(--color-chili-red, #d71920); font-weight: 700; }
.subtitle { margin: 0; color: var(--color-gray-600, #555); font-size: 14px; }

.filter-row { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-label { font-size: 13px; color: var(--color-gray-600, #555); margin-right: 4px; }
.filter-row button { padding: 6px 14px; border: 1px solid var(--color-gray-200, #eee); border-radius: 999px; background: white; cursor: pointer; font-size: 13px; }
.filter-row button.active { background: var(--color-chili-red, #d71920); color: white; border-color: var(--color-chili-red, #d71920); }

.tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 2px solid var(--color-gray-200, #eee); overflow-x: auto; }
.tabs button { padding: 12px 18px; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--color-gray-600, #555); border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; }
.tabs button.active { color: var(--color-chili-red, #d71920); border-bottom-color: var(--color-chili-red, #d71920); }

.alert { padding: 12px 16px; border-radius: 8px; }
.alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.empty { text-align: center; padding: 48px 24px; color: var(--color-gray-500, #777); }
.empty.small { padding: 16px; text-align: left; }

.orders { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 16px; }
.order-card { background: white; border-radius: 12px; padding: 18px; border: 1px solid var(--color-gray-200, #eee); display: flex; flex-direction: column; gap: 14px; }
.order-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.order-head .left { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.order-code { font-weight: 700; font-size: 15px; }
.amount { font-size: 18px; font-weight: 700; color: var(--color-chili-red, #d71920); }

.pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-cyan { background: #cffafe; color: #155e75; }
.pill-green { background: #d1fae5; color: #065f46; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-orange { background: #ffedd5; color: #9a3412; }
.pill-gray { background: var(--color-gray-200, #eee); color: var(--color-gray-600, #555); }

.recipient p { margin: 4px 0; font-size: 14px; }
.recipient .address { color: var(--color-gray-700, #333); }
.recipient .note { background: #fffbeb; padding: 6px 10px; border-radius: 6px; font-size: 13px; color: #78350f; }

.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; padding: 12px; background: #f9fafb; border-radius: 8px; }
.info-grid > div { display: flex; flex-direction: column; gap: 2px; }
.info-grid span { font-size: 11px; color: var(--color-gray-500, #777); text-transform: uppercase; letter-spacing: .04em; }
.info-grid strong { font-size: 13px; color: var(--color-charcoal-black, #222); }

.cancellation-flow { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 14px 16px; border-radius: 10px; background: #fff4e5; color: #713f12; }
.cancellation-flow.is-approved { background: #fff1f2; color: #881337; }
.cancellation-flow.is-refunded { background: #ecfdf3; color: #166534; }
.cancellation-flow strong { display: block; font-size: 14px; }
.cancellation-flow p { margin: 4px 0 0; color: inherit; font-size: 13px; line-height: 1.5; }
.cancellation-step { flex: 0 0 auto; padding: 4px 9px; border-radius: 999px; background: rgba(255,255,255,.72); color: inherit; font-size: 12px; font-weight: 700; }

.items h3 { margin: 0 0 8px; font-size: 13px; color: var(--color-gray-600, #555); text-transform: uppercase; letter-spacing: .08em; }
.items ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.items li { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; border-bottom: 1px dashed var(--color-gray-200, #eee); }
.items li:last-child { border-bottom: none; }
.spicy { background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 4px; font-size: 11px; margin-left: 4px; }

.actions { display: flex; gap: 8px; }
.note-info { font-size: 13px; color: var(--color-gray-600, #555); padding: 8px 0; }
.btn-ghost, .btn-primary, .btn-danger-solid { flex: 1; min-height: 44px; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; border: 1px solid var(--color-gray-200, #eee); background: white; transition: background-color .15s ease, border-color .15s ease, transform .15s ease; }
.btn-primary { background: var(--color-chili-red, #d71920); color: white; border-color: var(--color-chili-red, #d71920); }
.btn-ghost.danger { color: #991b1b; border-color: #fecaca; }
.btn-danger-solid { background: #a4131d; border-color: #a4131d; color: white; }
.btn-primary:hover:not(:disabled), .btn-danger-solid:hover:not(:disabled) { filter: brightness(.92); transform: translateY(-1px); }
.btn-ghost:hover:not(:disabled) { background: #f8fafc; border-color: #cbd5e1; }
.btn-primary:active:not(:disabled), .btn-danger-solid:active:not(:disabled), .btn-ghost:active:not(:disabled) { transform: translateY(0); }
.btn-primary:focus-visible, .btn-danger-solid:focus-visible, .btn-ghost:focus-visible { outline: 3px solid var(--color-chili-red, #d71920); outline-offset: 2px; }
.btn-primary:disabled, .btn-ghost:disabled, .btn-danger-solid:disabled { opacity: .6; cursor: not-allowed; }
.note-info--success { width: 100%; padding: 12px 14px; border-radius: 8px; background: #ecfdf3; color: #166534; font-weight: 700; }

.modal-layer { position: fixed; inset: 0; background: rgba(0, 0, 0, .45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: white; border-radius: 12px; padding: 24px; max-width: 480px; width: 90%; }
.modal h3 { margin: 0 0 8px; }
.modal-sub { color: var(--color-gray-600, #555); font-size: 13px; margin: 0 0 12px; }
.modal textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--color-gray-200, #eee); font: inherit; resize: vertical; box-sizing: border-box; }
.counter { text-align: right; font-size: 12px; color: var(--color-gray-500, #888); margin: 4px 0 0; }
.modal-actions { display: flex; gap: 8px; margin-top: 16px; justify-content: flex-end; }

.shipper-list { display: flex; flex-direction: column; gap: 8px; max-height: 200px; overflow-y: auto; margin: 8px 0 12px; }
.shipper-option { display: flex; gap: 8px; align-items: center; padding: 8px 12px; border: 1px solid var(--color-gray-200, #eee); border-radius: 8px; cursor: pointer; }
.shipper-option input { margin: 0; }
.shipper-option > span { display: flex; flex-direction: column; gap: 2px; }
.shipper-option--auto { border-color: var(--color-chili-red, #d71920); background: #fff5f5; }
.shipper-option--auto strong { color: var(--color-chili-red, #d71920); font-size: 14px; }
.shipper-option small { font-size: 12px; color: var(--color-gray-500, #777); }
.shipper-distance { color: var(--color-gray-500, #777); }

@media (max-width: 640px) {
    .online-orders { padding: 16px; }
    .orders { grid-template-columns: 1fr; }
    .cancellation-flow { flex-direction: column; }
}
</style>
