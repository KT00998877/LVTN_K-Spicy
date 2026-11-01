<template>
  <dialog ref="dialog" class="purchase-order-dialog" @cancel="onCancel">
    <header class="purchase-order-dialog__header">
      <div><h2>{{ order?.code ?? 'Chi tiết yêu cầu' }}</h2><p class="purchase-order-help">Yêu cầu nhập hàng từ K-SPICY</p></div>
      <button class="purchase-order-dialog__close" type="button" aria-label="Đóng chi tiết" :disabled="busy" @click="close">×</button>
    </header>

    <div class="purchase-order-dialog__body">
      <div v-if="loading" class="purchase-order-state" role="status"><p>Đang tải yêu cầu…</p></div>
      <div v-else-if="loadError" class="purchase-order-state purchase-order-state--error" role="alert"><div><p>{{ loadError }}</p><button class="purchase-order-button" type="button" @click="reload">Thử lại</button></div></div>
      <template v-else-if="order">
        <section class="purchase-order-section" aria-labelledby="supplier-summary-title">
          <div class="purchase-order-section__heading"><h3 id="supplier-summary-title">Thông tin giao hàng</h3><span class="purchase-order-status" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span></div>
          <div class="purchase-order-summary">
            <div><span>Ngày nhà hàng cần</span><strong>{{ formatDate(order.requested_delivery_date) }}</strong></div>
            <div><span>Ngày dự kiến giao</span><strong>{{ formatDate(order.supplier_delivery_date) }}</strong></div>
            <div><span>Chứng từ giao</span><strong>{{ order.supplier_document_code || 'Chưa có' }}</strong></div>
            <div><span>Ghi chú quản lý</span><strong>{{ order.manager_note || 'Không có' }}</strong></div>
            <div v-if="order.supplier_note"><span>Ghi chú nhà cung cấp</span><strong>{{ order.supplier_note }}</strong></div>
          </div>
        </section>

        <section class="purchase-order-section" aria-labelledby="supplier-progress-title">
          <h3 id="supplier-progress-title">Tiến trình đơn hàng</h3>
          <ol class="purchase-order-timeline purchase-order-timeline--supplier">
            <li class="is-complete"><strong>Nhà hàng gửi yêu cầu</strong><span>{{ formatDateTime(order.sent_at) }}</span></li>
            <li :class="{ 'is-complete': !!order.supplier_responded_at }"><strong>{{ order.status === 'SUPPLIER_REJECTED' ? 'Đã từ chối' : 'Nhà cung cấp phản hồi' }}</strong><span>{{ formatDateTime(order.supplier_responded_at) }}</span></li>
            <li :class="{ 'is-complete': !!order.shipped_at }"><strong>Nhà cung cấp gửi hàng</strong><span>{{ formatDateTime(order.shipped_at) }}</span></li>
            <li :class="{ 'is-complete': !!order.received_at }"><strong>Nhà hàng nhận hàng</strong><span>{{ formatDateTime(order.received_at) }}</span></li>
          </ol>
          <p v-if="order.status === 'SUPPLIER_REJECTED'" class="purchase-order-help">Yêu cầu đã kết thúc sau khi từ chối.</p>
        </section>

        <section v-if="order.return_notices?.length" class="purchase-order-section" aria-labelledby="supplier-returns-title">
          <h3 id="supplier-returns-title">Hàng nhà hàng đã trả</h3>
          <div v-for="notice in order.return_notices" :key="notice.code" class="purchase-order-return-record">
            <strong>{{ notice.code }}</strong> · {{ formatDateTime(notice.returned_at) }}
            <p><strong>Lý do:</strong> {{ notice.reason }}</p>
            <ul><li v-for="(item, index) in notice.items" :key="index">{{ item.ingredient_name || 'Nguyên liệu' }}: {{ formatQuantity(item.quantity) }} {{ item.unit || 'đơn vị tồn kho' }}</li></ul>
          </div>
        </section>

        <div v-if="formError" ref="errorSummary" class="purchase-order-error-summary" role="alert" tabindex="-1"><strong>Chưa thể hoàn tất.</strong> {{ formError }}</div>

        <form v-if="order.status === 'SENT'" class="purchase-order-section" @submit.prevent="acceptOrder" novalidate>
          <div class="purchase-order-section__heading"><div><h3>Phản hồi yêu cầu</h3><p class="purchase-order-help">Xác nhận số lượng có thể cung cấp và đơn giá theo từng đơn vị mua.</p></div></div>
          <div class="purchase-order-form-grid">
            <div class="purchase-order-field"><label for="supplier-delivery-date">Ngày dự kiến giao</label><input id="supplier-delivery-date" v-model="deliveryDate" class="purchase-order-input" type="date" required :disabled="busy"></div>
            <div class="purchase-order-field"><label for="supplier-accept-note">Ghi chú</label><input id="supplier-accept-note" v-model.trim="supplierNote" class="purchase-order-input" maxlength="2000" :disabled="busy"></div>
          </div>
          <div class="purchase-orders__table-wrap">
            <table class="purchase-orders__table">
              <thead><tr><th>Nguyên liệu</th><th class="is-number">Nhà hàng yêu cầu</th><th>Số chấp nhận</th><th>Đơn giá / đơn vị mua</th></tr></thead>
              <tbody><tr v-for="line in acceptLines" :key="line.id"><td><strong>{{ line.name }}</strong><br><span class="purchase-order-muted">{{ line.unit }}</span></td><td class="is-number">{{ formatQuantity(line.requested) }}</td><td><label class="sr-only" :for="`accept-quantity-${line.id}`">Số chấp nhận {{ line.name }}</label><input :id="`accept-quantity-${line.id}`" v-model.number="line.quantity" class="purchase-order-input" type="number" min="1" :max="line.requested" step="1" required :disabled="busy"></td><td><label class="sr-only" :for="`accept-price-${line.id}`">Đơn giá {{ line.name }}</label><input :id="`accept-price-${line.id}`" v-model.number="line.price" class="purchase-order-input" type="number" min="0" step="100" required :disabled="busy"></td></tr></tbody>
            </table>
          </div>
          <div class="purchase-order-actions"><button class="purchase-order-button purchase-order-button--primary" type="submit" :disabled="busy">{{ busy ? 'Đang xác nhận…' : 'Chấp nhận yêu cầu' }}</button></div>
          <fieldset class="purchase-order-reject"><legend>Không thể cung cấp đơn hàng?</legend><label for="supplier-rejection-reason">Lý do từ chối</label><textarea id="supplier-rejection-reason" v-model.trim="rejectionReason" class="purchase-order-textarea" maxlength="2000" :disabled="busy"></textarea><button class="purchase-order-button purchase-order-button--danger" type="button" :disabled="busy" @click="rejectOrder">Từ chối yêu cầu</button></fieldset>
        </form>

        <form v-else-if="order.status === 'SUPPLIER_ACCEPTED'" class="purchase-order-section" @submit.prevent="shipOrder" novalidate>
          <div class="purchase-order-section__heading"><div><h3>Xác nhận gửi hàng</h3><p class="purchase-order-help">Nhập mã chứng từ và thông tin lô trước khi chuyển hàng cho nhà hàng.</p></div></div>
          <div class="purchase-order-form-grid">
            <div class="purchase-order-field"><label for="supplier-document-code">Mã phiếu xuất / chứng từ</label><input id="supplier-document-code" v-model.trim="documentCode" class="purchase-order-input" maxlength="100" required :disabled="busy"></div>
            <div class="purchase-order-field"><label for="supplier-ship-note">Ghi chú giao hàng</label><input id="supplier-ship-note" v-model.trim="supplierNote" class="purchase-order-input" maxlength="2000" :disabled="busy"></div>
          </div>
          <div class="purchase-orders__table-wrap"><table class="purchase-orders__table"><thead><tr><th>Nguyên liệu</th><th class="is-number">Đã chấp nhận</th><th>Số gửi</th><th>Mã lô</th><th>Hạn sử dụng</th></tr></thead><tbody><tr v-for="line in shipLines" :key="line.id"><td><strong>{{ line.name }}</strong><br><span class="purchase-order-muted">{{ line.unit }}</span></td><td class="is-number">{{ formatQuantity(line.accepted) }}</td><td><label class="sr-only" :for="`ship-quantity-${line.id}`">Số gửi {{ line.name }}</label><input :id="`ship-quantity-${line.id}`" v-model.number="line.quantity" class="purchase-order-input" type="number" min="1" :max="line.accepted" step="1" required :disabled="busy"></td><td><label class="sr-only" :for="`ship-lot-${line.id}`">Mã lô {{ line.name }}</label><input :id="`ship-lot-${line.id}`" v-model.trim="line.lot" class="purchase-order-input" maxlength="60" :disabled="busy"></td><td><label class="sr-only" :for="`ship-expiry-${line.id}`">Hạn sử dụng {{ line.name }}</label><input :id="`ship-expiry-${line.id}`" v-model="line.expiry" class="purchase-order-input" type="date" :disabled="busy"></td></tr></tbody></table></div>
          <div class="purchase-order-actions"><button class="purchase-order-button purchase-order-button--primary" type="submit" :disabled="busy">{{ busy ? 'Đang ghi nhận…' : 'Xác nhận đã gửi hàng' }}</button></div>
        </form>

        <section v-else class="purchase-order-section" aria-labelledby="supplier-lines-title">
          <h3 id="supplier-lines-title">Chi tiết số lượng</h3>
          <div v-if="order.status === 'SUPPLIER_REJECTED'" class="purchase-order-error-summary"><strong>Lý do từ chối:</strong> {{ order.rejection_reason }}</div>
          <div class="purchase-orders__table-wrap"><table class="purchase-orders__table"><thead><tr><th>Nguyên liệu</th><th class="is-number">Yêu cầu</th><th class="is-number">Đã nhận</th><th class="is-number">Đã gửi</th><th class="is-number">Đơn giá</th></tr></thead><tbody><tr v-for="item in order.items" :key="item.id"><td><strong>{{ item.ingredient_name }}</strong><br><span class="purchase-order-muted">{{ item.purchase_unit }}</span></td><td class="is-number">{{ formatQuantity(item.requested_purchase_quantity) }}</td><td class="is-number">{{ formatOptional(item.supplier_accepted_quantity) }}</td><td class="is-number">{{ formatOptional(item.shipped_purchase_quantity) }}</td><td class="is-number">{{ formatMoney(item.purchase_unit_price) }}</td></tr></tbody></table></div>
        </section>
      </template>
    </div>
    <footer class="purchase-order-dialog__footer"><button class="purchase-order-button" type="button" :disabled="busy" @click="close">Đóng</button></footer>
  </dialog>
</template>

<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useAppDialog } from '@/composables/useAppDialog'
import { purchaseOrderError, supplierPurchaseOrdersApi, type PurchaseOrder, type PurchaseOrderStatus } from '@/api/purchaseOrders'

interface AcceptLine { id: number; name: string; unit: string; requested: number; quantity: number; price: number }
interface ShipLine { id: number; name: string; unit: string; accepted: number; quantity: number; lot: string; expiry: string }

const emit = defineEmits<{ updated: [order: PurchaseOrder] }>()
const appDialog = useAppDialog()
const dialog = ref<HTMLDialogElement | null>(null)
const errorSummary = ref<HTMLElement | null>(null)
const order = ref<PurchaseOrder | null>(null)
const orderId = ref<number | null>(null)
const loading = ref(false)
const busy = ref(false)
const loadError = ref('')
const formError = ref('')
const deliveryDate = ref('')
const supplierNote = ref('')
const rejectionReason = ref('')
const documentCode = ref('')
const acceptLines = ref<AcceptLine[]>([])
const shipLines = ref<ShipLine[]>([])

async function open(selected: PurchaseOrder) { orderId.value = selected.id; order.value = selected; formError.value = ''; rejectionReason.value = ''; syncForm(); dialog.value?.showModal(); await reload() }
async function reload() { if (!orderId.value) return; loading.value = true; loadError.value = ''; try { order.value = (await supplierPurchaseOrdersApi.get(orderId.value)).data.data; syncForm() } catch (error) { loadError.value = purchaseOrderError(error) } finally { loading.value = false } }
function syncForm() { if (!order.value) return; deliveryDate.value = order.value.supplier_delivery_date || order.value.requested_delivery_date || ''; supplierNote.value = order.value.supplier_note || ''; documentCode.value = order.value.supplier_document_code || ''; acceptLines.value = order.value.items.map((item) => ({ id: item.id, name: item.ingredient_name || item.ingredient_code || 'Nguyên liệu', unit: item.purchase_unit, requested: item.requested_purchase_quantity, quantity: item.supplier_accepted_quantity ?? item.requested_purchase_quantity, price: item.purchase_unit_price ?? 0 })); shipLines.value = order.value.items.map((item) => ({ id: item.id, name: item.ingredient_name || item.ingredient_code || 'Nguyên liệu', unit: item.purchase_unit, accepted: item.supplier_accepted_quantity ?? 0, quantity: item.shipped_purchase_quantity ?? item.supplier_accepted_quantity ?? 0, lot: item.supplier_lot_code || '', expiry: item.expiry_date || '' })) }
function close() { if (!busy.value) dialog.value?.close() }
function onCancel(event: Event) { if (busy.value) event.preventDefault() }
async function showError(error: unknown) { formError.value = error instanceof Error ? error.message : purchaseOrderError(error); await nextTick(); errorSummary.value?.focus() }

async function acceptOrder() {
  if (!order.value || busy.value) return
  const invalid = !deliveryDate.value || acceptLines.value.some((line) => !Number.isInteger(line.quantity) || line.quantity < 1 || line.quantity > line.requested || !Number.isFinite(line.price) || line.price < 0)
  if (invalid) return showError(new Error('Ngày giao, số chấp nhận nguyên dương không vượt yêu cầu và đơn giá không âm là bắt buộc.'))
  if (!await appDialog.confirm({ title: `Chấp nhận ${order.value.code}?`, message: 'Số lượng, đơn giá và ngày giao sẽ được gửi cho nhà hàng. Sau khi xác nhận, bạn không thể sửa phản hồi này.', confirmLabel: 'Chấp nhận yêu cầu' })) return
  busy.value = true; formError.value = ''
  try { order.value = (await supplierPurchaseOrdersApi.accept(order.value.id, { supplier_delivery_date: deliveryDate.value, supplier_note: supplierNote.value || null, items: acceptLines.value.map((line) => ({ purchase_order_item_id: line.id, supplier_accepted_quantity: line.quantity, purchase_unit_price: line.price })) })).data.data; syncForm(); emit('updated', order.value) } catch (error) { await showError(error) } finally { busy.value = false }
}

async function rejectOrder() {
  if (!order.value || busy.value) return
  if (!rejectionReason.value) return showError(new Error('Hãy nhập lý do từ chối yêu cầu.'))
  if (!await appDialog.confirm({ title: `Từ chối ${order.value.code}?`, message: 'Yêu cầu sẽ kết thúc và không thể chuyển sang gửi hàng.', confirmLabel: 'Từ chối yêu cầu' })) return
  busy.value = true; formError.value = ''
  try { order.value = (await supplierPurchaseOrdersApi.reject(order.value.id, rejectionReason.value)).data.data; syncForm(); emit('updated', order.value) } catch (error) { await showError(error) } finally { busy.value = false }
}

async function shipOrder() {
  if (!order.value || busy.value) return
  const invalid = !documentCode.value || shipLines.value.some((line) => !Number.isInteger(line.quantity) || line.quantity < 1 || line.quantity > line.accepted)
  if (invalid) return showError(new Error('Mã chứng từ và số gửi nguyên dương không vượt số đã chấp nhận là bắt buộc.'))
  if (!await appDialog.confirm({ title: `Gửi hàng cho ${order.value.code}?`, message: 'Thông tin chứng từ và số lượng gửi sẽ được chuyển cho nhà hàng để kiểm nhận.', confirmLabel: 'Xác nhận gửi hàng' })) return
  busy.value = true; formError.value = ''
  try { order.value = (await supplierPurchaseOrdersApi.ship(order.value.id, { supplier_document_code: documentCode.value, supplier_note: supplierNote.value || null, items: shipLines.value.map((line) => ({ purchase_order_item_id: line.id, shipped_purchase_quantity: line.quantity, supplier_lot_code: line.lot || null, expiry_date: line.expiry || null })) })).data.data; syncForm(); emit('updated', order.value) } catch (error) { await showError(error) } finally { busy.value = false }
}

function statusLabel(value: PurchaseOrderStatus) { return ({ DRAFT: 'Bản nháp', SENT: 'Chờ phản hồi', SUPPLIER_ACCEPTED: 'Đã chấp nhận', SUPPLIER_REJECTED: 'Đã từ chối', SHIPPED: 'Đã gửi hàng', RECEIVED: 'Nhà hàng đã nhận', CANCELLED: 'Đã hủy' } as Record<PurchaseOrderStatus, string>)[value] }
function statusTone(value: PurchaseOrderStatus) { if (value === 'RECEIVED' || value === 'SUPPLIER_ACCEPTED') return 'purchase-order-status--success'; if (value === 'SUPPLIER_REJECTED' || value === 'CANCELLED') return 'purchase-order-status--error'; return 'purchase-order-status--warning' }
function formatQuantity(value: number) { return Number(value).toLocaleString('vi-VN') }
function formatOptional(value: number | null) { return value === null ? '—' : formatQuantity(value) }
function formatMoney(value: number | null) { return value === null ? '—' : `${Number(value).toLocaleString('vi-VN')} đ` }
function formatDate(value: string | null) { return value ? new Date(`${value}T00:00:00`).toLocaleDateString('vi-VN') : 'Chưa có' }
function formatDateTime(value: string | null) { return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : 'Chưa thực hiện' }

defineExpose({ open })
</script>
