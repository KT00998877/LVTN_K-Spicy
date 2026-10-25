<template>
  <div class="daily-stock-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">SỔ KHO BẾP</span>
        <h2>Sổ kho hằng ngày</h2>
        <p>Bếp đếm tồn cuối ngày. Số đã chốt là tồn thực tế; lượng dùng được tính từ hai lần đếm.</p>
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
      <section class="sheet-summary" aria-label="Thông tin sổ">
        <div><span>Ngày kinh doanh</span><strong>{{ formatDate(sheet.business_date) }}</strong></div>
        <div><span>Nguyên liệu</span><strong>{{ rows.length }}</strong></div>
        <div><span>Đã đếm</span><strong>{{ countedCount }}/{{ rows.length }}</strong></div>
        <div><span>Kỳ đối chiếu</span><strong>{{ sheet.previous_date ? `Từ ${formatDate(sheet.previous_date)}` : 'Mốc khởi tạo' }}</strong></div>
      </section>

      <p v-if="sheet.baseline" class="manager-notice">Đây là lần đếm khởi tạo. Hệ thống ghi tồn thực tế nhưng chưa tính lượng dùng.</p>
      <p v-else-if="sheet.previous_date !== previousCalendarDay" class="manager-notice">Không có lần chốt vào ngày liền trước. Lượng dùng hiển thị là tổng từ lần chốt {{ formatDate(sheet.previous_date!) }}.</p>
      <p v-if="sheet.status === 'CLOSED'" class="manager-notice">Đã chốt lúc {{ formatTime(sheet.closed_at) }}. Số đã khóa và chỉ có thể xem.</p>
      <p v-if="!sheet.baseline && (sheet.missing_recipe_items || sheet.legacy_items)" class="manager-notice">Đối chiếu chưa đủ: {{ sheet.missing_recipe_items }} món bắt đầu nấu không có công thức, {{ sheet.legacy_items }} món cũ không có ảnh chụp công thức. Chênh lệch để trống.</p>
      <p v-if="!sheet.baseline && attentionCount" class="manager-notice">{{ attentionCount }} nguyên liệu cần đối chiếu; {{ explainedCount }} đã có giải trình từ bếp. Chênh lệch không tự kết luận thất thoát.</p>
      <section v-if="!sheet.baseline && unexplainedRows.length" class="manager-variance" aria-label="Nguyên liệu chưa giải trình">
        <strong>{{ unexplainedRows.length }} nguyên liệu chênh lệch chưa có giải trình</strong>
        <ul>
          <li v-for="row in unexplainedRows" :key="row.ingredient_id">
            {{ row.name }} <small>({{ row.code }})</small>: chênh <strong>{{ qty(variance(row)) }}</strong> {{ row.unit }}
          </li>
        </ul>
        <small class="manager-variance__hint">Bếp cần ghi lý do vào cột "Giải trình" trước khi chốt sổ — số chênh không tự kết luận thất thoát.</small>
      </section>
      <p v-if="!sheet.baseline" class="sheet-hint">Tồn dự kiến = tồn đầu + nhập − xuất/hủy + điều chỉnh − lượng dùng theo món đã bắt đầu nấu. Đây là số đối chiếu, không thay thế tồn bếp đếm.</p>
      <p class="sheet-hint">“Tiêu hao bán” là lượng hệ thống tự trừ khi bếp bắt đầu nấu. “Xuất/hủy” là lượng bếp xuất thủ công (hủy món, hư hỏng…). Hai cột tách riêng để không đếm trùng.</p>
      <p v-if="sheet.status !== 'CLOSED'" class="sheet-hint">Giữ chỗ là lượng cần cho đơn đang chờ nấu. Tồn khả dụng ước tính = tồn dự kiến − giữ chỗ; ô để trống khi chưa đủ dữ liệu.</p>

      <form v-if="rows.length" @submit.prevent="save">
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">Nguyên liệu</th><th scope="col">Tồn đầu</th><th scope="col">Nhập</th><th scope="col">Tiêu hao bán</th><th scope="col">Xuất/hủy</th><th scope="col">Điều chỉnh</th><th scope="col">Chứng từ</th><th scope="col">Tồn dự kiến</th><th scope="col">Giữ chỗ đơn chờ</th><th scope="col">Tồn khả dụng ước tính</th><th scope="col">Tồn bếp đếm</th><th scope="col">Đã dùng</th><th scope="col">Dùng dự kiến</th><th scope="col">Chênh lệch</th><th scope="col">Giải trình</th></tr></thead>
            <tbody v-for="group in groupedRows" :key="group.name">
              <tr class="category-row">
                <th colspan="15">
                  <button type="button" class="group-toggle" :aria-expanded="!collapsedGroups.has(group.name)" :aria-controls="`group-${group.name}`" @click="toggleGroup(group.name)">
                    <span class="chev" :class="{ open: !collapsedGroups.has(group.name) }" aria-hidden="true">▸</span>
                    {{ group.name }} · {{ group.counted }}/{{ group.rows.length }} đã đếm
                  </button>
                </th>
              </tr>
              <template v-if="!collapsedGroups.has(group.name)">
                <tr v-for="row in group.rows" :key="row.ingredient_id">
                <th scope="row"><strong>{{ row.name }}</strong><small>{{ row.code }} · {{ row.unit }}</small></th>
                <td>{{ qty(row.opening_qty) }}</td>
                <td>{{ qty(row.incoming_qty) }}</td>
                <td>{{ qty(row.consumption_qty) }}</td>
                <td>{{ qty(row.outgoing_qty) }}</td>
                <td>{{ qty(row.correction_qty) }}</td>
                <td><details v-if="row.sources.length"><summary>{{ row.sources.length }} giao dịch</summary><ul><li v-for="source in row.sources" :key="source.transaction_id">{{ sourceLabel(source) }} · {{ qty(source.quantity) }} {{ row.unit }}</li></ul></details><span v-else>—</span></td>
                <td>{{ qty(row.expected_balance_qty) }}<small v-if="!row.comparison_complete && !sheet.baseline">Chưa đủ dữ liệu</small></td>
                <td>{{ qty(row.reserved_qty) }}</td>
                <td>{{ qty(row.available_estimate_qty) }}</td>
                <td>
                  <label :for="`count-${row.ingredient_id}`" class="sr-only">Tồn cuối {{ row.name }} ({{ row.unit }})</label>
                  <input :id="`count-${row.ingredient_id}`" v-model="row.counted_input" type="number" inputmode="decimal" min="0" max="9999999999.99" step="0.01" :readonly="!canEdit" :aria-describedby="`unit-${row.ingredient_id}`" />
                  <span :id="`unit-${row.ingredient_id}`" class="unit">{{ row.unit }}</span>
                </td>
                <td :class="{ negative: used(row) !== null && used(row)! < 0 }">{{ qty(used(row)) }}</td>
                <td>{{ qty(row.expected_qty) }}<small v-if="row.uncomparable_items">{{ row.uncomparable_items }} món lệch đơn vị</small></td>
                <td>{{ variance(row) === null ? '—' : qty(variance(row)) }}<small v-if="!row.comparison_complete && !sheet.baseline">Chưa đủ dữ liệu</small><small v-else-if="variance(row) !== null && variance(row) !== 0">Cần đối chiếu</small></td>
                <td>
                  <template v-if="canEdit"><label :for="`explain-${row.ingredient_id}`" class="sr-only">Giải trình {{ row.name }} (tùy chọn)</label><textarea :id="`explain-${row.ingredient_id}`" v-model.trim="row.explanation" maxlength="500" rows="2" placeholder="Ghi lý do nếu cần đối chiếu" /></template>
                  <span v-else>{{ row.explanation || '—' }}</span>
                </td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>
        <p class="sheet-hint">Để trống ô chưa đếm. Sổ chỉ chốt được khi tất cả nguyên liệu đã có số và lượng dùng không âm.</p>
        <div v-if="canEdit" class="sheet-actions">
          <label for="counted-at">Giờ hoàn tất đếm</label>
          <input id="counted-at" v-model="countTime" type="datetime-local" step="1" :min="`${today}T00:00:00`" />
          <button type="button" class="manager-button" @click="countTime = vietnamNow()">Lấy giờ hiện tại</button>
          <button type="submit" class="manager-button" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu nháp' }}</button>
          <button v-if="canClose" type="button" class="manager-button primary" :disabled="saving || countedCount !== rows.length || !countTime" @click="closeSheet">{{ saving ? 'Đang xử lý…' : 'Chốt sổ ngày' }}</button>
        </div>
      </form>
      <p v-else class="manager-notice">Chưa có nguyên liệu đang sử dụng.</p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
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
/** Danh sách nhóm đang thu gọn; mặc định THU GỌN tất cả khi sheet load lần đầu. */
const collapsedGroups = ref<Set<string>>(new Set())
function toggleGroup(name: string) {
  if (collapsedGroups.value.has(name)) collapsedGroups.value.delete(name)
  else collapsedGroups.value.add(name)
  // Trigger reactivity khi xóa/thêm phần tử của Set.
  collapsedGroups.value = new Set(collapsedGroups.value)
}
/** Reset state thu gọn mỗi khi load sheet mới (theo ngày). */
watch(sheet, () => {
  const next = new Set<string>()
  groupedRows.value.forEach(g => next.add(g.name))
  collapsedGroups.value = next
})
const history = ref<HistoryEntry[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const canEdit = computed(() => staff.currentRole === 'KITCHEN' && selectedDate.value === today && sheet.value?.status !== 'CLOSED')
const canClose = computed(() => canEdit.value && !!staff.user?.can_close_daily_stock)
const countedCount = computed(() => rows.value.filter(row => row.counted_input.trim() !== '').length)
const needsAttention = (row: EditableItem) => !row.comparison_complete || (variance(row) !== null && variance(row) !== 0)
const attentionCount = computed(() => rows.value.filter(needsAttention).length)
const explainedCount = computed(() => rows.value.filter(row => needsAttention(row) && !!row.explanation?.trim()).length)
/** Phase E.3 — chi tiết các nguyên liệu có chênh lệch nhưng bếp CHƯA giải trình. */
const unexplainedRows = computed(() => rows.value.filter(row => variance(row) !== null && variance(row) !== 0 && !row.explanation?.trim()))
const statusLabel = computed(() => ({ NEW: 'Chưa lập', DRAFT: 'Nháp', CLOSED: 'Đã chốt' })[sheet.value?.status || 'NEW'])
const previousCalendarDay = computed(() => {
  const day = new Date(`${selectedDate.value}T12:00:00+07:00`)
  day.setUTCDate(day.getUTCDate() - 1)
  return day.toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
})
const qty = (value: number | null) => value === null ? '—' : Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
const sourceLabel = (source: StockSource) => source.reference_code || ({ SUPPLIER_RETURN: 'Trả NCC', OTHER_OUT: 'Xuất khác', DAMAGED: 'Hư hỏng', EXPIRED: 'Hết hạn', OTHER_WASTE: 'Hủy khác' } as Record<string, string>)[source.reason_code || ''] || `Giao dịch #${source.transaction_id}`
const formatDate = (value: string) => new Date(`${value}T12:00:00+07:00`).toLocaleDateString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })
const formatTime = (value: string | null) => value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'
const message = (e: any) => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || 'Không xử lý được sổ kho. Vui lòng thử lại.'

function used(row: EditableItem): number | null {
  if (sheet.value?.status === 'CLOSED') return row.used_qty
  if (sheet.value?.baseline || row.opening_qty === null || row.counted_input.trim() === '') return null
  return Math.round((row.opening_qty + row.incoming_qty - row.outgoing_qty + row.correction_qty - Number(row.counted_input)) * 100) / 100
}function variance(row: EditableItem): number | null {
  const actual = used(row)
  return row.comparison_complete && actual !== null && row.expected_qty !== null
    ? Math.round((actual - row.expected_qty) * 1000) / 1000 : null
}

async function load() {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const response = await api.get<{ data: Sheet }>(`/staff/daily-stock-sheets/${selectedDate.value}`)
    sheet.value = response.data.data
    rows.value = sheet.value.items.map(item => ({ ...item, counted_input: item.counted_qty === null ? '' : String(item.counted_qty) }))
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
  if (!canClose.value || saving.value) return
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
.sheet-toolbar label { font-weight: 600; }
.sheet-toolbar input { padding: 9px 11px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; }
.status { padding: 5px 10px; border-radius: 999px; background: #eef2f1; font-size: 13px; }
.status.closed { color: #245440; background: #e5f2e9; }
.status.draft { color: #9a5a00; background: #fff2d6; }
.sheet-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.sheet-summary > div { background: white; border: 1px solid var(--color-border); border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 7px; }
.sheet-summary span, .unit, .sheet-hint { color: #64748b; font-size: 13px; }
.sheet-summary strong { color: var(--color-brand-ink); }
.table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: 10px; background: white; }
table { border-collapse: collapse; width: 100%; min-width: 850px; font-size: 14px; }
th, td { text-align: left; border-bottom: 1px solid #edf1ee; padding: 11px; }
thead th { color: #56685f; background: var(--color-brand-soft); white-space: nowrap; }
tbody th { min-width: 150px; }
.category-row th { background: #edf5ef; color: var(--color-brand-ink); font-size: 13px; position: sticky; left: 0; }
.category-row .group-toggle { background: none; border: 0; padding: 0; margin: 0; cursor: pointer; font: inherit; color: inherit; display: inline-flex; align-items: center; gap: 8px; text-align: left; width: 100%; }
.category-row .group-toggle:hover { color: #1f4534; }
.category-row .chev { display: inline-block; transition: transform 0.15s ease; font-size: 12px; }
.category-row .chev.open { transform: rotate(90deg); }
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
@media (max-width: 750px) { .sheet-summary { grid-template-columns: repeat(2, minmax(0,1fr)); } }
</style>
