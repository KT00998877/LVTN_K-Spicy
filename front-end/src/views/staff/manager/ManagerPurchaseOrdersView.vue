<template>
  <div class="purchase-orders">
    <section class="purchase-orders__heading">
      <div>
        <h2>Yêu cầu nhập hàng</h2>
        <p>Lập yêu cầu, theo dõi Nhà cung cấp K và kiểm nhận hàng trước khi cộng tồn kho.</p>
      </div>
      <button class="purchase-order-button purchase-order-button--primary" type="button" :disabled="ingredientsLoading" @click="openCreate">
        Tạo yêu cầu
      </button>
    </section>

    <div v-if="actionError" class="purchase-order-error-summary" role="alert">
      <strong>Thao tác chưa hoàn tất.</strong> {{ actionError }}
    </div>

    <section class="purchase-orders__toolbar" aria-label="Bộ lọc yêu cầu nhập hàng">
      <div class="purchase-order-field purchase-order-field--grow">
        <label for="purchase-order-search">Tìm theo mã yêu cầu hoặc chứng từ</label>
        <input id="purchase-order-search" v-model.trim="filters.q" class="purchase-order-input" type="search" placeholder="Ví dụ: PO-20261026 hoặc PXK-001" @keyup.enter="applyFilters" />
      </div>
      <div class="purchase-order-field purchase-order-field--compact">
        <label for="purchase-order-status">Trạng thái</label>
        <select id="purchase-order-status" v-model="filters.status" class="purchase-order-select" @change="applyFilters">
          <option value="">Tất cả trạng thái</option>
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </div>
      <button class="purchase-order-button" type="button" :disabled="loading" @click="applyFilters">Lọc danh sách</button>
    </section>

    <div v-if="loading" class="purchase-order-state" role="status">
      <div><span class="purchase-order-spinner" aria-hidden="true"></span><p>Đang tải yêu cầu nhập hàng…</p></div>
    </div>
    <div v-else-if="listError" class="purchase-order-state purchase-order-state--error" role="alert">
      <div><p>{{ listError }}</p><button class="purchase-order-button" type="button" @click="fetchOrders">Thử lại</button></div>
    </div>
    <div v-else-if="orders.length === 0" class="purchase-order-state">
      <div>
        <strong>Chưa có yêu cầu phù hợp.</strong>
        <p>{{ hasFilters ? 'Hãy thay đổi bộ lọc để xem yêu cầu khác.' : 'Tạo yêu cầu đầu tiên để gửi Nhà cung cấp K.' }}</p>
      </div>
    </div>
    <template v-else>
      <div class="purchase-orders__table-wrap">
        <table class="purchase-orders__table">
          <thead>
            <tr>
              <th>Mã yêu cầu</th>
              <th>Nhà cung cấp</th>
              <th>Ngày cần giao</th>
              <th class="is-number">Số dòng</th>
              <th>Trạng thái</th>
              <th>Phiếu nhập</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in orders" :key="row.id">
              <td><strong>{{ row.code }}</strong><br><span class="purchase-order-muted">{{ formatDateTime(row.created_at) }}</span></td>
              <td>{{ row.supplier_name }}</td>
              <td>{{ formatDate(row.requested_delivery_date) }}</td>
              <td class="is-number">{{ row.items.length }}</td>
              <td><span class="purchase-order-status" :class="statusTone(row.status)">{{ statusLabel(row.status) }}</span></td>
              <td>
                <RouterLink v-if="row.stock_import" class="purchase-order-link" :to="{ name: 'manager-stock-imports', query: { q: row.stock_import.code } }">
                  {{ row.stock_import.code }}
                </RouterLink>
                <span v-else>—</span>
              </td>
              <td>
                <div class="purchase-order-actions">
                  <button class="purchase-order-button purchase-order-button--quiet" type="button" @click="openDetail(row)">Xem</button>
                  <button v-if="row.status === 'DRAFT'" class="purchase-order-button purchase-order-button--quiet" type="button" :disabled="busyId === row.id" @click="openEdit(row)">Sửa</button>
                  <button v-if="row.status === 'DRAFT'" class="purchase-order-button purchase-order-button--quiet purchase-order-button--primary" type="button" :disabled="busyId === row.id" @click="sendOrder(row)">
                    {{ busyId === row.id ? 'Đang gửi…' : 'Gửi NCC' }}
                  </button>
                  <button v-if="row.status === 'DRAFT' || row.status === 'SENT'" class="purchase-order-button purchase-order-button--quiet purchase-order-button--danger" type="button" :disabled="busyId === row.id" @click="cancelOrder(row)">Hủy</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="meta.last_page > 1" class="purchase-orders__toolbar" aria-label="Phân trang yêu cầu nhập hàng">
        <span class="purchase-order-muted">Trang {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} yêu cầu</span>
        <div class="purchase-order-actions">
          <button class="purchase-order-button purchase-order-button--quiet" type="button" :disabled="meta.current_page <= 1" @click="changePage(meta.current_page - 1)">Trang trước</button>
          <button class="purchase-order-button purchase-order-button--quiet" type="button" :disabled="meta.current_page >= meta.last_page" @click="changePage(meta.current_page + 1)">Trang sau</button>
        </div>
      </nav>
    </template>

    <PurchaseOrderFormDialog ref="formDialog" :ingredients="ingredients" @saved="handleUpdated" />
    <PurchaseOrderDetailDialog ref="detailDialog" @updated="handleUpdated" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ingredientsApi, type Ingredient } from '@/api/ingredients'
import {
  purchaseOrderError,
  purchaseOrdersApi,
  type PurchaseOrder,
  type PurchaseOrderStatus,
} from '@/api/purchaseOrders'
import { useAppDialog } from '@/composables/useAppDialog'
import PurchaseOrderDetailDialog from '@/components/manager/purchase-orders/PurchaseOrderDetailDialog.vue'
import PurchaseOrderFormDialog from '@/components/manager/purchase-orders/PurchaseOrderFormDialog.vue'
import '@/assets/purchase-orders.css'

type FormDialog = InstanceType<typeof PurchaseOrderFormDialog>
type DetailDialog = InstanceType<typeof PurchaseOrderDetailDialog>

const appDialog = useAppDialog()
const route = useRoute()
const formDialog = ref<FormDialog | null>(null)
const detailDialog = ref<DetailDialog | null>(null)
const orders = ref<PurchaseOrder[]>([])
const ingredients = ref<Ingredient[]>([])
const loading = ref(false)
const ingredientsLoading = ref(false)
const busyId = ref<number | null>(null)
const listError = ref('')
const actionError = ref('')
const filters = reactive({ q: typeof route.query.q === 'string' ? route.query.q : '', status: '', page: 1 })
const meta = reactive({ current_page: 1, per_page: 25, total: 0, last_page: 1 })

const statusOptions: Array<{ value: PurchaseOrderStatus; label: string }> = [
  { value: 'DRAFT', label: 'Bản nháp' },
  { value: 'SENT', label: 'Chờ nhà cung cấp' },
  { value: 'SUPPLIER_ACCEPTED', label: 'Nhà cung cấp chấp nhận' },
  { value: 'SUPPLIER_REJECTED', label: 'Nhà cung cấp từ chối' },
  { value: 'SHIPPED', label: 'Đang giao hàng' },
  { value: 'RECEIVED', label: 'Đã nhập kho' },
  { value: 'CANCELLED', label: 'Đã hủy' },
]

const hasFilters = computed(() => Boolean(filters.q || filters.status))

async function fetchOrders() {
  loading.value = true
  listError.value = ''
  try {
    const response = await purchaseOrdersApi.list({
      q: filters.q || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: meta.per_page,
    })
    orders.value = response.data.data
    Object.assign(meta, response.data.meta)
  } catch (error) {
    listError.value = purchaseOrderError(error)
  } finally {
    loading.value = false
  }
}

async function loadIngredients() {
  ingredientsLoading.value = true
  try {
    const all: Ingredient[] = []
    let page = 1
    let lastPage = 1
    do {
      const response = await ingredientsApi.list({ is_active: true, per_page: 100, page })
      all.push(...response.data)
      lastPage = response.meta.last_page
      page++
    } while (page <= lastPage)
    ingredients.value = all
  } catch (error) {
    actionError.value = `Không tải được nguyên liệu. ${purchaseOrderError(error)}`
  } finally {
    ingredientsLoading.value = false
  }
}

function applyFilters() {
  filters.page = 1
  void fetchOrders()
}

function changePage(page: number) {
  filters.page = page
  void fetchOrders()
}

function openCreate() {
  actionError.value = ''
  formDialog.value?.open(null)
}

function openEdit(order: PurchaseOrder) {
  actionError.value = ''
  formDialog.value?.open(order)
}

function openDetail(order: PurchaseOrder) {
  actionError.value = ''
  void detailDialog.value?.open(order)
}

async function sendOrder(order: PurchaseOrder) {
  const confirmed = await appDialog.confirm({
    title: `Gửi yêu cầu ${order.code}?`,
    message: 'Sau khi gửi, nội dung yêu cầu sẽ không thể sửa. Nhà cung cấp K sẽ nhìn thấy yêu cầu này.',
    confirmLabel: 'Gửi nhà cung cấp',
  })
  if (!confirmed) return
  await runAction(order.id, () => purchaseOrdersApi.send(order.id))
}

async function cancelOrder(order: PurchaseOrder) {
  const confirmed = await appDialog.confirm({
    title: `Hủy yêu cầu ${order.code}?`,
    message: 'Yêu cầu sẽ chuyển sang trạng thái đã hủy và không thể tiếp tục xử lý.',
    confirmLabel: 'Hủy yêu cầu',
    tone: 'danger',
  })
  if (!confirmed) return
  await runAction(order.id, () => purchaseOrdersApi.cancel(order.id))
}

async function runAction(id: number, action: () => ReturnType<typeof purchaseOrdersApi.send>) {
  busyId.value = id
  actionError.value = ''
  try {
    handleUpdated((await action()).data.data)
  } catch (error) {
    actionError.value = purchaseOrderError(error)
  } finally {
    busyId.value = null
  }
}

function handleUpdated(order: PurchaseOrder) {
  const index = orders.value.findIndex((item) => item.id === order.id)
  if (index >= 0) orders.value[index] = order
  else orders.value.unshift(order)
}

function statusLabel(status: PurchaseOrderStatus) {
  return statusOptions.find((option) => option.value === status)?.label ?? status
}

function statusTone(status: PurchaseOrderStatus) {
  if (status === 'RECEIVED' || status === 'SUPPLIER_ACCEPTED') return 'purchase-order-status--success'
  if (status === 'SUPPLIER_REJECTED' || status === 'CANCELLED') return 'purchase-order-status--error'
  if (status === 'SENT' || status === 'SHIPPED') return 'purchase-order-status--warning'
  return ''
}

function formatDate(value: string | null) {
  return value ? new Date(`${value}T00:00:00`).toLocaleDateString('vi-VN') : 'Chưa đặt ngày'
}

function formatDateTime(value: string | null) {
  return value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
}

onMounted(() => {
  void Promise.all([fetchOrders(), loadIngredients()])
})
</script>
