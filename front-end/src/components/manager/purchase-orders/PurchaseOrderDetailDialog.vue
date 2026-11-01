<template>
  <dialog ref="dialog" class="purchase-order-dialog purchase-order-dialog--detail" @cancel="onCancel">
    <header class="purchase-order-dialog__header">
      <div>
        <h2>{{ order?.code ?? 'Chi tiết yêu cầu nhập' }}</h2>
        <p v-if="order" class="purchase-order-help">
          {{ order.supplier_name }} · tạo bởi {{ order.created_by_name || 'Quản lý' }}
        </p>
      </div>
      <button class="purchase-order-dialog__close" type="button" aria-label="Đóng chi tiết" :disabled="busy" @click="close">×</button>
    </header>

    <div class="purchase-order-dialog__body">
      <div v-if="loading" class="purchase-order-state" role="status">
        <div><span class="purchase-order-spinner" aria-hidden="true"></span><p>Đang tải yêu cầu…</p></div>
      </div>
      <div v-else-if="loadError" class="purchase-order-state purchase-order-state--error" role="alert">
        <div><p>{{ loadError }}</p><button class="purchase-order-button" type="button" @click="reload">Thử lại</button></div>
      </div>
      <template v-else-if="order">
        <section class="purchase-order-section" aria-labelledby="po-progress-title">
          <div class="purchase-order-section__heading">
            <h3 id="po-progress-title">Tiến trình chứng từ</h3>
            <span class="purchase-order-status" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span>
          </div>
          <ol class="purchase-order-timeline">
            <li v-for="stage in timeline" :key="stage.status" :class="{ 'is-complete': isStageComplete(stage.status) }">
              <strong>{{ stage.label }}</strong>
              <span>{{ timelineTime(stage.status) }}</span>
            </li>
          </ol>
          <div v-if="order.status === 'SUPPLIER_REJECTED'" class="purchase-order-error-summary" role="status">
            <strong>Nhà cung cấp đã từ chối:</strong> {{ order.rejection_reason }}
          </div>
        </section>

        <section class="purchase-order-section" aria-labelledby="po-lines-title">
          <div class="purchase-order-section__heading">
            <div>
              <h3 id="po-lines-title">Đối chiếu số lượng</h3>
              <p class="purchase-order-help">Đơn vị hiển thị là đơn vị mua đã chốt khi gửi yêu cầu.</p>
            </div>
          </div>
          <div class="purchase-orders__table-wrap">
            <table class="purchase-orders__table">
              <thead>
                <tr><th>Nguyên liệu</th><th class="is-number">Yêu cầu</th><th class="is-number">NCC nhận</th><th class="is-number">Đã gửi</th><th class="is-number">Đơn giá</th></tr>
              </thead>
              <tbody>
                <tr v-for="item in order.items" :key="item.id">
                  <td><strong>{{ item.ingredient_name }}</strong><br><span class="purchase-order-muted">{{ item.ingredient_code }} · {{ item.purchase_unit }}</span></td>
                  <td class="is-number">{{ formatQuantity(item.requested_purchase_quantity) }}</td>
                  <td class="is-number">{{ formatOptionalQuantity(item.supplier_accepted_quantity) }}</td>
                  <td class="is-number">{{ formatOptionalQuantity(item.shipped_purchase_quantity) }}</td>
                  <td class="is-number">{{ formatMoney(item.purchase_unit_price) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="canInspect || order.stock_import" class="purchase-order-section" aria-labelledby="po-receipt-title">
          <div class="purchase-order-section__heading">
            <div>
              <h3 id="po-receipt-title">Kiểm nhận tại kho</h3>
              <p class="purchase-order-help">Chỉ số chấp nhận được quy đổi và cộng vào tồn kho.</p>
            </div>
            <button v-if="canInspect && !order.stock_import" class="purchase-order-button purchase-order-button--primary" type="button" :disabled="busy" @click="createReceipt">
              {{ busy ? 'Đang mở…' : 'Mở kiểm nhận' }}
            </button>
          </div>

          <template v-if="order.stock_import">
            <div class="purchase-order-summary">
              <div><span>Phiếu nhập</span><strong>{{ order.stock_import.code }}</strong></div>
              <div><span>Trạng thái</span><strong>{{ order.stock_import.status === 'CONFIRMED' ? 'Đã xác nhận' : 'Đang kiểm nhận' }}</strong></div>
              <div><span>Lượng nhập kho</span><strong>{{ formatQuantity(totalStockQuantity) }}</strong></div>
              <div><span>Giá trị chấp nhận</span><strong>{{ formatMoney(receiptTotal) }}</strong></div>
            </div>

            <div v-if="receiptError" ref="receiptErrorSummary" class="purchase-order-error-summary" role="alert" tabindex="-1">
              <strong>Chưa thể lưu kiểm nhận.</strong> {{ receiptError }}
            </div>

            <div class="purchase-orders__table-wrap">
              <table class="purchase-orders__table purchase-order-receipt-table">
                <thead>
                  <tr><th>Nguyên liệu</th><th class="is-number">Đã gửi</th><th>Thực nhận</th><th>Chấp nhận</th><th>Từ chối / lý do</th><th>Lô / HSD</th><th class="is-number">Thành tiền</th></tr>
                </thead>
                <tbody>
                  <tr v-for="line in receiptLines" :key="line.purchase_order_item_id">
                    <td><strong>{{ line.ingredient_name }}</strong><br><span class="purchase-order-muted">{{ line.purchase_unit }}</span></td>
                    <td class="is-number">{{ formatQuantity(shippedFor(line.purchase_order_item_id)) }}</td>
                    <td>
                      <label class="purchase-order-muted" :for="`delivered-${line.id}`">Số thực nhận</label>
                      <input :id="`delivered-${line.id}`" v-model.number="line.delivered_purchase_quantity" class="purchase-order-input" type="number" min="0" step="1" :disabled="receiptLocked || busy" />
                    </td>
                    <td>
                      <label class="purchase-order-muted" :for="`accepted-${line.id}`">Số nhập kho</label>
                      <input :id="`accepted-${line.id}`" v-model.number="line.accepted_purchase_quantity" class="purchase-order-input" type="number" min="0" :max="line.delivered_purchase_quantity" step="1" :disabled="receiptLocked || busy" />
                    </td>
                    <td>
                      <strong>{{ formatQuantity(rejectedFor(line)) }}</strong>
                      <label v-if="rejectedFor(line) > 0" class="purchase-order-muted" :for="`reason-${line.id}`">Lý do từ chối</label>
                      <input v-if="rejectedFor(line) > 0" :id="`reason-${line.id}`" v-model.trim="line.rejection_reason" class="purchase-order-input" maxlength="500" :disabled="receiptLocked || busy" />
                    </td>
                    <td>
                      <label class="purchase-order-muted" :for="`lot-${line.id}`">Mã lô</label>
                      <input :id="`lot-${line.id}`" v-model.trim="line.supplier_lot_code" class="purchase-order-input" maxlength="60" :disabled="receiptLocked || busy" />
                      <label class="purchase-order-muted" :for="`expiry-${line.id}`">Hạn sử dụng</label>
                      <input :id="`expiry-${line.id}`" v-model="line.expiry_date" class="purchase-order-input" type="date" :disabled="receiptLocked || busy" />
                    </td>
                    <td class="is-number">{{ formatMoney(line.accepted_purchase_quantity * line.purchase_unit_price) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="receiptLocked" class="purchase-order-help" role="status">
              Phiếu đã xác nhận lúc {{ formatDateTime(order.stock_import.confirmed_at) }} và không thể sửa trực tiếp.
              <RouterLink class="purchase-order-link" :to="{ name: 'manager-stock-imports', query: { q: order.stock_import.code } }">Xem phiếu nhập</RouterLink>
              · <RouterLink class="purchase-order-link" :to="{ name: 'manager-stock-transactions', query: { reference_type: 'stock_import', reference_id: order.stock_import.id } }">Xem lịch sử kho</RouterLink>
            </div>

            <div v-else class="purchase-order-actions">
              <button class="purchase-order-button" type="button" :disabled="busy" @click="saveReceipt">
                {{ busy ? 'Đang lưu…' : 'Lưu kiểm nhận' }}
              </button>
              <button class="purchase-order-button purchase-order-button--primary" type="button" :disabled="busy" @click="confirmReceipt">
                {{ busy ? 'Đang xác nhận…' : 'Xác nhận nhập kho' }}
              </button>
            </div>
          </template>
        </section>

        <PurchaseOrderReturnSection v-if="order.status === 'RECEIVED' && order.stock_import?.status === 'CONFIRMED'" :order="order" @updated="onReturnUpdated" />
      </template>
    </div>

    <footer class="purchase-order-dialog__footer">
      <button class="purchase-order-button" type="button" :disabled="busy" @click="close">Đóng</button>
    </footer>
  </dialog>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { RouterLink } from 'vue-router'
import PurchaseOrderReturnSection from './PurchaseOrderReturnSection.vue'
import { useAppDialog } from '@/composables/useAppDialog'
import {
  purchaseOrderError,
  purchaseOrdersApi,
  type PurchaseOrder,
  type PurchaseOrderReceiptItem,
  type PurchaseOrderStatus,
  type ReceiptPayload,
} from '@/api/purchaseOrders'

const emit = defineEmits<{ updated: [order: PurchaseOrder] }>()
const appDialog = useAppDialog()
const dialog = ref<HTMLDialogElement | null>(null)
const order = ref<PurchaseOrder | null>(null)
const orderId = ref<number | null>(null)
const loading = ref(false)
const busy = ref(false)
const loadError = ref('')
const receiptError = ref('')
const receiptErrorSummary = ref<HTMLElement | null>(null)
const receiptLines = ref<PurchaseOrderReceiptItem[]>([])

const timeline = [
  { status: 'DRAFT' as const, label: 'Tạo yêu cầu' },
  { status: 'SENT' as const, label: 'Đã gửi NCC' },
  { status: 'SUPPLIER_ACCEPTED' as const, label: 'NCC chấp nhận' },
  { status: 'SHIPPED' as const, label: 'NCC đã gửi' },
  { status: 'RECEIVED' as const, label: 'Đã nhập kho' },
]

const statusRanks: Partial<Record<PurchaseOrderStatus, number>> = {
  DRAFT: 0,
  SENT: 1,
  SUPPLIER_ACCEPTED: 2,
  SHIPPED: 3,
  RECEIVED: 4,
}

const canInspect = computed(() => order.value?.status === 'SHIPPED')
const receiptLocked = computed(() => order.value?.stock_import?.status === 'CONFIRMED')
const receiptTotal = computed(() => receiptLines.value.reduce(
  (sum, line) => sum + line.accepted_purchase_quantity * line.purchase_unit_price,
  0,
))
const totalStockQuantity = computed(() => receiptLines.value.reduce(
  (sum, line) => sum + line.accepted_purchase_quantity * line.conversion_quantity,
  0,
))

async function open(selected: PurchaseOrder) {
  orderId.value = selected.id
  order.value = selected
  syncReceiptLines()
  loadError.value = ''
  receiptError.value = ''
  dialog.value?.showModal()
  await reload()
}

async function reload() {
  if (!orderId.value) return
  loading.value = true
  loadError.value = ''
  try {
    order.value = (await purchaseOrdersApi.get(orderId.value)).data.data
    syncReceiptLines()
  } catch (error) {
    loadError.value = purchaseOrderError(error)
  } finally {
    loading.value = false
  }
}

function syncReceiptLines() {
  receiptLines.value = (order.value?.stock_import?.items ?? []).map((line) => ({ ...line }))
}

function onReturnUpdated(updated: PurchaseOrder) {
  order.value = updated
  syncReceiptLines()
  emit('updated', updated)
}

function close() {
  if (!busy.value) dialog.value?.close()
}

function onCancel(event: Event) {
  if (busy.value) event.preventDefault()
}

async function createReceipt() {
  if (!order.value || busy.value) return
  busy.value = true
  receiptError.value = ''
  try {
    order.value = (await purchaseOrdersApi.createReceipt(order.value.id)).data.data
    syncReceiptLines()
    emit('updated', order.value)
  } catch (error) {
    await showReceiptError(error)
  } finally {
    busy.value = false
  }
}

function receiptPayload(): ReceiptPayload {
  return {
    note: order.value?.stock_import?.note ?? null,
    items: receiptLines.value.map((line) => ({
      purchase_order_item_id: line.purchase_order_item_id,
      delivered_purchase_quantity: line.delivered_purchase_quantity,
      accepted_purchase_quantity: line.accepted_purchase_quantity,
      rejection_reason: rejectedFor(line) > 0 ? line.rejection_reason : null,
      supplier_lot_code: line.supplier_lot_code || null,
      expiry_date: line.expiry_date || null,
    })),
  }
}

async function persistReceipt(): Promise<boolean> {
  if (!order.value) return false
  const invalidLine = receiptLines.value.find((line) =>
    !Number.isInteger(line.delivered_purchase_quantity)
    || !Number.isInteger(line.accepted_purchase_quantity)
    || line.delivered_purchase_quantity < 0
    || line.accepted_purchase_quantity < 0
    || line.accepted_purchase_quantity > line.delivered_purchase_quantity,
  )
  if (invalidLine) {
    receiptError.value = 'Số thực nhận và số chấp nhận phải là số nguyên không âm; số chấp nhận không được vượt số thực nhận.'
    await nextTick()
    receiptErrorSummary.value?.focus()
    return false
  }
  const missingReason = receiptLines.value.find((line) => rejectedFor(line) > 0 && !line.rejection_reason?.trim())
  if (missingReason) {
    receiptError.value = `Hãy nhập lý do từ chối cho ${missingReason.ingredient_name || 'dòng hàng có chênh lệch'}.`
    await nextTick()
    receiptErrorSummary.value?.focus()
    return false
  }
  try {
    order.value = (await purchaseOrdersApi.updateReceipt(order.value.id, receiptPayload())).data.data
    syncReceiptLines()
    emit('updated', order.value)
    return true
  } catch (error) {
    await showReceiptError(error)
    return false
  }
}

async function saveReceipt() {
  if (busy.value) return
  busy.value = true
  receiptError.value = ''
  await persistReceipt()
  busy.value = false
}

async function confirmReceipt() {
  if (!order.value || busy.value) return
  const confirmed = await appDialog.confirm({
    title: `Xác nhận nhập kho ${order.value.code}?`,
    message: `Hệ thống sẽ cộng ${formatQuantity(totalStockQuantity.value)} đơn vị tồn kho, ghi giao dịch và khóa phiếu kiểm nhận.`,
    confirmLabel: 'Xác nhận nhập kho',
  })
  if (!confirmed) return

  busy.value = true
  receiptError.value = ''
  if (!await persistReceipt()) {
    busy.value = false
    return
  }
  try {
    order.value = (await purchaseOrdersApi.receive(order.value.id, currentLocalDateTime())).data.data
    syncReceiptLines()
    emit('updated', order.value)
  } catch (error) {
    await showReceiptError(error)
  } finally {
    busy.value = false
  }
}

async function showReceiptError(error: unknown) {
  receiptError.value = purchaseOrderError(error)
  await nextTick()
  receiptErrorSummary.value?.focus()
}

function rejectedFor(line: PurchaseOrderReceiptItem) {
  return Math.max(0, Number(line.delivered_purchase_quantity || 0) - Number(line.accepted_purchase_quantity || 0))
}

function shippedFor(purchaseOrderItemId: number) {
  return order.value?.items.find((item) => item.id === purchaseOrderItemId)?.shipped_purchase_quantity ?? 0
}

function isStageComplete(status: PurchaseOrderStatus) {
  const currentRank = statusRanks[order.value?.status ?? 'DRAFT']
  const stageRank = statusRanks[status]
  return currentRank !== undefined && stageRank !== undefined && currentRank >= stageRank
}

function timelineTime(status: PurchaseOrderStatus) {
  if (!order.value) return 'Chưa thực hiện'
  const value = status === 'DRAFT' ? order.value.created_at
    : status === 'SENT' ? order.value.sent_at
      : status === 'SUPPLIER_ACCEPTED' ? order.value.supplier_responded_at
        : status === 'SHIPPED' ? order.value.shipped_at
          : order.value.received_at
  return value ? formatDateTime(value) : 'Chưa thực hiện'
}

function statusLabel(status: PurchaseOrderStatus) {
  return ({
    DRAFT: 'Bản nháp', SENT: 'Chờ phản hồi', SUPPLIER_ACCEPTED: 'Đã chấp nhận',
    SUPPLIER_REJECTED: 'Đã từ chối', SHIPPED: 'Đang giao', RECEIVED: 'Đã nhập kho', CANCELLED: 'Đã hủy',
  } as Record<PurchaseOrderStatus, string>)[status]
}

function statusTone(status: PurchaseOrderStatus) {
  if (status === 'RECEIVED' || status === 'SUPPLIER_ACCEPTED') return 'purchase-order-status--success'
  if (status === 'SUPPLIER_REJECTED' || status === 'CANCELLED') return 'purchase-order-status--error'
  if (status === 'SENT' || status === 'SHIPPED') return 'purchase-order-status--warning'
  return ''
}

function formatQuantity(value: number) {
  return Number(value).toLocaleString('vi-VN')
}

function formatOptionalQuantity(value: number | null) {
  return value === null ? '—' : formatQuantity(value)
}

function formatMoney(value: number | null) {
  return value === null ? '—' : `${Number(value).toLocaleString('vi-VN')} đ`
}

function formatDateTime(value: string | null) {
  return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
}

function currentLocalDateTime() {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 19).replace('T', ' ')
}

defineExpose({ open })
</script>
