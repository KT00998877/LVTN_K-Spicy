<template>
  <div class="stock-imports-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">KHO &amp; NGUYÊN LIỆU</span>
        <h2>Phiếu nhập kho</h2>
        <p>Quản lý phiếu nhập theo trạng thái DRAFT → CONFIRMED. Chỉ phiếu đã xác nhận mới cộng tồn kho.</p>
      </div>
      <div class="header-actions">
        <button class="manager-button primary" :disabled="loading" @click="openCreate">Tạo phiếu nhập</button>
      </div>
    </header>

    <div class="metrics">
      <div><span>Tổng phiếu</span><strong>{{ total }}</strong></div>
      <div><span>Đang chờ xác nhận</span><strong>{{ draftCount }}</strong></div>
      <div><span>Đã xác nhận</span><strong>{{ confirmedCount }}</strong></div>
      <div><span>Tổng giá trị nhập</span><strong>{{ formatVnd(totalValue) }}</strong></div>
    </div>

    <div class="filters">
      <input v-model="filters.q" placeholder="Tìm mã phiếu hoặc số phiếu giao…" aria-label="Tìm mã phiếu hoặc số phiếu giao" @keydown.enter="onSearchEnter" />
      <select v-model="filters.status" aria-label="Trạng thái">
        <option value="">Tất cả trạng thái</option>
        <option value="DRAFT">DRAFT</option>
        <option value="CONFIRMED">CONFIRMED</option>
        <option value="CANCELLED">CANCELLED</option>
      </select>
      <select v-model.number="filters.ingredient_category_id" aria-label="Danh mục nguyên liệu">
        <option :value="0">Tất cả danh mục</option>
        <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <label>Từ ngày<input v-model="filters.from" type="date" /></label>
      <label>Đến ngày<input v-model="filters.to" type="date" /></label>
      <button class="manager-button" :disabled="loading" @click="onFilterChange">Lọc</button>
    </div>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>
    <template v-else-if="!error">
      <p v-if="!rows.length" class="manager-notice">Chưa có phiếu nhập nào.</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mã phiếu</th><th>Ngày trên phiếu</th><th>Nhà cung cấp</th>
              <th>Trạng thái</th><th>Tổng tiền</th><th>Số dòng</th><th>Người tạo</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in rows" :key="i.id">
              <td><strong>{{ i.code }}</strong></td>
              <td>{{ formatDate(i.imported_at) }}</td>
              <td>{{ i.supplier_name || '—' }}</td>
              <td><span class="tag" :class="statusClass(i.status)">{{ statusLabel(i.status) }}</span></td>
              <td class="number">{{ formatVnd(i.total_cost) }}</td>
              <td>{{ i.items?.length || 0 }}</td>
              <td>{{ i.imported_by_name || '—' }}</td>
              <td>
                <div class="actions">
                  <button class="manager-button" @click="openDetail(i)">Xem</button>
                  <button v-if="i.status === 'DRAFT'" class="manager-button" @click="openEdit(i)">Sửa</button>
                  <button v-if="i.status === 'DRAFT'" class="manager-button primary" :disabled="busyId === i.id" @click="openReceive(i)">
                    {{ busyId === i.id ? 'Đang xử lý…' : 'Xác nhận nhập' }}
                  </button>
                  <button v-if="i.status === 'DRAFT'" class="manager-button danger" :disabled="busyId === i.id" @click="cancelImport(i)">
                    Huỷ
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="pagination">
        <button :disabled="page <= 1" @click="changePage(page - 1)">Trước</button>
        <span>{{ page }} / {{ lastPage }}</span>
        <button :disabled="page >= lastPage" @click="changePage(page + 1)">Sau</button>
      </div>
    </template>

    <!-- Detail / edit dialog -->
    <dialog ref="dialog" @cancel="onDialogCancel">
      <form @submit.prevent="onSubmit">
        <header>
          <h3>{{ dialogMode === 'create' ? 'Tạo phiếu nhập' : dialogMode === 'edit' ? 'Sửa phiếu nhập' : 'Chi tiết phiếu nhập' }}</h3>
          <button type="button" :disabled="saving" @click="closeDialog">Đóng</button>
        </header>
        <div class="form-body">
          <p v-if="formError" class="manager-notice error" role="alert">{{ formError }}</p>
          <fieldset :disabled="dialogMode === 'view' || saving">
            <div class="row">
              <label>Mã phiếu<input v-model="form.code" :readonly="dialogMode !== 'create'" placeholder="Tự sinh nếu bỏ trống" maxlength="30" /></label>
              <label>Nhà cung cấp
                <select v-model.number="form.supplier_id">
                  <option :value="null">Chưa chọn</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </label>
            </div>
            <div class="row">
              <label>Ngày trên phiếu<input v-model="form.imported_at" type="date" required /></label>
              <label>Số phiếu giao / hóa đơn NCC<input v-model.trim="form.supplier_document_code" maxlength="100" placeholder="Dùng để tránh nhập trùng" /></label>
            </div>
            <div class="row">
              <label>Ghi chú<input v-model="form.note" maxlength="2000" /></label>
            </div>
            <div class="items-box">
              <div class="items-header">
                <strong>Danh sách nguyên liệu</strong>
                <button v-if="dialogMode !== 'view'" type="button" class="manager-button" @click="addLine">Thêm dòng</button>
              </div>
              <table v-if="form.items.length">
                <thead>
                  <tr><th>Nguyên liệu</th><th>Số lượng</th><th>Đơn giá</th><th>HSD</th><th>Thành tiền</th><th v-if="dialogMode !== 'view'"></th></tr>
                </thead>
                <template v-for="group in groupedItems" :key="group.name">
                  <tbody class="category-row">
                    <tr><th colspan="6">{{ group.name }} · {{ group.rows.length }} dòng</th></tr>
                  </tbody>
                  <tbody>
                    <tr v-for="row in group.rows" :key="row.idx">
                      <td>
                        <select v-model.number="row.line.ingredient_id" required @change="row.line.ingredient_packaging_id = null; row.line.purchase_quantity = null; row.line.purchase_unit_price = null">
                          <option :value="0" disabled>Chọn nguyên liệu</option>
                          <option v-for="i in ingredients" :key="i.id" :value="i.id" :disabled="!i.is_active">{{ i.code }} · {{ i.name }} ({{ i.unit }})</option>
                        </select>
                      </td>
                      <td>
                        <label v-if="packagings(row.line).length && dialogMode !== 'view'">Đơn vị nhập
                          <select v-model.number="row.line.ingredient_packaging_id" @change="selectPackaging(row.line)">
                            <option :value="null">{{ ingredientUnit(row.line) }}</option>
                            <option v-for="pack in packagings(row.line)" :key="pack.id" :value="pack.id">{{ pack.purchase_unit }} = {{ number(pack.conversion_quantity) }} {{ ingredientUnit(row.line) }}</option>
                          </select>
                        </label>
                        <label>{{ selectedPackaging(row.line) ? `Số ${selectedPackaging(row.line)!.purchase_unit}` : `Số ${ingredientUnit(row.line)}` }}
                          <input v-if="selectedPackaging(row.line)" v-model.number="row.line.purchase_quantity" type="number" min="0.001" step="0.001" required />
                          <input v-else v-model.number="row.line.quantity" type="number" min="0.01" step="0.01" required />
                        </label>
                        <small v-if="selectedPackaging(row.line)">= {{ number((row.line.purchase_quantity || 0) * selectedPackaging(row.line)!.conversion_quantity) }} {{ ingredientUnit(row.line) }}</small>
                      </td>
                      <td><label>{{ selectedPackaging(row.line) ? `Giá mỗi ${selectedPackaging(row.line)!.purchase_unit}` : `Giá mỗi ${ingredientUnit(row.line)}` }}
                        <input v-if="selectedPackaging(row.line)" v-model.number="row.line.purchase_unit_price" type="number" min="0" step="0.01" required />
                        <input v-else v-model.number="row.line.unit_price" type="number" min="0" step="0.01" required />
                      </label></td>
                      <td><input v-model="row.line.expiry_date" type="date" /></td>
                      <td class="number">{{ formatVnd(row.line.quantity * row.line.unit_price) }}</td>
                      <td v-if="dialogMode !== 'view'"><button type="button" class="manager-button danger" @click="removeLine(row.idx)">Xoá</button></td>
                    </tr>
                  </tbody>
                </template>
                <tfoot>
                  <tr>
                    <td colspan="4" class="right"><strong>Tổng cộng</strong></td>
                    <td class="number"><strong>{{ formatVnd(totalForm) }}</strong></td>
                    <td v-if="dialogMode !== 'view'"></td>
                  </tr>
                </tfoot>
              </table>
              <p v-else class="empty">Chưa có nguyên liệu nào. Bấm "Thêm dòng" để bắt đầu.</p>
            </div>
          </fieldset>
          <p v-if="dialogMode === 'view' && viewRow" class="status-line">
            Trạng thái: <strong>{{ statusLabel(viewRow.status) }}</strong>
            <span v-if="viewRow.confirmed_at"> · Xác nhận lúc {{ formatDateTime(viewRow.confirmed_at) }} bởi {{ viewRow.confirmed_by_name }}</span>
            <span v-if="viewRow.received_at"> · Nhận thực tế lúc {{ formatDateTime(viewRow.received_at) }}</span>
            <span v-if="viewRow.cancellation_reason"> · Lý do huỷ: {{ viewRow.cancellation_reason }}</span>
          </p>
        </div>
        <footer>
          <button type="button" class="manager-button" :disabled="saving" @click="closeDialog">Đóng</button>
          <button v-if="dialogMode !== 'view'" type="submit" class="manager-button primary" :disabled="saving">
            {{ saving ? 'Đang lưu…' : dialogMode === 'create' ? 'Tạo phiếu' : 'Cập nhật' }}
          </button>
        </footer>
      </form>
    </dialog>
    <dialog ref="receiveDialog" @cancel="onReceiveCancel">
      <form @submit.prevent="confirmImport">
        <header><h3>Xác nhận nhận hàng {{ pendingImport?.code }}</h3><button type="button" :disabled="busyId !== null" @click="receiveDialog?.close()">Đóng</button></header>
        <div class="form-body">
          <p v-if="receiveError" class="manager-notice error" role="alert">{{ receiveError }}</p>
          <label>Giờ nhận thực tế<input v-model="receivedAt" type="datetime-local" step="1" required /></label>
          <p class="help">Phiếu sẽ cộng tồn một lần khi xác nhận. Giờ nhận phải sau lần đếm kho đã chốt gần nhất.</p>
        </div>
        <footer><button type="button" class="manager-button" :disabled="busyId !== null" @click="receiveDialog?.close()">Hủy</button><button class="manager-button primary" :disabled="busyId !== null">Xác nhận nhập</button></footer>
      </form>
    </dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '@/api/client'
import { ingredientsApi, type Ingredient } from '@/api/ingredients'
import { useStockFlowStore } from '@/stores/managerStockFlow'
import { useAppDialog } from '@/composables/useAppDialog'
import { useToast } from '@/composables/useStaffUtils'
import type { StockImport, StockImportItemPayload, StockImportPayload } from '@/api/stockFlow'

const store = useStockFlowStore()
const appDialog = useAppDialog()
const toast = useToast()

const dialog = ref<HTMLDialogElement | null>(null)
const receiveDialog = ref<HTMLDialogElement | null>(null)
const pendingImport = ref<StockImport | null>(null)
const receivedAt = ref('')
const receiveError = ref('')
const dialogMode = ref<'create' | 'edit' | 'view'>('create')
const saving = ref(false)
const formError = ref('')
const viewRow = ref<StockImport | null>(null)
const busyId = ref<number | null>(null)

const ingredients = ref<Ingredient[]>([])
const suppliers = ref<{ id: number; name: string }[]>([])
const categoryOptions = ref<{ id: number; name: string }[]>([])

const filters = reactive({ q: '', status: '', from: '', to: '', ingredient_category_id: 0 })
const page = ref(1), lastPage = ref(1)
const total = computed(() => store.importsTotal)
const rows = computed(() => store.imports)
const loading = computed(() => store.importsLoading)
const error = computed(() => store.importsError)

const draftCount = computed(() => store.draftImports.length)
const confirmedCount = computed(() => store.confirmedImports.length)
const totalValue = computed(() => store.imports.reduce((s, i) => s + (i.total_cost || 0), 0))

type ImportLine = StockImportItemPayload
const form = reactive<Omit<StockImportPayload, 'items'> & { items: ImportLine[] }>({
  code: null,
  supplier_id: null,
  imported_at: new Date().toISOString().slice(0, 10),
  supplier_document_code: '',
  note: '',
  items: [],
})
const totalForm = computed(() => form.items.reduce((s, l) => s + (selectedPackaging(l)
  ? (Number(l.purchase_quantity) || 0) * (Number(l.purchase_unit_price) || 0)
  : (Number(l.quantity) || 0) * (Number(l.unit_price) || 0)), 0))
/** Nhóm items theo danh mục nguyên liệu để hiển thị trong dialog. */
const groupedItems = computed(() => {
  const order: string[] = []
  const map = new Map<string, { name: string; rows: { idx: number; line: ImportLine }[] }>()
  form.items.forEach((line, idx) => {
    const ing = ingredients.value.find(i => i.id === line.ingredient_id)
    const catName = ing?.category_name ?? 'Chưa phân loại'
    if (!map.has(catName)) { map.set(catName, { name: catName, rows: [] }); order.push(catName) }
    map.get(catName)!.rows.push({ idx, line })
  })
  return order.map(n => map.get(n)!)
})
const packagings = (line: ImportLine) => ingredients.value.find(i => i.id === line.ingredient_id)?.packagings?.filter(p => p.is_active) || []
const selectedPackaging = (line: ImportLine) => packagings(line).find(p => p.id === line.ingredient_packaging_id)
const ingredientUnit = (line: ImportLine) => ingredients.value.find(i => i.id === line.ingredient_id)?.unit || 'đơn vị'
function selectPackaging(line: ImportLine) {
  const pack = selectedPackaging(line)
  line.purchase_quantity = pack ? 1 : null
  line.purchase_unit_price = pack ? line.unit_price * pack.conversion_quantity : null
}

function statusLabel(s: string) {
  return { DRAFT: 'DRAFT', CONFIRMED: 'Đã nhập', CANCELLED: 'Đã huỷ' }[s] || s
}
function statusClass(s: string) {
  return { DRAFT: 'warn', CONFIRMED: 'ok', CANCELLED: 'danger' }[s] || ''
}

const number = (n: number | string) => Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
const formatVnd = (n: number | string) => Number(n).toLocaleString('vi-VN') + ' đ'
const formatDate = (s: string | null) => s ? new Date(s + 'T00:00:00').toLocaleDateString('vi-VN') : '—'
const formatDateTime = (s: string | null) => s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'

async function refresh() {
  const params: Record<string, any> = { page: page.value, per_page: 25 }
  for (const k of ['q', 'status', 'from', 'to'] as const) {
    if (filters[k]) params[k] = filters[k]
  }
  if (filters.ingredient_category_id) params.ingredient_category_id = filters.ingredient_category_id
  await store.fetchImports(params)
  page.value = store.imports.length ? page.value : 1
  // Last page from meta — store can store it; simplified here:
  lastPage.value = Math.max(1, Math.ceil(store.importsTotal / 25))
}

function onSearchEnter() { page.value = 1; void refresh() }
function onFilterChange() { page.value = 1; void refresh() }
function changePage(p: number) { page.value = p; void refresh() }

async function loadLookups() {
  try {
    const [ings, sup, cats] = await Promise.all([
      ingredientsApi.list({ per_page: 100 }),
      api.get<{ data: { id: number; name: string }[] }>('/staff/dashboard/ingredients').then((r) => r.data.data),
      ingredientsApi.categories(),
    ])
    ingredients.value = ings.data
    suppliers.value = sup
    categoryOptions.value = cats
  } catch (e) { console.warn(e) }
}

function resetForm() {
  Object.assign(form, {
    code: null,
    supplier_id: null,
    imported_at: new Date().toISOString().slice(0, 10),
    supplier_document_code: '',
    note: '',
    items: [],
  })
}

function addLine() {
  form.items.push({ ingredient_id: 0, ingredient_packaging_id: null, purchase_quantity: null, purchase_unit_price: null, quantity: 1, unit_price: 0, expiry_date: null, supplier_lot_code: null, note: null })
}
function removeLine(idx: number) { form.items.splice(idx, 1) }

function openCreate() {
  dialogMode.value = 'create'
  resetForm()
  if (form.items.length === 0) addLine()
  viewRow.value = null
  formError.value = ''
  dialog.value?.showModal()
}

function openEdit(row: StockImport) {
  dialogMode.value = 'edit'
  Object.assign(form, {
    code: row.code,
    supplier_id: row.supplier_id,
    imported_at: row.imported_at?.slice(0, 10) || new Date().toISOString().slice(0, 10),
    supplier_document_code: row.supplier_document_code || '',
    note: row.note,
    items: row.items.map((it) => ({
      ingredient_id: it.ingredient_id,
      ingredient_packaging_id: it.ingredient_packaging_id,
      purchase_quantity: it.purchase_quantity,
      purchase_unit_price: it.purchase_unit_price,
      quantity: it.quantity,
      unit_price: it.unit_price,
      expiry_date: it.expiry_date,
      supplier_lot_code: it.supplier_lot_code,
      note: it.note,
    })),
  })
  viewRow.value = row
  formError.value = ''
  dialog.value?.showModal()
}

function openDetail(row: StockImport) {
  dialogMode.value = 'view'
  Object.assign(form, {
    code: row.code,
    supplier_id: row.supplier_id,
    imported_at: row.imported_at?.slice(0, 10) || '',
    supplier_document_code: row.supplier_document_code || '',
    note: row.note,
    items: row.items.map((it) => ({
      ingredient_id: it.ingredient_id,
      ingredient_packaging_id: it.ingredient_packaging_id,
      purchase_quantity: it.purchase_quantity,
      purchase_unit_price: it.purchase_unit_price,
      quantity: it.quantity,
      unit_price: it.unit_price,
      expiry_date: it.expiry_date,
      supplier_lot_code: it.supplier_lot_code,
      note: it.note,
    })),
  })
  viewRow.value = row
  formError.value = ''
  dialog.value?.showModal()
}

function closeDialog() { if (!saving.value) dialog.value?.close() }
function onDialogCancel(e: Event) { if (saving.value) e.preventDefault() }

async function onSubmit() {
  if (saving.value) return
  saving.value = true
  formError.value = ''
  try {
    const validLines = form.items.filter((l) => l.ingredient_id > 0 && l.quantity > 0)
    if (!validLines.length) {
      formError.value = 'Phiếu nhập phải có ít nhất một dòng hợp lệ.'
      return
    }
    const payload: StockImportPayload = {
      code: form.code || undefined,
      supplier_id: form.supplier_id,
      imported_at: form.imported_at,
      supplier_document_code: form.supplier_document_code || null,
      note: form.note || '',
      items: validLines.map((l) => ({
        ingredient_id: l.ingredient_id,
        ingredient_packaging_id: l.ingredient_packaging_id || null,
        purchase_quantity: l.ingredient_packaging_id ? l.purchase_quantity : null,
        purchase_unit_price: l.ingredient_packaging_id ? l.purchase_unit_price : null,
        quantity: l.quantity,
        unit_price: l.unit_price,
        expiry_date: l.expiry_date || null,
        supplier_lot_code: l.supplier_lot_code || null,
        note: l.note || null,
      })),
    }
    if (dialogMode.value === 'create') {
      await store.createImport(payload)
    } else if (dialogMode.value === 'edit' && viewRow.value) {
      await store.updateImport(viewRow.value.id, payload)
    }
    dialog.value?.close()
    await refresh()
  } catch (e: any) {
    formError.value = store.msg(e)
  } finally {
    saving.value = false
  }
}

function openReceive(row: StockImport) {
  pendingImport.value = row
  const now = new Date()
  receivedAt.value = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 19)
  receiveError.value = ''
  receiveDialog.value?.showModal()
}
function onReceiveCancel(e: Event) { if (busyId.value !== null) e.preventDefault() }
async function confirmImport() {
  const row = pendingImport.value
  if (!row || !receivedAt.value || busyId.value !== null) return
  busyId.value = row.id
  receiveError.value = ''
  try {
    await store.confirmImport(row.id, receivedAt.value.replace('T', ' '))
    receiveDialog.value?.close()
    await refresh()
  } catch (e: any) {
    receiveError.value = store.msg(e)
  } finally {
    busyId.value = null
  }
}

async function cancelImport(row: StockImport) {
  if (!await appDialog.confirm({ title: `Hủy phiếu nhập ${row.code}?`, message: 'Phiếu nhập này sẽ không được xác nhận vào kho.', confirmLabel: 'Hủy phiếu nhập', tone: 'danger' })) return
  busyId.value = row.id
  try {
    await store.cancelImport(row.id)
    await refresh()
  } catch (e: any) {
    toast.error(store.msg(e))
  } finally {
    busyId.value = null
  }
}

onMounted(async () => {
  await loadLookups()
  await refresh()
})
watch(() => filters.status, () => { page.value = 1; void refresh() })
</script>

<style scoped>
.stock-imports-page { display: flex; flex-direction: column; gap: 20px; }
.metrics { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }
.metrics>div { padding: 22px; background: white; border: 1px solid var(--color-border); border-radius: 12px; display: flex; flex-direction: column; gap: 12px; }
.metrics span { font-size: 13px; color: #64748b; }
.metrics strong { font-size: 24px; color: var(--color-brand-ink); }
.filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filters>input { flex: 1; }
.filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
.table-wrap { overflow-x: auto; background: white; border: 1px solid var(--color-border); border-radius: 12px; }
table { border-collapse: collapse; width: 100%; min-width: 820px; font-size: 13px; }
th, td { text-align: left; padding: 14px; border-bottom: 1px solid #edf1ee; }
th { background: var(--color-brand-soft); color: #64748b; font-size: 12px; }
.number { font-variant-numeric: tabular-nums; }
.tag { padding: 5px 8px; border-radius: 5px; background: #edf5ef; white-space: nowrap; font-size: 12px; }
.tag.warn { color: #9a3412; background: #fff2df; }
.tag.ok { color: #0f7d34; background: #d6f3df; }
.tag.danger { color: #b42318; background: #fde7e3; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.pagination { display: flex; align-items: center; gap: 10px; justify-content: flex-end; font-size: 13px; }
dialog { width: min(820px, calc(100% - 32px)); max-height: 90vh; border: 0; border-radius: 14px; padding: 0; color: var(--color-text); font: 14px Arial, sans-serif; }
dialog::backdrop { background: color-mix(in srgb, var(--color-brand-dark) 75%, transparent); }
dialog header, dialog footer { padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid #e2e8e0; }
dialog footer { justify-content: flex-end; border-top: 1px solid #e2e8e0; border-bottom: 0; }
dialog h3 { margin: 0; }
.form-body { padding: 24px; }
fieldset { display: flex; flex-direction: column; gap: 16px; padding: 0; margin: 0; border: 0; }
fieldset label { display: flex; flex-direction: column; gap: 7px; font-size: 13px; }
fieldset .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.items-box { border: 1px solid var(--color-border); border-radius: 10px; padding: 12px; background: #fbfdfb; }
.items-header { display: flex; align-items: center; justify-content: space-between; }
.items-box table { width: 100%; }
.items-box tbody.category-row th { background: #f3f5f6; color: var(--color-brand-ink); font-size: 12px; padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.items-box tbody.category-row th::before { content: ''; }
.items-box input, .items-box select { border: 1px solid var(--color-border-strong); border-radius: 7px; padding: 8px; font: inherit; min-width: 0; box-sizing: border-box; width: 100%; }
.items-box .right { text-align: right; }
.empty { color: #64748b; font-size: 13px; margin: 8px 0; }
.status-line { background: #f3f6f4; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-top: 12px; }
input, select, textarea { border: 1px solid var(--color-border-strong); border-radius: 7px; padding: 10px; font: inherit; min-width: 0; box-sizing: border-box; }
.primary { background: var(--color-brand-action); color: white; }
.danger { color: #b42318; border-color: #f3c6c6; }
button { cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 880px) { .metrics { grid-template-columns: 1fr 1fr; } fieldset .row { grid-template-columns: 1fr; } }
</style>
