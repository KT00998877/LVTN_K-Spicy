<template>
  <section class="purchase-orders" aria-labelledby="return-requests-title">
    <header class="purchase-orders__heading">
      <div><h2 id="return-requests-title">Yêu cầu trả hàng</h2><p>Kiểm tra hàng nhà hàng muốn trả, phản hồi và xác nhận khi đã nhận hàng.</p></div>
      <button class="purchase-order-button" type="button" :disabled="loading" @click="load">Tải lại</button>
    </header>
    <p v-if="error" class="purchase-order-error-summary" role="alert">{{ error }}</p>
    <p v-if="loading" class="purchase-order-state" role="status">Đang tải yêu cầu trả hàng…</p>
    <p v-else-if="!requests.length" class="purchase-order-state">Chưa có yêu cầu trả hàng từ nhà hàng.</p>
    <article v-for="item in requests" :key="item.id" class="purchase-order-return-record">
      <div class="purchase-order-section__heading">
        <div><h3>{{ item.code }}</h3><p class="purchase-order-help">Đơn {{ item.purchase_order_code }} · {{ formatDateTime(item.requested_at) }}</p></div>
        <span class="purchase-order-status" :class="item.status === 'REJECTED' ? 'purchase-order-status--error' : item.status === 'RECEIVED' ? 'purchase-order-status--success' : 'purchase-order-status--warning'">{{ statusLabel(item.status) }}</span>
      </div>
      <p><strong>Lý do trả:</strong> {{ item.reason }}</p>
      <ul><li v-for="line in item.items" :key="line.stock_import_item_id">{{ line.ingredient_name }}: {{ formatQuantity(line.quantity) }} {{ line.unit }}</li></ul>
      <ol class="purchase-order-timeline purchase-order-timeline--supplier" aria-label="Tiến trình trả hàng">
        <li class="is-complete"><strong>Quản lý yêu cầu</strong><span>{{ formatDateTime(item.requested_at) }}</span></li>
        <li :class="{ 'is-complete': !!item.supplier_responded_at }"><strong>NCC phản hồi</strong><span>{{ formatDateTime(item.supplier_responded_at) }}</span></li>
        <li :class="{ 'is-complete': !!item.dispatched_at }"><strong>Quản lý bàn giao</strong><span>{{ formatDateTime(item.dispatched_at) }}</span></li>
        <li :class="{ 'is-complete': !!item.received_at }"><strong>NCC nhận hàng</strong><span>{{ formatDateTime(item.received_at) }}</span></li>
      </ol>
      <p v-if="item.rejection_reason"><strong>Lý do từ chối:</strong> {{ item.rejection_reason }}</p>
      <div v-if="item.status === 'REQUESTED'" class="purchase-order-actions">
        <button class="purchase-order-button purchase-order-button--primary" type="button" :disabled="busyId === item.id" @click="accept(item)">Chấp nhận trả hàng</button>
        <button class="purchase-order-button purchase-order-button--danger" type="button" :disabled="busyId === item.id" @click="reject(item)">Từ chối</button>
      </div>
      <button v-if="item.status === 'DISPATCHED'" class="purchase-order-button purchase-order-button--primary" type="button" :disabled="busyId === item.id" @click="receive(item)">Xác nhận đã nhận hàng</button>
    </article>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { purchaseOrderError } from '@/api/purchaseOrders'
import { supplierReturnRequestsApi, type ReturnRequest, type ReturnRequestStatus } from '@/api/returnRequests'
import { useAppDialog } from '@/composables/useAppDialog'
import '@/assets/purchase-orders.css'

const appDialog = useAppDialog()
const requests = ref<ReturnRequest[]>([])
const loading = ref(false)
const busyId = ref<number | null>(null)
const error = ref('')

async function load() {
  loading.value = true; error.value = ''
  try { requests.value = (await supplierReturnRequestsApi.list()).data.data }
  catch (requestError) { error.value = purchaseOrderError(requestError) }
  finally { loading.value = false }
}

async function act(item: ReturnRequest, action: () => Promise<{ data: { data: ReturnRequest } }>) {
  busyId.value = item.id; error.value = ''
  try {
    const updated = (await action()).data.data
    requests.value = requests.value.map((current) => current.id === updated.id ? updated : current)
  } catch (requestError) { error.value = purchaseOrderError(requestError) }
  finally { busyId.value = null }
}

async function accept(item: ReturnRequest) {
  if (!await appDialog.confirm({ title: `Chấp nhận ${item.code}?`, message: 'Nhà hàng sẽ được phép bàn giao số hàng ghi trong yêu cầu.', confirmLabel: 'Chấp nhận' })) return
  await act(item, () => supplierReturnRequestsApi.accept(item.id))
}
async function reject(item: ReturnRequest) {
  const reason = await appDialog.prompt({ title: `Từ chối ${item.code}?`, message: 'Nhập lý do để nhà hàng biết vì sao yêu cầu không được chấp nhận.', label: 'Lý do từ chối', confirmLabel: 'Từ chối' })
  if (!reason?.trim()) return
  await act(item, () => supplierReturnRequestsApi.reject(item.id, reason.trim()))
}
async function receive(item: ReturnRequest) {
  if (!await appDialog.confirm({ title: `Đã nhận ${item.code}?`, message: 'Xác nhận bạn đã nhận đủ số hàng nhà hàng bàn giao.', confirmLabel: 'Đã nhận hàng' })) return
  await act(item, () => supplierReturnRequestsApi.receive(item.id))
}
function statusLabel(status: ReturnRequestStatus) {
  return ({ REQUESTED: 'Chờ phản hồi', ACCEPTED: 'Đã chấp nhận', REJECTED: 'Đã từ chối', DISPATCHED: 'Nhà hàng đã bàn giao', RECEIVED: 'Đã nhận hàng' } as Record<ReturnRequestStatus, string>)[status]
}
function formatQuantity(value: number) { return Number(value).toLocaleString('vi-VN') }
function formatDateTime(value: string | null) { return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—' }
onMounted(load)
</script>
