<template>
  <div class="stocktake-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">KHO &amp; NGUYÊN LIỆU</span>
        <h2>Kiểm kê kho</h2>
        <p>Kiểm đếm đột xuất để đối chiếu. Phiếu này không đổi tồn kho; số bếp chốt trên sổ ngày là tồn thực tế.</p>
      </div>
      <button class="manager-button primary" :disabled="loading" @click="openCreate">Tạo phiên kiểm kê</button>
    </header>

    <div class="metrics">
      <div><span>Tổng phiên</span><strong>{{ total }}</strong></div>
      <div><span>Đang kiểm đếm</span><strong>{{ openCount }}</strong></div>
      <div><span>Đã hoàn tất</span><strong>{{ completedCount }}</strong></div>
      <div><span>Tổng chênh lệch giá trị</span><strong :class="totalDiffClass">{{ formatVnd(totalDiff) }}</strong></div>
    </div>

    <div class="filters">
      <select v-model="filters.status" aria-label="Trạng thái">
        <option value="">Tất cả trạng thái</option>
        <option value="OPEN">OPEN</option>
        <option value="COMPLETED">COMPLETED</option>
        <option value="CANCELLED">CANCELLED</option>
      </select>
      <label>Từ ngày<input v-model="filters.from" type="date" /></label>
      <label>Đến ngày<input v-model="filters.to" type="date" /></label>
      <button class="manager-button" :disabled="loading" @click="refresh()">Lọc</button>
    </div>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải…</p>
    <template v-else-if="!error">
      <p v-if="!rows.length" class="manager-notice">Chưa có phiên kiểm kê nào.</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr><th>Mã phiếu</th><th>Ngày kiểm</th><th>Trạng thái</th><th>Số dòng</th><th>Chênh lệch giá trị</th><th>Hoàn tất bởi</th><th>Thao tác</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in rows" :key="s.id">
              <td><strong>{{ s.code }}</strong></td>
              <td>{{ formatDate(s.counted_at) }}</td>
              <td><span class="tag" :class="statusClass(s.status)">{{ statusLabel(s.status) }}</span></td>
              <td>{{ s.items?.length || 0 }}</td>
              <td class="number"><span :class="diffClass(s.total_difference_value)">{{ formatVnd(s.total_difference_value) }}</span></td>
              <td>{{ s.completed_by_name || '—' }}</td>
              <td>
                <div class="actions">
                  <button class="manager-button" @click="openDetail(s)">Xem</button>
                  <button v-if="s.status === 'OPEN'" class="manager-button primary" :disabled="busyId === s.id" @click="completeOne(s)">Hoàn tất</button>
                  <button v-if="s.status === 'OPEN'" class="manager-button danger" :disabled="busyId === s.id" @click="cancelOne(s)">Huỷ</button>
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

    <dialog ref="dialog" @cancel="onDialogCancel">
      <form @submit.prevent="onSubmit">
        <header>
          <h3>{{ dialogMode === 'create' ? 'Tạo phiên kiểm kê' : 'Chi tiết phiên kiểm kê' }}</h3>
          <button type="button" :disabled="saving" @click="closeDialog">Đóng</button>
        </header>
        <div class="form-body">
          <p v-if="formError" class="manager-notice error" role="alert">{{ formError }}</p>
          <fieldset :disabled="dialogMode === 'view' || saving">
            <div class="row">
              <label>Ngày kiểm<input v-model="form.counted_at" type="date" required /></label>
              <label>Ghi chú<input v-model="form.note" maxlength="2000" placeholder="Lý do kiểm kê" /></label>
            </div>
            <div class="items-box">
              <div class="items-header">
                <strong>Danh sách nguyên liệu đếm thực tế</strong>
                <button v-if="dialogMode !== 'view'" type="button" class="manager-button" @click="addLine">Thêm dòng</button>
              </div>
              <table v-if="form.items.length">
                <thead>
                  <tr><th>Nguyên liệu</th><th>Tồn ghi nhận</th><th>Số đếm thực tế</th><th>Chênh lệch</th><th>Lý do</th><th v-if="dialogMode !== 'view'"></th></tr>
                </thead>
                <tbody>
                  <tr v-for="(line, idx) in form.items" :key="idx">
                    <td>
                      <select v-model.number="line.ingredient_id" required @change="onPickIngredient(idx)">
                        <option :value="0" disabled>Chọn nguyên liệu</option>
                        <option v-for="i in ingredients" :key="i.id" :value="i.id">{{ i.code }} · {{ i.name }}</option>
                      </select>
                    </td>
                    <td class="number">{{ fmtNumber(line.system_qty) }}</td>
                    <td><input v-model.number="line.actual_qty" type="number" min="0" step="0.01" required @change="recomputeDiff(idx)" /></td>
                    <td class="number"><strong :class="diffClass(line.actual_qty - line.system_qty)">{{ fmtNumber(line.actual_qty - line.system_qty) }}</strong></td>
                    <td><input v-model="line.reason" maxlength="500" placeholder="Hao hụt, thất thoát, sai số…" /></td>
                    <td v-if="dialogMode !== 'view'"><button type="button" class="manager-button danger" @click="removeLine(idx)">Xoá</button></td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="empty">Chưa có dòng kiểm kê nào.</p>
            </div>
            <p v-if="dialogMode === 'view' && viewRow" class="status-line">
              Trạng thái: <strong>{{ statusLabel(viewRow.status) }}</strong>
              <span v-if="viewRow.completed_at"> · Hoàn tất {{ formatDateTime(viewRow.completed_at) }} bởi {{ viewRow.completed_by_name }}</span>
              <span v-if="viewRow.total_difference_value !== 0" class="strong"> · Tổng chênh lệch: {{ formatVnd(viewRow.total_difference_value) }}</span>
            </p>
          </fieldset>
        </div>
        <footer>
          <button type="button" class="manager-button" :disabled="saving" @click="closeDialog">Đóng</button>
          <button v-if="dialogMode !== 'view'" type="submit" class="manager-button primary" :disabled="saving">
            {{ saving ? 'Đang lưu…' : 'Tạo phiên' }}
          </button>
        </footer>
      </form>
    </dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ingredientsApi } from '@/api/ingredients'
import { useStockFlowStore } from '@/stores/managerStockFlow'
import type { Stocktake, StocktakeItemPayload, StocktakePayload } from '@/api/stockFlow'

const store = useStockFlowStore()

const dialog = ref<HTMLDialogElement | null>(null)
const dialogMode = ref<'create' | 'view'>('create')
const saving = ref(false)
const formError = ref('')
const viewRow = ref<Stocktake | null>(null)
const busyId = ref<number | null>(null)

interface IngredientRow { id: number; code: string; name: string; unit: string; quantity_in_stock: number; import_price: number }
const ingredients = ref<IngredientRow[]>([])

const filters = reactive({ status: '', from: '', to: '' })
const page = ref(1), lastPage = ref(1)

const total = computed(() => store.stocktakesTotal)
const rows = computed(() => store.stocktakes)
const loading = computed(() => store.stocktakesLoading)
const error = computed(() => store.stocktakesError)
const openCount = computed(() => store.openStocktakes.length)
const completedCount = computed(() => store.completedStocktakes.length)
const totalDiff = computed(() => store.stocktakes.reduce((s, x) => s + (x.total_difference_value || 0), 0))
const totalDiffClass = computed(() => diffClass(totalDiff.value))

const form = reactive<StocktakePayload & { items: (StocktakeItemPayload & { system_qty: number })[] }>({
  counted_at: new Date().toISOString().slice(0, 10),
  note: '',
  items: [],
})

const fmtNumber = (n: number | string | null) =>
  n == null ? '—' : Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
const formatVnd = (n: number | string) => Number(n).toLocaleString('vi-VN') + ' đ'
const formatDate = (s: string | null) => s ? new Date(s + 'T00:00:00').toLocaleDateString('vi-VN') : '—'
const formatDateTime = (s: string | null) => s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'

function statusLabel(s: string) {
  return { OPEN: 'Đang kiểm', COMPLETED: 'Đã hoàn tất', CANCELLED: 'Đã huỷ' }[s] || s
}
function statusClass(s: string) {
  return { OPEN: 'warn', COMPLETED: 'ok', CANCELLED: 'danger' }[s] || ''
}
function diffClass(d: number) {
  if (d > 0.001) return 'pos'
  if (d < -0.001) return 'neg'
  return ''
}

async function refresh() {
  const params: Record<string, any> = { page: page.value, per_page: 25 }
  if (filters.status) params.status = filters.status
  if (filters.from) params.from = filters.from
  if (filters.to) params.to = filters.to
  await store.fetchStocktakes(params)
  lastPage.value = Math.max(1, Math.ceil(store.stocktakesTotal / 25))
}

function changePage(p: number) { page.value = p; void refresh() }

function addLine() {
  form.items.push({ ingredient_id: 0, actual_qty: 0, reason: null, system_qty: 0 })
}
function removeLine(idx: number) { form.items.splice(idx, 1) }
function recomputeDiff(idx: number) {
  // No-op: chênh lệch tính trực tiếp trong template từ actual_qty - system_qty.
}
function onPickIngredient(idx: number) {
  const line = form.items[idx]
  if (!line) return
  const ing = ingredients.value.find((i) => i.id === line.ingredient_id)
  if (ing) {
    line.system_qty = ing.quantity_in_stock
    if (line.actual_qty === 0) line.actual_qty = ing.quantity_in_stock
  }
}

function openCreate() {
  dialogMode.value = 'create'
  Object.assign(form, {
    counted_at: new Date().toISOString().slice(0, 10),
    note: '',
    items: [{ ingredient_id: 0, actual_qty: 0, reason: null, system_qty: 0 }],
  })
  viewRow.value = null
  formError.value = ''
  dialog.value?.showModal()
}

function openDetail(row: Stocktake) {
  dialogMode.value = 'view'
  Object.assign(form, {
    counted_at: row.counted_at?.slice(0, 10) || '',
    note: row.note,
    items: row.items.map((it) => ({
      ingredient_id: it.ingredient_id,
      actual_qty: it.actual_qty,
      reason: it.reason,
      system_qty: it.system_qty,
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
    const valid = form.items.filter((l) => l.ingredient_id > 0)
    if (!valid.length) {
      formError.value = 'Phiên kiểm kê phải có ít nhất 1 dòng.'
      return
    }
    await store.createStocktake({
      counted_at: form.counted_at,
      note: form.note,
      items: valid.map((l) => ({ ingredient_id: l.ingredient_id, actual_qty: l.actual_qty, reason: l.reason })),
    })
    dialog.value?.close()
    await refresh()
  } catch (e: any) {
    formError.value = store.msg(e)
  } finally {
    saving.value = false
  }
}

async function completeOne(row: Stocktake) {
  if (!confirm(`Hoàn tất phiên kiểm kê ${row.code}? Phiếu chỉ lưu chênh lệch để đối chiếu, không đổi tồn kho.`)) return
  busyId.value = row.id
  try {
    await store.completeStocktake(row.id)
    await refresh()
  } catch (e: any) {
    alert(store.msg(e))
  } finally {
    busyId.value = null
  }
}

async function cancelOne(row: Stocktake) {
  if (!confirm(`Huỷ phiên kiểm kê ${row.code}?`)) return
  busyId.value = row.id
  try {
    await store.cancelStocktake(row.id)
    await refresh()
  } catch (e: any) {
    alert(store.msg(e))
  } finally {
    busyId.value = null
  }
}

onMounted(async () => {
  try {
    const list = await ingredientsApi.list()
    ingredients.value = list as unknown as IngredientRow[]
  } catch (e) { /* ignore */ }
  await refresh()
})
watch(() => filters.status, () => { page.value = 1; void refresh() })
</script>

<style scoped>
.stocktake-page { display: flex; flex-direction: column; gap: 18px; }
.metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.metrics>div { padding: 22px; background: white; border: 1px solid var(--color-border); border-radius: 12px; display: flex; flex-direction: column; gap: 12px; }
.metrics span { font-size: 13px; color: #64748b; }
.metrics strong { font-size: 24px; color: var(--color-brand-ink); }
.filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
.table-wrap { overflow-x: auto; background: white; border: 1px solid var(--color-border); border-radius: 12px; }
table { border-collapse: collapse; width: 100%; min-width: 880px; font-size: 13px; }
th, td { text-align: left; padding: 14px; border-bottom: 1px solid #edf1ee; }
th { background: var(--color-brand-soft); color: #64748b; font-size: 12px; }
.number { font-variant-numeric: tabular-nums; }
.tag { padding: 5px 8px; border-radius: 5px; background: #edf5ef; white-space: nowrap; font-size: 12px; }
.tag.warn { color: #9a3412; background: #fff2df; }
.tag.ok { color: #0f7d34; background: #d6f3df; }
.tag.danger { color: #b42318; background: #fde7e3; }
.pos { color: #0f7d34; }
.neg { color: #b42318; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.pagination { display: flex; align-items: center; gap: 10px; justify-content: flex-end; font-size: 13px; }
dialog { width: min(880px, calc(100% - 32px)); max-height: 90vh; border: 0; border-radius: 14px; padding: 0; color: var(--color-text); font: 14px Arial, sans-serif; }
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
.items-box input, .items-box select { border: 1px solid var(--color-border-strong); border-radius: 7px; padding: 8px; font: inherit; min-width: 0; box-sizing: border-box; width: 100%; }
.empty { color: #64748b; font-size: 13px; margin: 8px 0; }
.status-line { background: #f3f6f4; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-top: 12px; }
.status-line .strong { color: #b42318; font-weight: 600; }
input, select, textarea { border: 1px solid var(--color-border-strong); border-radius: 7px; padding: 10px; font: inherit; min-width: 0; box-sizing: border-box; }
.primary { background: var(--color-brand-action); color: white; }
.danger { color: #b42318; border-color: #f3c6c6; }
button { cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 880px) { .metrics { grid-template-columns: 1fr 1fr; } fieldset .row { grid-template-columns: 1fr; } }
</style>
