<template>
  <section class="purchase-orders" aria-labelledby="supplier-orders-title">
    <header class="purchase-orders__heading">
      <div>
        <h2 id="supplier-orders-title">Yêu cầu từ K-SPICY</h2>
        <p>Kiểm tra yêu cầu, phản hồi khả năng cung cấp và theo dõi đến khi nhà hàng nhận hàng.</p>
      </div>
    </header>

    <div class="purchase-orders__toolbar">
      <div class="purchase-order-field purchase-order-field--compact">
        <label for="supplier-order-status">Trạng thái</label>
        <select id="supplier-order-status" v-model="status" class="purchase-order-select" :disabled="loading" @change="load(1)">
          <option value="">Tất cả yêu cầu</option>
          <option value="SENT">Chờ phản hồi</option>
          <option value="SUPPLIER_ACCEPTED">Đã chấp nhận</option>
          <option value="SUPPLIER_REJECTED">Đã từ chối</option>
          <option value="SHIPPED">Đã gửi hàng</option>
          <option value="RECEIVED">Nhà hàng đã nhận</option>
        </select>
      </div>
      <button class="purchase-order-button" type="button" :disabled="loading" @click="load(page)">
        {{ loading ? 'Đang tải…' : 'Tải lại' }}
      </button>
    </div>

    <div class="sr-only" aria-live="polite">{{ liveMessage }}</div>
    <div v-if="loading" class="purchase-order-state" role="status">
      <div><span class="purchase-order-spinner" aria-hidden="true"></span><p>Đang tải yêu cầu nhập hàng…</p></div>
    </div>
    <div v-else-if="error" class="purchase-order-state purchase-order-state--error" role="alert">
      <div><p>{{ error }}</p><button class="purchase-order-button" type="button" @click="load(page)">Thử lại</button></div>
    </div>
    <div v-else-if="orders.length === 0" class="purchase-order-state">
      <p>{{ status ? 'Không có yêu cầu ở trạng thái đã chọn. Chọn “Tất cả yêu cầu” để xem những đơn khác.' : 'Chưa có yêu cầu nào từ K-SPICY. Khi quản lý gửi đơn, yêu cầu sẽ xuất hiện tại đây.' }}</p>
    </div>
    <div v-else class="purchase-orders__table-wrap">
      <table class="purchase-orders__table">
        <thead><tr><th>Mã yêu cầu</th><th>Ngày gửi</th><th>Ngày cần hàng</th><th class="is-number">Dòng hàng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id">
            <td><strong>{{ order.code }}</strong><br><span class="purchase-order-muted">K-SPICY</span></td>
            <td>{{ formatDateTime(order.sent_at) }}</td>
            <td>{{ formatDate(order.requested_delivery_date) }}</td>
            <td class="is-number">{{ order.items.length }}</td>
            <td><span class="purchase-order-status" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span><br><span class="purchase-order-muted">{{ formatDateTime(statusTime(order)) }}</span><br><span v-if="order.return_count" class="purchase-order-return-alert">{{ order.return_count }} phiếu trả hàng</span></td>
            <td><button class="purchase-order-button purchase-order-button--quiet" type="button" @click="detail?.open(order)">{{ order.status === 'SENT' ? 'Kiểm tra và phản hồi' : order.status === 'SUPPLIER_ACCEPTED' ? 'Xác nhận gửi hàng' : 'Xem chi tiết' }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="lastPage > 1" class="purchase-order-pagination" aria-label="Phân trang yêu cầu nhập hàng">
      <button class="purchase-order-button" type="button" :disabled="page <= 1 || loading" @click="load(page - 1)">Trang trước</button>
      <span>Trang {{ page }} / {{ lastPage }}</span>
      <button class="purchase-order-button" type="button" :disabled="page >= lastPage || loading" @click="load(page + 1)">Trang sau</button>
    </nav>

    <SupplierPurchaseOrderDialog ref="detail" @updated="onUpdated" />
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import '@/assets/purchase-orders.css'
import SupplierPurchaseOrderDialog from '@/components/supplier/SupplierPurchaseOrderDialog.vue'
import { purchaseOrderError, supplierPurchaseOrdersApi, type PurchaseOrder, type PurchaseOrderStatus } from '@/api/purchaseOrders'

const orders = ref<PurchaseOrder[]>([])
const status = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const liveMessage = ref('')
const detail = ref<InstanceType<typeof SupplierPurchaseOrderDialog> | null>(null)

async function load(targetPage = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await supplierPurchaseOrdersApi.list({ page: targetPage, per_page: 20, ...(status.value ? { status: status.value } : {}) })
    if (targetPage > response.data.meta.last_page) {
      await load(response.data.meta.last_page)
      return
    }
    orders.value = response.data.data
    page.value = response.data.meta.current_page
    lastPage.value = response.data.meta.last_page
    liveMessage.value = `Đã tải ${response.data.meta.total} yêu cầu.`
  } catch (requestError) {
    error.value = purchaseOrderError(requestError)
  } finally {
    loading.value = false
  }
}

function onUpdated(updated: PurchaseOrder) {
  liveMessage.value = `Đã cập nhật ${updated.code}: ${statusLabel(updated.status)}.`
  void load(page.value)
}

function statusTime(order: PurchaseOrder) {
  if (order.status === 'RECEIVED') return order.received_at
  if (order.status === 'SHIPPED') return order.shipped_at
  if (order.status === 'SUPPLIER_ACCEPTED' || order.status === 'SUPPLIER_REJECTED') return order.supplier_responded_at
  return order.sent_at
}

function statusLabel(value: PurchaseOrderStatus) {
  return ({ DRAFT: 'Bản nháp', SENT: 'Chờ phản hồi', SUPPLIER_ACCEPTED: 'Đã chấp nhận', SUPPLIER_REJECTED: 'Đã từ chối', SHIPPED: 'Đã gửi hàng', RECEIVED: 'Nhà hàng đã nhận', CANCELLED: 'Đã hủy' } as Record<PurchaseOrderStatus, string>)[value]
}

function statusTone(value: PurchaseOrderStatus) {
  if (value === 'RECEIVED' || value === 'SUPPLIER_ACCEPTED') return 'purchase-order-status--success'
  if (value === 'SUPPLIER_REJECTED' || value === 'CANCELLED') return 'purchase-order-status--error'
  return 'purchase-order-status--warning'
}

function formatDate(value: string | null) {
  return value ? new Date(`${value}T00:00:00`).toLocaleDateString('vi-VN') : 'Không yêu cầu'
}
function formatDateTime(value: string | null) {
  return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
}

onMounted(() => load())
</script>
