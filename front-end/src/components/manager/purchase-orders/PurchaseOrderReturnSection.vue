<template>
  <section class="purchase-order-section" aria-labelledby="po-return-title">
    <div class="purchase-order-section__heading">
      <div><h3 id="po-return-title">Yêu cầu trả hàng</h3><p class="purchase-order-help">Gửi NCC xác nhận trước. Tồn kho chỉ giảm khi bạn bàn giao hàng.</p></div>
    </div>

    <div v-if="error" ref="errorSummary" class="purchase-order-error-summary" role="alert" tabindex="-1"><strong>Chưa thể xử lý yêu cầu.</strong> {{ error }}</div>

    <form v-if="returnableLines.length" class="purchase-order-return-form" @submit.prevent="submitReturn" novalidate>
      <div class="purchase-order-field"><label for="supplier-return-reason">Lý do trả hàng</label><textarea id="supplier-return-reason" v-model.trim="reason" class="purchase-order-textarea" maxlength="2000" required :disabled="busy" aria-describedby="supplier-return-help"></textarea><p id="supplier-return-help" class="purchase-order-help">NCC sẽ thấy lý do và số lượng yêu cầu trả.</p></div>
      <div class="purchase-orders__table-wrap"><table class="purchase-orders__table"><thead><tr><th>Nguyên liệu</th><th class="is-number">Đã nhập</th><th class="is-number">Đã trả</th><th class="is-number">Còn có thể trả</th><th>Số lượng trả</th></tr></thead><tbody><tr v-for="line in returnableLines" :key="line.id"><td><strong>{{ line.ingredient_name }}</strong><br><span class="purchase-order-muted">Đơn vị tồn: {{ line.unit }}</span></td><td class="is-number">{{ formatQuantity(line.quantity) }}</td><td class="is-number">{{ formatQuantity(line.returned_quantity) }}</td><td class="is-number">{{ formatQuantity(line.returnable_quantity) }}</td><td><label class="sr-only" :for="`return-quantity-${line.id}`">Số lượng trả {{ line.ingredient_name }}</label><input :id="`return-quantity-${line.id}`" v-model.number="quantities[line.id]" class="purchase-order-input" type="number" min="0" :max="line.returnable_quantity" step="1" :disabled="busy"></td></tr></tbody></table></div>
      <div class="purchase-order-actions"><button class="purchase-order-button purchase-order-button--primary" type="submit" :disabled="busy">{{ busy ? 'Đang gửi…' : 'Gửi yêu cầu trả' }}</button></div>
    </form>
    <p v-else class="purchase-order-help">Không còn số lượng nào từ phiếu nhập này có thể trả.</p>

    <div v-if="requests.length" class="purchase-order-return-history">
      <h3>Tiến trình yêu cầu</h3>
      <article v-for="item in requests" :key="item.id" class="purchase-order-return-record">
        <strong>{{ item.code }}</strong> · {{ statusLabel(item.status) }}
        <p class="purchase-order-help">{{ formatDateTime(item.requested_at) }} · {{ item.reason }}</p>
        <p v-if="item.rejection_reason"><strong>Lý do từ chối:</strong> {{ item.rejection_reason }}</p>
        <ul><li v-for="line in item.items" :key="line.stock_import_item_id">{{ line.ingredient_name }}: {{ formatQuantity(line.quantity) }} {{ line.unit }}</li></ul>
        <button v-if="item.status === 'ACCEPTED'" class="purchase-order-button purchase-order-button--danger" type="button" :disabled="busy" @click="dispatch(item)">Xác nhận đã bàn giao</button>
        <p v-if="item.status === 'DISPATCHED'" class="purchase-order-help">Đã trừ tồn kho; đang chờ NCC xác nhận nhận hàng.</p>
      </article>
    </div>

    <div v-if="returns.length" class="purchase-order-return-history">
      <article v-for="supplierReturn in returns" :key="supplierReturn.id" class="purchase-order-return-record">
        <div class="purchase-order-section__heading"><div><strong>{{ supplierReturn.code }}</strong><p class="purchase-order-help">{{ formatDateTime(supplierReturn.returned_at) }} · {{ supplierReturn.returned_by_name || 'Quản lý' }}</p></div><RouterLink class="purchase-order-link" :to="{ name: 'manager-stock-transactions', query: { reference_type: 'supplier_return', reference_id: supplierReturn.id } }">Xem giao dịch kho</RouterLink></div>
        <p><strong>Lý do:</strong> {{ supplierReturn.reason }}</p>
        <ul><li v-for="item in supplierReturn.items" :key="item.id">{{ item.ingredient_name }}: {{ formatQuantity(item.quantity) }} · {{ formatMoney(item.subtotal) }}</li></ul>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAppDialog } from '@/composables/useAppDialog'
import { purchaseOrderError, purchaseOrdersApi, type PurchaseOrder } from '@/api/purchaseOrders'
import { managerReturnRequestsApi, type ReturnRequest, type ReturnRequestStatus } from '@/api/returnRequests'

const props = defineProps<{ order: PurchaseOrder }>()
const emit = defineEmits<{ updated: [order: PurchaseOrder] }>()
const appDialog = useAppDialog()
const reason = ref('')
const busy = ref(false)
const error = ref('')
const errorSummary = ref<HTMLElement | null>(null)
const requestKey = ref(crypto.randomUUID())
const quantities = reactive<Record<number, number>>({})
const returns = computed(() => props.order.supplier_returns ?? [])
const requests = ref<ReturnRequest[]>([])
const activeRequests = computed(() => requests.value.filter((item) => item.status === 'REQUESTED' || item.status === 'ACCEPTED'))
const returnableLines = computed(() => (props.order.stock_import?.items ?? []).map((item) => ({
  ...item,
  returnable_quantity: Math.max(0, item.returnable_quantity - activeRequests.value.flatMap((request) => request.items).filter((line) => line.stock_import_item_id === item.id).reduce((sum, line) => sum + line.quantity, 0)),
})).filter((item) => item.returnable_quantity > 0))

async function loadRequests() {
  try { requests.value = (await managerReturnRequestsApi.list()).data.data.filter((item) => item.purchase_order_id === props.order.id) }
  catch (requestError) { await showError(purchaseOrderError(requestError)) }
}
watch(() => props.order.id, () => { void loadRequests() }, { immediate: true })

watch(returnableLines, (lines) => { for (const line of lines) if (!(line.id in quantities)) quantities[line.id] = 0 }, { immediate: true })

async function submitReturn() {
  if (busy.value) return
  const items = returnableLines.value.filter((line) => Number(quantities[line.id]) > 0).map((line) => ({ stock_import_item_id: line.id, quantity: Number(quantities[line.id]) }))
  if (!reason.value) return showError('Hãy nhập lý do trả hàng.')
  const invalid = items.length === 0 || items.some((item) => !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > (returnableLines.value.find((line) => line.id === item.stock_import_item_id)?.returnable_quantity ?? 0))
  if (invalid) return showError('Chọn ít nhất một dòng và nhập số lượng nguyên không vượt lượng còn có thể trả.')
  const total = items.reduce((sum, item) => sum + item.quantity, 0)
  if (!await appDialog.confirm({ title: `Gửi yêu cầu trả từ ${props.order.stock_import?.code}?`, message: `Gửi ${formatQuantity(total)} đơn vị tồn kho cho NCC xem xét. Tồn kho chưa thay đổi.`, confirmLabel: 'Gửi yêu cầu' })) return

  busy.value = true; error.value = ''
  try {
    const created = (await managerReturnRequestsApi.create(props.order.id, { request_key: requestKey.value, reason: reason.value, items })).data.data
    requests.value.unshift(created)
    reason.value = ''
    for (const key of Object.keys(quantities)) quantities[Number(key)] = 0
    requestKey.value = crypto.randomUUID()
  } catch (requestError) {
    await showError(purchaseOrderError(requestError))
  } finally { busy.value = false }
}

async function dispatch(item: ReturnRequest) {
  if (!await appDialog.confirm({ title: `Bàn giao ${item.code}?`, message: 'Hệ thống sẽ tạo phiếu trả và trừ tồn kho ngay khi xác nhận bàn giao.', confirmLabel: 'Đã bàn giao' })) return
  busy.value = true; error.value = ''
  try {
    const updated = (await managerReturnRequestsApi.dispatch(item.id)).data.data
    requests.value = requests.value.map((current) => current.id === updated.id ? updated : current)
    emit('updated', (await purchaseOrdersApi.get(props.order.id)).data.data)
  } catch (requestError) { await showError(purchaseOrderError(requestError)) }
  finally { busy.value = false }
}

function statusLabel(status: ReturnRequestStatus) {
  return ({ REQUESTED: 'Chờ NCC phản hồi', ACCEPTED: 'NCC đã chấp nhận', REJECTED: 'NCC từ chối', DISPATCHED: 'Đã bàn giao', RECEIVED: 'NCC đã nhận' } as Record<ReturnRequestStatus, string>)[status]
}

async function showError(message: string) { error.value = message; await nextTick(); errorSummary.value?.focus() }
function formatQuantity(value: number) { return Number(value).toLocaleString('vi-VN') }
function formatMoney(value: number) { return `${Number(value).toLocaleString('vi-VN')} đ` }
function formatDateTime(value: string | null) { return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—' }
</script>
