<template>
  <div class="supplier-prices">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">KHO & NGUYÊN LIỆU</span>
        <h2>Giá nhập</h2>
        <p>Cập nhật quy cách mua và giá nhập. Đơn giá được chốt khi gửi yêu cầu.</p>
      </div>
    </header>

    <div class="price-toolbar">
      <label for="price-search">Tìm nguyên liệu</label>
      <input id="price-search" v-model.trim="search" type="search" placeholder="Tên hoặc mã nguyên liệu" />
    </div>
    <p v-if="loading" role="status">Đang tải bảng giá…</p>
    <p v-if="error" role="alert" class="price-error">{{ error }}</p>
    <p v-if="success" role="status" class="price-success">{{ success }}</p>
    <div v-if="!loading && !error" class="price-table-wrap">
      <table>
        <thead><tr><th>Nguyên liệu</th><th>Quy cách mua</th><th>Giá nhập / đơn vị mua</th><th v-if="canEdit">Thao tác</th></tr></thead>
        <tbody>
          <tr v-for="row in filtered" :key="`${row.ingredient_id}-${row.packaging_id ?? 'base'}`">
            <th scope="row"><strong>{{ row.name }}</strong><small>{{ row.code }} · {{ row.supplier_name }}</small></th>
            <td>
              <template v-if="canEdit">
                <span v-if="fixedUnit(row)">{{ row.editUnit }}</span>
                <template v-else>
                <label class="sr-only" :for="`unit-${key(row)}`">Quy cách mua {{ row.name }}</label>
                <select :id="`unit-${key(row)}`" v-model="row.editUnit" :disabled="row.stock_unit === 'gram'" @change="changeUnit(row)">
                  <option v-if="!row.packaging_id && row.stock_unit !== 'gram'" :value="row.stock_unit">{{ row.stock_unit }} (mua lẻ)</option>
                  <option v-for="unit in purchaseUnits(row)" :key="unit" :value="unit">{{ unit }}</option>
                </select>
                <input v-if="row.editUnit !== row.stock_unit && row.stock_unit !== 'gram'" v-model.number="row.editConversion" class="conversion-input" type="number" min="2" step="1" :aria-label="`Số ${row.stock_unit} trong một ${row.editUnit} ${row.name}`" :title="`Số ${row.stock_unit} trong một ${row.editUnit}`" />
                </template>
              </template>
              <template v-else>{{ row.purchase_unit }}</template>
            </td>
            <td>
              <label v-if="canEdit" class="price-input"><span class="sr-only">Giá nhập {{ row.name }} / {{ row.editUnit }}</span><input v-model.number="row.editPrice" type="number" min="1" max="9999999999.99" step="0.01" /> đ / {{ row.editUnit }}</label>
              <strong v-else>{{ row.price === null ? 'Chưa có giá' : formatMoney(row.price) }}</strong>
            </td>
            <td v-if="canEdit"><button type="button" class="manager-button primary" :disabled="savingKey === key(row) || !validRow(row) || !changed(row)" @click="save(row)">{{ savingKey === key(row) ? 'Đang lưu…' : 'Lưu' }}</button></td>
          </tr>
          <tr v-if="!filtered.length"><td :colspan="canEdit ? 4 : 3">Không có nguyên liệu phù hợp.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/api/client'
import { useStaffStore } from '@/stores/staff'

interface PriceRow {
  ingredient_id: number
  packaging_id: number | null
  code: string
  name: string
  supplier_name: string
  purchase_unit: string
  stock_unit: string
  conversion_quantity: number
  price: number | null
  editPrice: number | null
  editUnit: string
  editConversion: number
}

const staff = useStaffStore()
const canEdit = computed(() => staff.currentRole === 'MANAGER')
const rows = ref<PriceRow[]>([])
const search = ref('')
const loading = ref(false)
const savingKey = ref('')
const error = ref('')
const success = ref('')
const filtered = computed(() => rows.value.filter(row => `${row.code} ${row.name}`.toLocaleLowerCase('vi-VN').includes(search.value.toLocaleLowerCase('vi-VN'))))
const key = (row: PriceRow) => `${row.ingredient_id}-${row.packaging_id ?? 'base'}`
const fixedUnit = (row: PriceRow) => ['NL005', 'NL020', 'NL021', 'NL024', 'NL043', 'NL044', 'NL056'].includes(row.code)
const purchaseUnits = (row: PriceRow) => row.stock_unit === 'gram' ? ['kg'] : ['thùng', 'bịch', 'lốc', 'hộp', 'chai', 'kg'].filter(unit => unit !== row.stock_unit)
const validRow = (row: PriceRow) => row.editPrice !== null && Number.isFinite(row.editPrice) && row.editPrice > 0 && row.editPrice <= 9999999999.99
  && Number.isInteger(row.editConversion) && row.editConversion >= 1 && row.editConversion <= 1000000000
  && (row.editUnit === row.stock_unit ? row.editConversion === 1 : row.stock_unit === 'gram' ? row.editUnit === 'kg' && row.editConversion === 1000 : row.editConversion > 1)
const changed = (row: PriceRow) => row.editPrice !== row.price || row.editUnit !== row.purchase_unit || row.editConversion !== row.conversion_quantity
function changeUnit(row: PriceRow) { row.editConversion = row.editUnit === row.stock_unit ? 1 : row.stock_unit === 'gram' ? 1000 : row.editConversion === 1 ? 2 : row.editConversion }
const formatMoney = (price: number) => `${Number(price).toLocaleString('vi-VN')} đ`
const message = (e: any) => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || 'Không xử lý được bảng giá. Vui lòng thử lại.'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: Omit<PriceRow, 'editPrice'>[] }>('/staff/manager/supplier-prices')
    rows.value = response.data.data.map(row => ({ ...row, editPrice: row.price,
      editUnit: row.code === 'NL024' ? 'hộp' : row.code === 'NL056' ? 'chai' : row.code === 'NL005' ? 'bịch' : ['NL020', 'NL021', 'NL043', 'NL044'].includes(row.code) ? 'thùng' : row.purchase_unit,
      editConversion: ['NL024', 'NL056'].includes(row.code) ? 1000 : ['NL005', 'NL020', 'NL021', 'NL043', 'NL044'].includes(row.code) ? 24 : row.conversion_quantity }))
  } catch (e) { error.value = message(e) }
  finally { loading.value = false }
}

async function save(row: PriceRow) {
  if (!canEdit.value || !validRow(row)) return
  savingKey.value = key(row)
  error.value = ''
  success.value = ''
  try {
    const response = await api.put<{ packaging_id: number | null }>('/staff/manager/supplier-prices', { ingredient_id: row.ingredient_id, packaging_id: row.packaging_id, purchase_unit: row.editUnit, conversion_quantity: row.editConversion, price: row.editPrice })
    row.price = row.editPrice
    row.purchase_unit = row.editUnit
    row.conversion_quantity = row.editConversion
    row.packaging_id = response.data.packaging_id
    success.value = `Đã lưu ${row.name} / ${row.purchase_unit}.`
  } catch (e) { error.value = message(e) }
  finally { savingKey.value = '' }
}

onMounted(load)
</script>

<style scoped>
.supplier-prices { display: flex; flex-direction: column; gap: 18px; }
.price-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.price-toolbar input, .price-input input { border: 1px solid var(--color-border-strong); border-radius: 7px; padding: 10px; font: inherit; }
.price-toolbar input { min-width: min(320px, 100%); }
.price-input { display: inline-flex; align-items: center; gap: 8px; }
.price-input input { width: 150px; }
.conversion-input { width: 72px; margin-left: 8px; padding: 8px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; }
td select { min-width: 110px; padding: 8px; border: 1px solid var(--color-border-strong); border-radius: 7px; background: white; font: inherit; }
.price-table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: 10px; background: white; }
table { border-collapse: collapse; width: 100%; min-width: 680px; }
th, td { text-align: left; padding: 14px; border-bottom: 1px solid var(--color-border); }
thead th { background: var(--color-brand-soft); }
small { display: block; color: #64748b; margin-top: 4px; }
.primary { background: var(--color-brand-action); color: white; }
.price-error { color: var(--status-error-text); }
.price-success { color: var(--color-brand-ink); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>
