<template>
  <div class="daily-stock-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">SỔ KHO BẾP</span>
        <h2>Sổ kho hằng ngày</h2>
      </div>
    </header>

    <div class="sheet-toolbar">
      <label for="stock-date">Ngày xem sổ</label>
      <input id="stock-date" v-model="selectedDate" type="date" :max="today" />
      <button class="manager-button" :disabled="loading" @click="load">Xem sổ</button>
      <span v-if="sheet" class="status" :class="sheet.status.toLowerCase()">{{ statusLabel }}</span>
    </div>

    <p v-if="loading" role="status" class="manager-notice">Đang tải sổ kho…</p>
    <p v-if="error" role="alert" class="manager-notice error">{{ error }}</p>
    <p v-if="success" role="status" class="manager-notice">{{ success }}</p>

    <section v-if="history.length" class="recent-sheets" aria-label="Sổ kho gần đây">
      <h3>Sổ gần đây</h3>
      <div>
        <button v-for="entry in history" :key="entry.business_date" type="button" class="manager-button"
          :aria-current="selectedDate === entry.business_date ? 'date' : undefined"
          @click="selectedDate = entry.business_date; load()">
          {{ formatDate(entry.business_date) }} · {{ entry.status === 'CLOSED' ? 'Đã chốt' : 'Nháp' }}
        </button>
      </div>
    </section>

    <template v-if="sheet && !loading">
      <p v-if="sheet.previous_date && sheet.previous_date !== previousCalendarDay" class="manager-notice">Không có lần chốt vào ngày liền trước. Lượng dùng hiển thị là tổng từ lần chốt {{ formatDate(sheet.previous_date) }}.</p>
      <p v-if="sheet.status === 'CLOSED'" class="manager-notice">Đã chốt lúc {{ formatTime(sheet.closed_at) }}. Số đã khóa và chỉ có thể xem.</p>
      <p v-if="attentionCount" class="manager-notice">{{ attentionCount }} nguyên liệu cần đối chiếu; {{ explainedCount }} đã có lý do từ bếp.</p>
      <section v-if="unexplainedRows.length" class="manager-variance" aria-label="Nguyên liệu chưa có lý do">
        <strong>{{ unexplainedRows.length }} nguyên liệu chênh lệch chưa có lý do</strong>
        <ul>
          <li v-for="row in unexplainedRows" :key="row.ingredient_id">
            {{ row.name }} <small>({{ row.code }})</small>: chênh <strong>{{ qty(stockDifference(row)) }}</strong> {{ row.unit }}
          </li>
        </ul>
        <small class="manager-variance__hint">Bếp cần ghi lý do vào cột "Lý do" trước khi chốt sổ.</small>
      </section>

      <form v-if="rows.length" @submit.prevent="save">
        <div class="sheet-controls">
          <label for="stock-filter">Hiển thị</label>
          <select id="stock-filter" v-model="rowFilter"><option value="all">Tất cả</option><option value="uncounted">Chưa đếm</option><option value="variance">Có chênh lệch</option></select>
          <span class="sheet-progress">Đã đếm {{ countedCount }}/{{ rows.length }} nguyên liệu</span>
          <button v-if="canEdit" type="button" class="manager-button" @click="fillUncounted">Điền ô trống theo tồn hệ thống</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">Tồn hệ thống</th><th scope="col">Tồn bếp đếm</th><th scope="col">Chênh lệch</th><th scope="col">Lý do</th></tr></thead>
            <tbody v-for="group in visibleGroups" :key="group.name">
              <tr class="category-row">
                <th colspan="4">{{ group.name }} · {{ group.counted }}/{{ group.rows.length }} đã đếm</th>
              </tr>
                <tr v-for="row in group.rows" :key="row.ingredient_id">
                <th scope="row"><strong>{{ row.name }}</strong><small>{{ row.code }} · {{ row.unit }}</small><span class="system-qty">{{ qty(systemStock(row)) }} {{ row.unit }}</span></th>
                <td>
                  <label :for="`count-${row.ingredient_id}`" class="sr-only">Tồn cuối {{ row.name }} ({{ row.unit }})</label>
                  <input :id="`count-${row.ingredient_id}`" v-model="row.counted_input" type="number" inputmode="decimal" min="0" max="9999999999.99" step="0.01" :readonly="!canEdit" :aria-describedby="`unit-${row.ingredient_id}`" />
                  <span :id="`unit-${row.ingredient_id}`" class="unit">{{ row.unit }}</span>
                </td>
                <td>{{ qty(stockDifference(row)) }}<small v-if="stockDifference(row) !== null && stockDifference(row) !== 0">Cần đối chiếu</small></td>
                <td>
                  <template v-if="canEdit"><label :for="`explain-${row.ingredient_id}`" class="sr-only">Lý do {{ row.name }} (tùy chọn)</label><textarea :id="`explain-${row.ingredient_id}`" v-model.trim="row.explanation" maxlength="500" rows="2" placeholder="Ghi lý do chênh lệch" /></template>
                  <span v-else>{{ row.explanation || '—' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="rowFilter !== 'all' && !visibleGroups.length" class="sheet-hint">Không có nguyên liệu nào trong bộ lọc này.</p>
        <div v-if="canEdit" class="sheet-actions">
          <label for="counted-at">Giờ hoàn tất đếm</label>
          <input id="counted-at" v-model="countTime" type="datetime-local" step="1" :min="`${today}T00:00:00`" />
          <button type="button" class="manager-button" @click="countTime = vietnamNow()">Lấy giờ hiện tại</button>
          <button type="submit" class="manager-button" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu nháp' }}</button>
          <button v-if="canClose" type="button" class="manager-button primary" :disabled="saving || countedCount !== rows.length || !countTime" @click="reviewOpen = true">Xem lại và chốt sổ</button>
        </div>
        <section v-if="reviewOpen && canClose" class="sheet-review" aria-label="Xem lại trước khi chốt">
          <h3>Xem lại trước khi chốt</h3>
          <p>Đã đếm {{ countedCount }}/{{ rows.length }} nguyên liệu. {{ reviewDifferenceRows.length }} nguyên liệu khác số tham chiếu. Giờ đếm: {{ countTime.replace('T', ' ') }}.</p>
          <ul v-if="reviewDifferenceRows.length"><li v-for="row in reviewDifferenceRows" :key="row.ingredient_id">{{ row.name }}: tồn hệ thống {{ qty(systemStock(row)) }} {{ row.unit }}, bếp đếm {{ qty(Number(row.counted_input)) }} {{ row.unit }}. {{ row.explanation || 'Chưa có lý do' }}</li></ul>
          <p v-if="sheet.baseline">Đây là lần chốt mốc khởi tạo; số khác tồn hệ thống sẽ được ghi nhận khi chốt.</p>
          <div class="sheet-actions"><button type="button" class="manager-button" @click="reviewOpen = false">Quay lại chỉnh sửa</button><button type="button" class="manager-button primary" :disabled="saving || countedCount !== rows.length || !countTime" @click="closeSheet">{{ saving ? 'Đang xử lý…' : 'Xác nhận chốt sổ' }}</button></div>
        </section>
      </form>
      <p v-else class="manager-notice">Chưa có nguyên liệu đang sử dụng.</p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/api/client'
import { useStaffStore } from '@/stores/staff'
import { useAppDialog } from '@/composables/useAppDialog'
const appDialog = useAppDialog()

interface StockItem {
  ingredient_id: number
  code: string
  name: string
  category_name: string | null
  unit: string
  system_stock_qty: number | null
  opening_qty: number | null
  incoming_qty: number
  outgoing_qty: number
  consumption_qty: number
  correction_qty: number
  counted_qty: number | null
  used_qty: number | null
  expected_qty: number | null
  expected_balance_qty: number | null
  reserved_qty: number | null
  available_estimate_qty: number | null
  variance_qty: number | null
  comparison_complete: boolean
  uncomparable_items: number
  explanation: string | null
  sources: StockSource[]
}
interface StockSource { transaction_id: number; type: string; quantity: number; reference_code: string | null; reason_code: string | null }
interface Sheet {
  business_date: string
  status: 'NEW' | 'DRAFT' | 'CLOSED'
  baseline: boolean
  previous_date: string | null
  movement_version: number
  closed_at: string | null
  counted_at: string | null
  items: StockItem[]
  missing_recipe_items: number
  legacy_items: number
}
type EditableItem = StockItem & { counted_input: string }
interface HistoryEntry { business_date: string; status: 'DRAFT' | 'CLOSED' }

const staff = useStaffStore()
const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
const vietnamNow = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' }).replace(' ', 'T').slice(0, 19)
const selectedDate = ref(today)
const countTime = ref(vietnamNow())
const sheet = ref<Sheet | null>(null)
const rows = ref<EditableItem[]>([])
const groupedRows = computed(() => {
  const groups = new Map<string, EditableItem[]>()
  for (const row of rows.value) {
    const name = row.category_name || 'Chưa phân loại'
    groups.set(name, [...(groups.get(name) || []), row])
  }
  return [...groups.entries()].map(([name, entries]) => ({
    name, rows: entries, counted: entries.filter(row => row.counted_input.trim() !== '').length,
  }))
})
const rowFilter = ref<'all' | 'uncounted' | 'variance'>('all')
const reviewOpen = ref(false)
const visibleGroups = computed(() => groupedRows.value.map(group => {
  const entries = group.rows.filter(row => rowFilter.value === 'all'
    || (rowFilter.value === 'uncounted' && row.counted_input.trim() === '')
    || (rowFilter.value === 'variance' && stockDifference(row) !== null && stockDifference(row) !== 0))
  return { ...group, rows: entries, counted: entries.filter(row => row.counted_input.trim() !== '').length }
}).filter(group => group.rows.length))
const history = ref<HistoryEntry[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const canEdit = computed(() => staff.currentRole === 'KITCHEN' && selectedDate.value === today && sheet.value?.status !== 'CLOSED')
const canClose = computed(() => canEdit.value && !!staff.user?.can_close_daily_stock)
const countedCount = computed(() => rows.value.filter(row => row.counted_input.trim() !== '').length)
const needsAttention = (row: EditableItem) => stockDifference(row) !== null && stockDifference(row) !== 0
const attentionCount = computed(() => rows.value.filter(needsAttention).length)
const explainedCount = computed(() => rows.value.filter(row => needsAttention(row) && !!row.explanation?.trim()).length)
/** Phase E.3 — chi tiết các nguyên liệu có chênh lệch nhưng bếp CHƯA giải trình. */
const unexplainedRows = computed(() => rows.value.filter(row => needsAttention(row) && !row.explanation?.trim()))
const reviewDifferenceRows = computed(() => rows.value.filter(row => {
  if (row.counted_input.trim() === '') return false
  const reference = systemStock(row)
  return reference !== null && reference !== undefined && Number(row.counted_input) !== reference
}))
const statusLabel = computed(() => ({ NEW: 'Chưa lập', DRAFT: 'Nháp', CLOSED: 'Đã chốt' })[sheet.value?.status || 'NEW'])
const previousCalendarDay = computed(() => {
  const day = new Date(`${selectedDate.value}T12:00:00+07:00`)
  day.setUTCDate(day.getUTCDate() - 1)
  return day.toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
})
const qty = (value: number | null) => value === null ? '—' : Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
const formatDate = (value: string) => new Date(`${value}T12:00:00+07:00`).toLocaleDateString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })
const formatTime = (value: string | null) => value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
const message = (e: any) => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || 'Không xử lý được sổ kho. Vui lòng thử lại.'

function systemStock(row: EditableItem): number | null {
  return row.system_stock_qty
}
function stockDifference(row: EditableItem): number | null {
  const stock = systemStock(row)
  if (stock === null || row.counted_input.trim() === '') return null
  return Math.round((Number(row.counted_input) - stock) * 100) / 100
}

function fillUncounted() {
  if (!canEdit.value) return
  for (const row of rows.value) {
    if (row.counted_input.trim() === '' && row.system_stock_qty !== null) row.counted_input = String(row.system_stock_qty)
  }
  success.value = 'Đã điền các ô trống theo tồn hệ thống. Hãy kiểm tra số thực tế trước khi chốt.'
}

async function load() {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const response = await api.get<{ data: Sheet }>(`/staff/daily-stock-sheets/${selectedDate.value}`)
    sheet.value = response.data.data
    rows.value = sheet.value.items.map(item => ({ ...item, counted_input: item.counted_qty === null ? '' : String(item.counted_qty) }))
    reviewOpen.value = false
  } catch (e) { error.value = message(e) }
  finally { loading.value = false }
}

async function loadHistory() {
  try {
    const response = await api.get<{ data: HistoryEntry[] }>('/staff/daily-stock-sheets')
    history.value = response.data.data
  } catch { /* Sổ đang xem vẫn sử dụng được nếu danh sách gần đây không tải được. */ }
}

async function save() {
  if (!canEdit.value || saving.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.put(`/staff/daily-stock-sheets/${selectedDate.value}`, { movement_version: sheet.value?.movement_version, items: rows.value.map(row => ({
      ingredient_id: row.ingredient_id,
      counted_qty: row.counted_input.trim() === '' ? null : Number(row.counted_input),
      explanation: row.explanation,
    })) })
    await load()
    await loadHistory()
    success.value = 'Đã lưu bản nháp.'
  } catch (e) { error.value = message(e) }
  finally { saving.value = false }
}

async function closeSheet() {
  if (!canClose.value || saving.value || countedCount.value !== rows.value.length || !countTime.value) return
  if (!await appDialog.confirm({ title: 'Chốt sổ kho hôm nay?', message: 'Sau khi chốt, số đếm sẽ bị khóa và không thể chỉnh sửa.', confirmLabel: 'Chốt sổ kho', tone: 'warning' })) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.put(`/staff/daily-stock-sheets/${selectedDate.value}`, { movement_version: sheet.value?.movement_version, items: rows.value.map(row => ({
      ingredient_id: row.ingredient_id, counted_qty: Number(row.counted_input), explanation: row.explanation,
    })) })
    await api.post(`/staff/daily-stock-sheets/${selectedDate.value}/close`, { counted_at: countTime.value.replace('T', ' ') })
    await load()
    await loadHistory()
    success.value = 'Đã chốt sổ kho ngày.'
  } catch (e) { error.value = message(e) }
  finally { saving.value = false }
}

onMounted(() => { void load(); void loadHistory() })
</script>

<style scoped>
.daily-stock-page { display: flex; flex-direction: column; gap: 18px; }
.sheet-toolbar, .sheet-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sheet-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0; }
.sheet-controls label { font-weight: 600; }
.sheet-controls select { padding: 9px 11px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; background: white; }
.sheet-review { margin: 16px 0; padding: 16px; border: 1px solid var(--color-border-strong); border-radius: 10px; background: var(--color-brand-soft); }
.sheet-review h3 { margin: 0 0 8px; }
.sheet-review ul { padding-left: 20px; }
.sheet-toolbar label { font-weight: 600; }
.sheet-toolbar input { padding: 9px 11px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; }
.status { padding: 5px 10px; border-radius: 999px; background: #eef2f1; font-size: 13px; }
.status.closed { color: #245440; background: #e5f2e9; }
.status.draft { color: #9a5a00; background: #fff2d6; }
.unit, .sheet-hint { color: #64748b; font-size: 13px; }
.sheet-progress { margin-left: auto; color: var(--color-brand-ink); font-weight: 700; font-size: 13px; }
.table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: 10px; background: white; }
table { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 680px; font-size: 13px; }
th, td { text-align: left; border-bottom: 1px solid #edf1ee; padding: 11px; }
thead th { color: #56685f; background: var(--color-brand-soft); white-space: nowrap; position: sticky; top: 0; z-index: 2; }
thead th:first-child { left: 0; z-index: 3; }
tbody th { min-width: 170px; position: sticky; left: 0; background: white; z-index: 1; }
.system-qty { display: block; margin-top: 8px; font-weight: 700; font-size: 16px; font-variant-numeric: tabular-nums; }
.category-row th { background: #edf5ef; color: var(--color-brand-ink); font-size: 13px; position: static; }
small { display: block; font-weight: 400; color: #64748b; margin-top: 4px; }
td { font-variant-numeric: tabular-nums; }
td input { width: 100px; padding: 8px; border: 1px solid #a9bcb1; border-radius: 6px; font: inherit; }
td input:read-only { background: #f4f6f5; }
td textarea { width: 190px; max-width: 100%; padding: 8px; border: 1px solid #a9bcb1; border-radius: 6px; font: inherit; }
.unit { margin-left: 5px; }
.negative { color: #b42318; font-weight: 700; }
.sheet-hint { margin: 10px 0; }
.sheet-actions input { padding: 9px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; }
.recent-sheets h3 { font-size: 15px; margin: 0 0 8px; }
.recent-sheets > div { display: flex; gap: 8px; flex-wrap: wrap; }
.recent-sheets [aria-current="date"] { border-color: var(--color-brand-ink); color: var(--color-brand-ink); }
.primary { background: var(--color-brand-action); color: white; }
/* Phase E.3 — banner liệt kê NL chênh lệch chưa có giải trình */
.manager-variance { margin: 10px 0; padding: 12px 16px; border-radius: 10px;
                     background: #fff7ed; border: 1px solid #fde68a; color: #92400e; font-size: 13px; }
.manager-variance strong { display: block; margin-bottom: 6px; color: #9a3412; }
.manager-variance ul { margin: 4px 0 8px; padding-left: 20px; }
.manager-variance li { padding: 2px 0; }
.manager-variance li strong { display: inline; color: #9a3412; font-variant-numeric: tabular-nums; }
.manager-variance__hint { color: #92400e; opacity: 0.85; margin-top: 6px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>
