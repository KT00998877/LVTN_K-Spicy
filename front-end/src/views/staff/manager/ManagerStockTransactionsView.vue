<template>
  <div class="stock-tx-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">KHO &amp; NGUYÊN LIỆU</span>
        <h2>Lịch sử giao dịch kho</h2>
        <p>Truy xuất tất cả biến động tồn kho: nhập, xuất, hao hụt, kiểm kê. Có thể lọc theo ngày, nguyên liệu, loại phiếu.</p>
      </div>
    </header>

    <div class="filters">
      <select v-model="filters.ingredient_id" aria-label="Nguyên liệu">
        <option value="">Tất cả nguyên liệu</option>
        <option v-for="i in ingredients" :key="i.id" :value="i.id">{{ i.code }} · {{ i.name }}</option>
      </select>
      <select v-model="filters.type" aria-label="Loại phiếu">
        <option value="">Tất cả loại phiếu</option>
        <option value="IMPORT">IMPORT</option>
        <option value="EXPORT">EXPORT</option>
        <option value="WASTE">WASTE</option>
        <option value="ADJUSTMENT">ADJUSTMENT</option>
      </select>
      <label>Từ ngày<input v-model="filters.from" type="date" /></label>
      <label>Đến ngày<input v-model="filters.to" type="date" /></label>
      <button class="manager-button" :disabled="loading" @click="refresh()">Tra cứu</button>
      <button class="manager-button" :disabled="loading" @click="reset">Đặt lại</button>
    </div>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải lịch sử…</p>
    <template v-else-if="!error">
      <p v-if="!rows.length" class="manager-notice">Chưa có giao dịch nào trong khoảng tra cứu.</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mã phiếu / Thời gian</th>
              <th>Nguyên liệu</th>
              <th>Loại</th>
              <th>Số lượng</th>
              <th>Tồn trước → sau</th>
              <th>Tham chiếu</th>
              <th>Người thực hiện</th>
              <th>Lý do</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in rows" :key="t.id">
              <td>
                <strong>{{ t.code }}</strong>
                <small>{{ fmtDateTime(t.transacted_at) }}</small>
              </td>
              <td>
                {{ t.name }}<small>{{ t.unit }}</small>
              </td>
              <td><span class="tag" :class="typeClass(t.type)">{{ typeLabel(t.type) }}</span></td>
              <td class="number">
                <strong :class="t.quantity > 0 ? 'pos' : 'neg'">
                  {{ t.quantity > 0 ? '+' : '' }}{{ fmtNumber(t.quantity) }}
                </strong>
              </td>
              <td class="number">
                {{ fmtNumber(t.stock_before) }} → {{ fmtNumber(t.stock_after) }}
              </td>
              <td>
                <span v-if="t.reference_type" class="ref">{{ t.reference_type }}#{{ t.reference_id }}</span>
                <span v-else>—</span>
              </td>
              <td>{{ t.performed_name || '—' }}</td>
              <td><small class="note">{{ t.note || '—' }}</small></td>
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
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/api/client'
import { ingredientsApi } from '@/api/ingredients'

interface Transaction {
  id: number; code: string; name: string; unit: string; type: string
  quantity: number; stock_before: number | null; stock_after: number | null
  reference_type: string | null; reference_id: number | null
  performed_name: string | null; note: string; transacted_at: string
}
interface IngredientRow { id: number; code: string; name: string }

const rows = ref<Transaction[]>([])
const ingredients = ref<IngredientRow[]>([])
const loading = ref(false)
const error = ref('')

const filters = reactive({ ingredient_id: '' as number | '', type: '', from: '', to: '' })
const page = ref(1), lastPage = ref(1)

const fmtNumber = (n: number | string | null) =>
  n == null ? '—' : Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
const fmtDateTime = (s: string) =>
  new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })

function typeLabel(t: string) {
  return { IMPORT: 'Nhập kho', EXPORT: 'Xuất kho', WASTE: 'Hao hụt', ADJUSTMENT: 'Kiểm kê' }[t] || t
}
function typeClass(t: string) {
  return { IMPORT: 'ok', EXPORT: 'warn', WASTE: 'danger', ADJUSTMENT: 'accent' }[t] || ''
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    const params: Record<string, any> = { page: page.value }
    if (filters.ingredient_id) params.ingredient_id = filters.ingredient_id
    if (filters.type) params.type = filters.type
    if (filters.from) params.from = filters.from
    if (filters.to) params.to = filters.to
    const r = await api.get<{ data: Transaction[]; current_page: number; last_page: number }>(
      '/staff/dashboard/inventory-transactions', { params }
    )
    rows.value = r.data.data
    page.value = r.data.current_page
    lastPage.value = r.data.last_page
  } catch (e: any) {
    error.value = Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || 'Không tải được lịch sử.'
  } finally {
    loading.value = false
  }
}

function reset() {
  filters.ingredient_id = ''; filters.type = ''; filters.from = ''; filters.to = ''
  page.value = 1; void refresh()
}
function changePage(p: number) { page.value = p; void refresh() }

onMounted(async () => {
  try {
    ingredients.value = (await ingredientsApi.list({ per_page: 100 })).data
  } catch (e) { /* ignore */ }
  await refresh()
})
</script>

<style scoped>
.stock-tx-page { display: flex; flex-direction: column; gap: 18px; }
.filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
.table-wrap { overflow-x: auto; background: white; border: 1px solid #dfe7e2; border-radius: 12px; }
table { border-collapse: collapse; width: 100%; min-width: 920px; font-size: 13px; }
th, td { text-align: left; padding: 14px; border-bottom: 1px solid #edf1ee; vertical-align: top; }
th { background: #f8faf9; color: #64748b; font-size: 12px; }
small { display: block; color: #64748b; margin-top: 4px; font-size: 11px; }
.number { font-variant-numeric: tabular-nums; }
.tag { padding: 5px 8px; border-radius: 5px; white-space: nowrap; font-size: 12px; background: #edf5ef; }
.tag.ok { color: #0f7d34; background: #d6f3df; }
.tag.warn { color: #9a3412; background: #fff2df; }
.tag.danger { color: #b42318; background: #fde7e3; }
.tag.accent { color: #245440; background: #d5e7e0; }
.pos { color: #0f7d34; }
.neg { color: #b42318; }
.ref { background: #f1f5f9; padding: 3px 6px; border-radius: 4px; font-size: 11px; font-family: monospace; }
.note { white-space: pre-wrap; overflow-wrap: anywhere; max-width: 280px; }
.pagination { display: flex; align-items: center; gap: 10px; justify-content: flex-end; font-size: 13px; }
input, select { border: 1px solid #cbd5d0; border-radius: 7px; padding: 10px; font: inherit; min-width: 0; box-sizing: border-box; }
button { cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 700px) { .filters > * { flex: 1; } }
</style>
