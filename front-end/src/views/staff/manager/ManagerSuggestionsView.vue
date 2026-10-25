<template>
  <div class="suggestions-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">QUẢN LÝ THỰC ĐƠN</span>
        <h2>Combo nước uống</h2>
        <p>
          Gợi ý món nên đi kèm với đồ uống cho từng món chính. Khách thấy gợi ý ngay trong
          màn hình tùy chỉnh món và được giảm giá combo khi thanh toán.
        </p>
      </div>
      <button class="manager-button primary" :disabled="loading" @click="openCreate()">
        Thêm gợi ý combo
      </button>
    </header>

    <div class="summary">
      <span>{{ rows.length }} gợi ý</span>
      <span>{{ activeCount }} đang hiển thị</span>
      <span v-if="totalSaving > 0">Tiết kiệm trung bình {{ money(totalSaving) }}/đơn</span>
    </div>

    <div class="filters">
      <input v-model="search" aria-label="Tìm kiếm" placeholder="Tìm theo tên món…" />
      <select v-model="status" aria-label="Trạng thái">
        <option value="all">Tất cả trạng thái</option>
        <option value="active">Đang hiển thị</option>
        <option value="hidden">Đang ẩn</option>
      </select>
      <button class="manager-button" :disabled="loading" @click="load()">Làm mới</button>
    </div>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>

    <template v-else-if="!error">
      <p v-if="!filtered.length" class="manager-notice">
        Chưa có gợi ý combo nào. Bấm “Thêm gợi ý combo” để tạo món chính đi kèm đồ uống.
      </p>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Combo</th>
              <th>Món chính</th>
              <th>Món gợi ý</th>
              <th>Giá combo</th>
              <th>Tiết kiệm</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filtered" :key="row.id">
              <td>
                <strong>{{ row.title }}</strong>
                <small v-if="row.message">{{ row.message }}</small>
              </td>
              <td>{{ row.menu_item?.name ?? '—' }}</td>
              <td>{{ row.suggested_item?.name ?? '—' }}</td>
              <td class="number">
                {{ row.combo_price !== null ? money(row.combo_price) : 'Không ưu đãi' }}
                <small v-if="row.combo_price !== null">tổng {{ money(row.original_price) }}</small>
              </td>
              <td class="number">
                <strong v-if="row.saving > 0" class="saving">-{{ money(row.saving) }}</strong>
                <span v-else>—</span>
              </td>
              <td>
                <span class="status" :class="{ muted: !row.is_active }">
                  {{ row.is_active ? 'Đang hiển thị' : 'Đang ẩn' }}
                </span>
              </td>
              <td class="actions">
                <button class="manager-button" @click="openEdit(row)">Sửa</button>
                <button class="manager-button danger" @click="remove(row)">Xoá</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <dialog ref="dialog" @cancel="onCancel" @click="onBackdrop">
      <form @submit.prevent="save">
        <header>
          <h3>{{ editingId ? 'Sửa gợi ý combo' : 'Thêm gợi ý combo' }}</h3>
          <button type="button" :disabled="saving" @click="close">Đóng</button>
        </header>

        <div class="form-body">
          <p v-if="formError" class="manager-notice error" role="alert">{{ formError }}</p>

          <label>
            Món chính
            <select v-model.number="form.menu_item_id" required :disabled="saving">
              <option :value="0" disabled>Chọn món chính</option>
              <option v-for="item in mainOptions" :key="item.id" :value="item.id">
                {{ item.name }} — {{ item.price_formatted }}
              </option>
            </select>
          </label>

          <label>
            Món gợi ý (đồ uống)
            <select v-model.number="form.suggested_item_id" required :disabled="saving">
              <option :value="0" disabled>Chọn món gợi ý</option>
              <option v-for="item in sideOptions" :key="item.id" :value="item.id">
                {{ item.name }} — {{ item.price_formatted }}
              </option>
            </select>
          </label>
          <p v-if="form.menu_item_id && form.suggested_item_id && form.menu_item_id === form.suggested_item_id" class="manager-notice error">
            Món gợi ý phải khác món chính.
          </p>

          <div class="form-grid">
            <label>
              Giá combo (đ)
              <input
                v-model.number="form.combo_price"
                type="number"
                min="0"
                max="9999999999.99"
                step="1000"
                :disabled="saving"
                placeholder="Để trống nếu không ưu đãi"
              />
            </label>
            <label>
              Thứ tự hiển thị
              <input v-model.number="form.sort_order" type="number" min="0" max="65535" :disabled="saving" />
            </label>
          </div>
          <p v-if="comboHint" class="help">{{ comboHint }}</p>

          <label>
            Tiêu đề hiển thị
            <input v-model="form.title" maxlength="150" :disabled="saving" placeholder="Bỏ trống để tự sinh (VD: Combo Mì cay + Trà đào)" />
          </label>

          <label>
            Mô tả ngắn
            <input v-model="form.message" maxlength="255" :disabled="saving" placeholder="VD: Thêm trà đào chỉ thêm 10.000đ" />
          </label>

          <label class="check">
            <input v-model="form.is_active" type="checkbox" :disabled="saving" />
            Hiển thị gợi ý cho khách
          </label>
        </div>

        <footer>
          <button type="button" class="manager-button" :disabled="saving" @click="close">Hủy</button>
          <button
            class="manager-button primary"
            :disabled="saving || !form.menu_item_id || !form.suggested_item_id"
          >
            {{ saving ? 'Đang lưu…' : 'Lưu thay đổi' }}
          </button>
        </footer>
      </form>
    </dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useAppDialog } from '@/composables/useAppDialog'
import {
  createMenuSuggestion,
  deleteMenuSuggestion,
  listMenuSuggestions,
  listSuggestionOptions,
  updateMenuSuggestion,
  type ManagerMenuSuggestion,
  type MenuSuggestionPayload,
  type SuggestionOption,
} from '@/api/menuSuggestion'

const rows = ref<ManagerMenuSuggestion[]>([])
const appDialog = useAppDialog()
const options = ref<SuggestionOption[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)

const search = ref('')
const status = ref<'all' | 'active' | 'hidden'>('all')

const dialog = ref<HTMLDialogElement>()
const editingId = ref<number | null>(null)

const defaults = () => ({
  menu_item_id: 0,
  suggested_item_id: 0,
  title: '',
  message: '',
  combo_price: null as number | null,
  is_active: true,
  sort_order: 0,
})
const form = reactive(defaults())

const money = (n: number) => n.toLocaleString('vi-VN') + ' đ'

const activeCount = computed(() => rows.value.filter((r) => r.is_active).length)
const totalSaving = computed(() => rows.value.reduce((sum, r) => sum + (r.is_active ? r.saving : 0), 0))

const filtered = computed(() =>
  rows.value.filter((r) => {
    const keyword = search.value.trim().toLocaleLowerCase('vi')
    const matchesSearch =
      !keyword ||
      r.title.toLocaleLowerCase('vi').includes(keyword) ||
      (r.menu_item?.name ?? '').toLocaleLowerCase('vi').includes(keyword) ||
      (r.suggested_item?.name ?? '').toLocaleLowerCase('vi').includes(keyword)
    const matchesStatus =
      status.value === 'all' ||
      (status.value === 'active' ? r.is_active : !r.is_active)
    return matchesSearch && matchesStatus
  }),
)

/** Món chính: mọi món. Món gợi ý: ưu tiên nhóm đồ uống, cho phép chọn mọi món. */
const mainOptions = computed(() => options.value)
const sideOptions = computed(() => {
  const drinks = options.value.filter((o) => o.category_slug === 'do-uong')
  const rest = options.value.filter((o) => o.category_slug !== 'do-uong')
  return [...drinks, ...rest]
})

/** Nhắc giá combo so với tổng giá 2 món. */
const comboHint = computed(() => {
  if (!form.menu_item_id || !form.suggested_item_id) return ''
  const main = options.value.find((o) => o.id === form.menu_item_id)
  const side = options.value.find((o) => o.id === form.suggested_item_id)
  if (!main || !side) return ''
  const total = main.price + side.price
  if (form.combo_price === null || form.combo_price === 0) {
    return `Giá gốc khi mua riêng: ${money(total)}. Để trống nếu chỉ gợi ý mà không ưu đãi.`
  }
  if (form.combo_price >= total) {
    return `Giá combo phải thấp hơn ${money(total)} để khách thấy ưu đãi.`
  }
  return `Khách tiết kiệm ${money(total - form.combo_price)} khi mua theo combo.`
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [list, opts] = await Promise.all([listMenuSuggestions({ per_page: 100 }), listSuggestionOptions()])
    rows.value = list.data
    options.value = opts
  } catch (e: any) {
    error.value =
      Object.values(e?.response?.data?.errors || {}).flat().join(' ') ||
      e?.response?.data?.message ||
      'Không tải được danh sách gợi ý. Vui lòng thử lại.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, defaults())
  formError.value = ''
  dialog.value?.showModal()
}

function openEdit(row: ManagerMenuSuggestion) {
  editingId.value = row.id
  Object.assign(form, {
    menu_item_id: row.menu_item_id,
    suggested_item_id: row.suggested_item_id,
    title: row.title ?? '',
    message: row.message ?? '',
    combo_price: row.combo_price,
    is_active: row.is_active,
    sort_order: row.sort_order,
  })
  formError.value = ''
  dialog.value?.showModal()
}

function close() {
  if (!saving.value) dialog.value?.close()
}

function onCancel(e: Event) {
  if (saving.value) e.preventDefault()
}

function onBackdrop(e: MouseEvent) {
  if (e.target === dialog.value) close()
}

async function save() {
  if (saving.value) return
  saving.value = true
  formError.value = ''
  try {
    const payload: MenuSuggestionPayload = {
      menu_item_id: form.menu_item_id,
      suggested_item_id: form.suggested_item_id,
      title: form.title.trim() || null,
      message: form.message.trim() || null,
      combo_price: form.combo_price,
      is_active: form.is_active,
      sort_order: form.sort_order,
    }
    if (editingId.value) {
      await updateMenuSuggestion(editingId.value, payload)
    } else {
      await createMenuSuggestion(payload)
    }
    dialog.value?.close()
    await load()
  } catch (e: any) {
    formError.value =
      Object.values(e?.response?.data?.errors || {}).flat().join(' ') ||
      e?.response?.data?.message ||
      'Không lưu được gợi ý. Vui lòng thử lại.'
  } finally {
    saving.value = false
  }
}

async function remove(row: ManagerMenuSuggestion) {
  if (!await appDialog.confirm({ title: 'Xóa gợi ý món?', message: `“${row.title}” sẽ bị xóa khỏi danh sách gợi ý.`, confirmLabel: 'Xóa gợi ý', tone: 'danger' })) return
  error.value = ''
  try {
    await deleteMenuSuggestion(row.id)
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Không xoá được gợi ý. Vui lòng thử lại.'
  }
}

onMounted(load)
</script>

<style scoped>
.suggestions-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.summary,
.filters {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.summary {
  padding: 18px;
  background: #eaf2ed;
  border-radius: 10px;
  font-size: 14px;
}

.filters input {
  flex: 1;
  min-width: 200px;
}

.table-wrap {
  overflow-x: auto;
  background: white;
  border: 1px solid var(--color-border);
  border-radius: 12px;
}

table {
  width: 100%;
  min-width: 900px;
  border-collapse: collapse;
  font-size: 13px;
}

th,
td {
  text-align: left;
  padding: 16px;
  border-bottom: 1px solid #edf1ee;
  vertical-align: top;
}

th {
  background: var(--color-brand-soft);
  color: #63736b;
  font-size: 12px;
}

small {
  display: block;
  color: #64748b;
  margin-top: 4px;
  font-size: 11px;
}

.number {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.saving {
  color: #166534;
}

.status {
  white-space: nowrap;
  padding: 5px 8px;
  border-radius: 5px;
  background: #eaf5ee;
  color: #166534;
  font-size: 12px;
}

.status.muted {
  background: #f1f5f9;
  color: #64748b;
}

.actions {
  display: flex;
  gap: 8px;
}

input,
select,
textarea {
  padding: 11px;
  border: 1px solid var(--color-border-strong);
  border-radius: 7px;
  font: inherit;
  box-sizing: border-box;
  min-width: 0;
}

.primary {
  background: var(--color-brand-action);
  color: white;
}

.danger {
  color: #b42318;
}

dialog {
  width: min(640px, calc(100% - 32px));
  max-height: 90vh;
  padding: 0;
  border: 0;
  border-radius: 14px;
  color: var(--color-text);
  font: 14px var(--font-ui);
}

dialog::backdrop {
  background: #10261dc0;
}

dialog header,
dialog footer {
  padding: 18px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  border-bottom: 1px solid #e2e8f0;
}

dialog header h3 {
  margin: 0;
}

dialog footer {
  justify-content: flex-end;
  border-top: 1px solid #e2e8f0;
}

dialog button {
  cursor: pointer;
}

dialog header button {
  border: 0;
  background: none;
  color: #64748b;
}

.form-body {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-body label {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.form-body .check {
  flex-direction: row;
  align-items: center;
}

.check input {
  accent-color: #245440;
}

.help {
  color: #64748b;
  font-size: 12px;
  margin: 0;
  line-height: 1.6;
}

@media (max-width: 550px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
