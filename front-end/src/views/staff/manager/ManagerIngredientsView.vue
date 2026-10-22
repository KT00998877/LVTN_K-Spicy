<template>
    <div class="mgr-ingredients">
        <!-- =========================================================
             HEADER — Where am I + primary action
             ========================================================= -->
        <header class="mgr-header">
            <div class="mgr-header__intro">
                <span class="mgr-eyebrow">QUẢN LÝ KHO</span>
                <h1 class="mgr-header__title">Nguyên liệu</h1>
                <p class="mgr-header__sub">
                    Theo dõi <strong>tồn kho</strong>, <strong>mức tối thiểu</strong> và <strong>đơn vị tính</strong>
                    cho từng nguyên liệu. Số liệu tồn chỉ thay đổi qua phiếu nhập/xuất/điều chỉnh
                    để đảm bảo <strong>có lịch sử audit</strong>.
                </p>
            </div>
            <div class="mgr-header__actions">
                <button class="mgr-btn mgr-btn--ghost" :disabled="store.loading" @click="refresh()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" :class="['mgr-btn__icon', { 'mgr-btn__icon--spin': refreshing }]">
                        <path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
                        <polyline points="21 3 21 8 16 8" />
                    </svg>
                    {{ refreshing ? 'Đang tải…' : 'Làm mới' }}
                </button>
                <button class="mgr-btn mgr-btn--primary" @click="openCreate">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Thêm nguyên liệu
                </button>
                <button class="mgr-btn mgr-btn--ghost" @click="categoryDialog?.showModal()">Thêm danh mục</button>
            </div>
        </header>

        <!-- =========================================================
             STATS — Quick operational overview
             ========================================================= -->
        <section class="mgr-stats" aria-label="Tổng quan">
            <article class="mgr-stat">
                <span class="mgr-stat__label">Tổng nguyên liệu</span>
                <div class="mgr-stat__value">{{ totalCount }}</div>
                <p class="mgr-stat__hint">{{ activeCount }} đang sử dụng · {{ totalCount - activeCount }} ẩn</p>
            </article>
            <article class="mgr-stat mgr-stat--warn">
                <span class="mgr-stat__label">Sắp hết hàng</span>
                <div class="mgr-stat__value">{{ lowStockCount }}</div>
                <p class="mgr-stat__hint">dưới hoặc bằng mức tối thiểu</p>
            </article>
            <article class="mgr-stat mgr-stat--ok">
                <span class="mgr-stat__label">Giá trị tồn kho</span>
                <div class="mgr-stat__value">{{ formatVND(totalValue) }}</div>
                <p class="mgr-stat__hint">theo giá nhập hiện tại</p>
            </article>
            <article class="mgr-stat mgr-stat--info">
                <span class="mgr-stat__label">Đang lọc</span>
                <div class="mgr-stat__value">{{ filtered.length }}</div>
                <p class="mgr-stat__hint">
                    {{ filters.low_stock ? 'chỉ sắp hết hàng' : filters.q ? 'tìm: "' + filters.q + '"' : 'tất cả' }}
                </p>
            </article>
        </section>

        <!-- =========================================================
             FILTER BAR
             ========================================================= -->
        <section class="mgr-filterbar">
            <label class="mgr-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input
                    v-model.trim="filters.q"
                    type="search"
                    placeholder="Tìm theo mã hoặc tên…"
                    aria-label="Tìm nguyên liệu"
                    @keydown.enter.prevent="onSearchEnter"
                />
            </label>
            <label class="mgr-check">
                <input v-model="filters.low_stock" type="checkbox" @change="onFilterChange" />
                Chỉ sắp hết hàng
            </label>
            <label class="mgr-field mgr-category-filter">Danh mục
                <select v-model.number="filters.ingredient_category_id" @change="onFilterChange">
                    <option :value="0">Tất cả danh mục</option>
                    <option v-for="category in categoryOptions" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
            </label>
            <label class="mgr-check">
                <input v-model="filters.is_active" type="checkbox" true-value="1" false-value="0" @change="onFilterChange" />
                Chỉ đang sử dụng
            </label>
            <span v-if="filtered.length" class="mgr-filterbar__count">
                {{ filtered.length }} / {{ store.list.length }} nguyên liệu
            </span>
        </section>

        <!-- =========================================================
             LOADING / ERROR
             ========================================================= -->
        <div v-if="store.loading && !store.list.length" class="mgr-state mgr-state--loading">
            <span class="mgr-spinner"></span>
            <p>Đang tải dữ liệu nguyên liệu…</p>
        </div>
        <div v-else-if="store.error" class="mgr-state mgr-state--error" role="alert">
            <span class="mgr-state__icon">!</span>
            <div>
                <strong>Không tải được dữ liệu</strong>
                <p>{{ store.error }}</p>
            </div>
            <button class="mgr-btn mgr-btn--ghost" @click="refresh()">Thử lại</button>
        </div>

        <!-- =========================================================
             TABLE
             ========================================================= -->
        <section v-else-if="filtered.length" class="mgr-tablecard">
            <table class="mgr-table">
                <thead>
                    <tr>
                        <th scope="col">Mã</th>
                        <th scope="col">Tên nguyên liệu</th>
                        <th scope="col">Danh mục</th>
                        <th scope="col" class="mgr-num">Tồn kho</th>
                        <th scope="col" class="mgr-num">Tối thiểu</th>
                        <th scope="col" class="mgr-num">Giá nhập</th>
                        <th scope="col">Nhà cung cấp</th>
                        <th scope="col">Trạng thái</th>
                        <th scope="col">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in filtered" :key="row.id"
                        :class="{ 'mgr-table__row--low': row.is_low_stock }">
                        <th scope="row" class="mgr-mono">{{ row.code }}</th>
                        <td class="mgr-name">
                            <strong>{{ row.name }}</strong>
                            <small class="mgr-name__unit">{{ row.unit }}</small>
                        </td>
                        <td>{{ row.category_name || 'Chưa phân loại' }}</td>
                        <td class="mgr-num">
                            <span :class="['mgr-stock', { 'mgr-stock--low': row.is_low_stock }]">
                                {{ formatNumber(row.quantity_in_stock) }}
                                <small>{{ row.unit }}</small>
                            </span>
                        </td>
                        <td class="mgr-num mgr-mute">
                            {{ formatNumber(row.min_stock_level) }} <small>{{ row.unit }}</small>
                        </td>
                        <td class="mgr-num mgr-money">{{ formatVND(row.import_price) }}</td>
                        <td>
                            <span class="mgr-mute">{{ row.supplier_name || '—' }}</span>
                        </td>
                        <td>
                            <span v-if="!row.is_active" class="mgr-pill mgr-pill--muted">Đã ẩn</span>
                            <span v-else-if="row.is_low_stock" class="mgr-pill mgr-pill--warn">Sắp hết</span>
                            <span v-else class="mgr-pill mgr-pill--ok">Bình thường</span>
                        </td>
                        <td>
                            <div class="mgr-rowactions">
                                <button class="mgr-linkbtn" @click="openEdit(row)">Chỉnh sửa</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- EMPTY -->
        <section v-else class="mgr-empty" role="status">
            <p class="mgr-empty__title">Chưa có nguyên liệu nào phù hợp.</p>
            <p class="mgr-empty__hint">Bấm <strong>Thêm nguyên liệu</strong> để tạo nguyên liệu đầu tiên hoặc thay đổi bộ lọc.</p>
        </section>

        <!-- =========================================================
             CREATE / EDIT DIALOG
             ========================================================= -->
        <dialog ref="dialog" class="mgr-dialog" @cancel="onCancelDialog" @click="onBackdrop">
            <form class="mgr-form" @submit.prevent="submit">
                <header class="mgr-form__head">
                    <h2 class="mgr-form__title">{{ editingId ? 'Chỉnh sửa nguyên liệu' : 'Thêm nguyên liệu' }}</h2>
                    <button type="button" class="mgr-iconbtn" :disabled="store.saving" aria-label="Đóng" @click="close">×</button>
                </header>

                <div class="mgr-form__body">
                    <p v-if="formError" class="mgr-alert mgr-alert--error" role="alert">{{ formError }}</p>

                    <div class="mgr-grid">
                        <label class="mgr-field">
                            <span>Mã nguyên liệu <em class="mgr-req">*</em></span>
                            <input
                                v-model.trim="form.code"
                                type="text"
                                required
                                maxlength="30"
                                :disabled="store.saving"
                                placeholder="VD: NL001"
                                autocomplete="off"
                            />
                            <small class="mgr-field__hint">Viết hoa, không dấu. Bắt đầu bằng chữ cái.</small>
                        </label>
                        <label class="mgr-field">
                            <span>Tên nguyên liệu <em class="mgr-req">*</em></span>
                            <input
                                v-model.trim="form.name"
                                type="text"
                                required
                                maxlength="150"
                                :disabled="store.saving"
                                placeholder="VD: Thịt bò"
                                autocomplete="off"
                            />
                        </label>
                    </div>

                    <div class="mgr-grid">
                        <label class="mgr-field">
                            <span>Đơn vị <em class="mgr-req">*</em></span>
                            <select v-model="form.unit" :disabled="store.saving" required>
                                <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                            </select>
                            <small v-if="editingId && unitLocked" class="mgr-field__hint mgr-field__hint--warn">
                                Đơn vị đã khóa vì nguyên liệu đã có công thức hoặc lịch sử tồn kho.
                            </small>
                        </label>
                        <label class="mgr-field">
                            <span>Mức tồn tối thiểu <em class="mgr-req">*</em></span>
                            <input
                                v-model.number="form.min_stock_level"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                                :disabled="store.saving"
                                placeholder="VD: 2"
                            />
                            <small class="mgr-field__hint">Dùng để cảnh báo khi tồn kho ≤ mức này.</small>
                        </label>
                    </div>

                    <label class="mgr-field">
                        <span>Nhà cung cấp</span>
                        <select v-model.number="form.supplier_id" :disabled="store.saving">
                            <option :value="null">— Không chọn —</option>
                            <option v-for="s in supplierOptions" :key="s.id" :value="s.id">
                                {{ s.name }}{{ s.is_active ? '' : ' (đã ẩn)' }}
                            </option>
                        </select>
                    </label>

                    <label class="mgr-field">
                        <span>Danh mục</span>
                        <select v-model.number="form.ingredient_category_id" :disabled="store.saving">
                            <option :value="null">— Chưa phân loại —</option>
                            <option v-for="category in categoryOptions" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </label>

                    <label class="mgr-check">
                        <input v-model="form.is_active" type="checkbox" :disabled="store.saving" />
                        Đang sử dụng
                    </label>

                    <section v-if="editingId" class="mgr-alert">
                        <strong>Quy cách nhập</strong>
                        <p v-if="editingIngredient?.packagings.length">
                            <span v-for="pack in editingIngredient.packagings" :key="pack.id" class="mgr-pill mgr-pill--muted">
                                1 {{ pack.purchase_unit }} = {{ formatNumber(pack.conversion_quantity) }} {{ editingIngredient.unit }}
                            </span>
                        </p>
                        <div class="mgr-grid">
                            <label class="mgr-field"><span>Đơn vị mua</span><input v-model.trim="packagingForm.purchase_unit" maxlength="30" placeholder="VD: bịch, thùng" /></label>
                            <label class="mgr-field"><span>Số {{ editingIngredient?.unit }} trong 1 đơn vị mua</span><input v-model.number="packagingForm.conversion_quantity" type="number" min="0.001" step="0.001" /></label>
                        </div>
                        <button type="button" class="mgr-btn mgr-btn--ghost" :disabled="savingPackaging" @click="addPackaging">{{ savingPackaging ? 'Đang lưu…' : 'Thêm quy cách' }}</button>
                    </section>

                    <div v-if="blocking && !blocking.can_deactivate" class="mgr-alert mgr-alert--warn">
                        <strong>Không thể ẩn nguyên liệu này</strong>
                        <p>Đang được dùng trong {{ blocking.data.length }} món:</p>
                        <ul>
                            <li v-for="m in blocking.data" :key="m.id">{{ m.name }}</li>
                        </ul>
                    </div>

                    <!-- Phase E: tồn dự kiến (3 cột + khả dụng ước tính). Chỉ hiện khi đang sửa. -->
                    <IngredientBalancePanel v-if="editingId" :ingredient-id="editingId" />
                </div>

                <footer class="mgr-form__foot">
                    <button type="button" class="mgr-btn mgr-btn--ghost" :disabled="store.saving" @click="close">Huỷ</button>
                    <button type="submit" class="mgr-btn mgr-btn--primary"
                            :disabled="store.saving || !canSubmit">
                        {{ store.saving ? 'Đang lưu…' : editingId ? 'Lưu thay đổi' : 'Tạo nguyên liệu' }}
                    </button>
                </footer>
            </form>
        </dialog>

        <dialog ref="categoryDialog" class="mgr-dialog">
            <form class="mgr-form" @submit.prevent="addCategory">
                <header class="mgr-form__head"><h2 class="mgr-form__title">Thêm danh mục nguyên liệu</h2><button type="button" class="mgr-iconbtn" aria-label="Đóng" @click="categoryDialog?.close()">×</button></header>
                <div class="mgr-form__body"><label class="mgr-field"><span>Tên danh mục</span><input v-model.trim="newCategoryName" required maxlength="100" placeholder="VD: Hải sản tươi" /></label></div>
                <footer class="mgr-form__foot"><button type="button" class="mgr-btn mgr-btn--ghost" @click="categoryDialog?.close()">Hủy</button><button class="mgr-btn mgr-btn--primary" :disabled="!newCategoryName || savingCategory">{{ savingCategory ? 'Đang lưu…' : 'Tạo danh mục' }}</button></footer>
            </form>
        </dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import apiClient from '@/api/client'
import { useIngredientsStore } from '@/stores/managerIngredients'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { ALLOWED_UNITS, ingredientsApi, type Ingredient, type IngredientCategory, type IngredientPayload } from '@/api/ingredients'
import IngredientBalancePanel from '@/components/manager/IngredientBalancePanel.vue'

const store = useIngredientsStore()

const units = ALLOWED_UNITS
const dialog = ref<HTMLDialogElement | null>(null)
const categoryDialog = ref<HTMLDialogElement | null>(null)
const editingId = ref<number | null>(null)
const refreshing = ref(false)
const formError = ref('')
const blocking = ref<{ can_deactivate: boolean; data: { id: number; name: string; code: string | null }[] } | null>(null)
const supplierOptions = ref<{ id: number; name: string; is_active: boolean }[]>([])
const categoryOptions = ref<IngredientCategory[]>([])
const newCategoryName = ref('')
const savingCategory = ref(false)
const savingPackaging = ref(false)
const packagingForm = reactive({ purchase_unit: '', conversion_quantity: 1 })

const filters = reactive<{ q: string; low_stock: boolean; is_active: '1' | '0'; ingredient_category_id: number }>({
    q: '',
    low_stock: false,
    is_active: '1',
    ingredient_category_id: 0,
})

interface FormState {
    code: string
    name: string
    unit: string
    min_stock_level: number
    supplier_id: number | null
    ingredient_category_id: number | null
    is_active: boolean
}

const form = reactive<FormState>({
    code: '',
    name: '',
    unit: 'kg',
    min_stock_level: 0,
    supplier_id: null,
    ingredient_category_id: null,
    is_active: true,
})

const filtered = computed<Ingredient[]>(() => store.list)
const editingIngredient = computed(() => store.list.find(x => x.id === editingId.value))
const totalCount = computed(() => store.meta?.total ?? store.list.length)
const activeCount = computed(() => store.list.filter((x) => x.is_active).length)
const lowStockCount = computed(() => store.list.filter((x) => x.is_low_stock && x.is_active).length)
const totalValue = computed(() => store.list.reduce((s, x) => s + (x.quantity_in_stock * x.import_price), 0))

const unitLocked = computed(() => {
    if (!editingId.value) return false
    const ing = store.list.find((x) => x.id === editingId.value)
    if (!ing) return false
    return ing.quantity_in_stock > 0
})

const canSubmit = computed(() => {
    if (!form.code || !form.name || !form.unit) return false
    if (form.min_stock_level < 0) return false
    return true
})

async function refresh(quiet = false) {
    if (refreshing.value) return
    refreshing.value = true
    try {
        await store.fetchList({
            q: filters.q || undefined,
            low_stock: filters.low_stock || undefined,
            is_active: filters.is_active === '1' ? true : false,
            ingredient_category_id: filters.ingredient_category_id || undefined,
            per_page: 100,
        }, quiet)
    } finally {
        refreshing.value = false
    }
}

function onSearchEnter() { refresh() }
function onFilterChange() { refresh() }

async function loadSuppliers() {
    try {
        const res = await apiClient.get<{ data: { suppliers: { id: number; name: string; is_active: boolean }[] } }>(
            '/staff/dashboard/ingredients',
        )
        supplierOptions.value = res.data.data.suppliers.map((s) => ({
            id: s.id, name: s.name, is_active: !!s.is_active,
        }))
    } catch {
        supplierOptions.value = []
    }
}

async function loadCategories() {
    try { categoryOptions.value = await ingredientsApi.categories() } catch { categoryOptions.value = [] }
}

async function addCategory() {
    if (!newCategoryName.value || savingCategory.value) return
    savingCategory.value = true
    try {
        const created = await ingredientsApi.createCategory(newCategoryName.value)
        categoryOptions.value.push(created)
        form.ingredient_category_id = created.id
        newCategoryName.value = ''
        categoryDialog.value?.close()
    } catch (err: any) { formError.value = err?.response?.data?.message || 'Không tạo được danh mục.' }
    finally { savingCategory.value = false }
}

async function addPackaging() {
    if (!editingId.value || !packagingForm.purchase_unit || packagingForm.conversion_quantity <= 0) return
    savingPackaging.value = true
    try {
        const updated = await ingredientsApi.savePackaging(editingId.value, {
            supplier_id: form.supplier_id, purchase_unit: packagingForm.purchase_unit,
            conversion_quantity: packagingForm.conversion_quantity, is_default: false, is_active: true,
        })
        const index = store.list.findIndex(x => x.id === updated.id)
        if (index >= 0) store.list[index] = updated
        packagingForm.purchase_unit = ''; packagingForm.conversion_quantity = 1
    } catch (err: any) { formError.value = err?.response?.data?.message || 'Không lưu được quy cách.' }
    finally { savingPackaging.value = false }
}

function openCreate() {
    editingId.value = null
    blocking.value = null
    Object.assign(form, {
        code: '', name: '', unit: 'kg', min_stock_level: 0,
        supplier_id: null, is_active: true,
        ingredient_category_id: null,
    })
    formError.value = ''
    dialog.value?.showModal()
}

async function openEdit(row: Ingredient) {
    editingId.value = row.id
    Object.assign(form, {
        code: row.code, name: row.name, unit: row.unit,
        min_stock_level: row.min_stock_level,
        supplier_id: row.supplier_id,
        ingredient_category_id: row.ingredient_category_id,
        is_active: row.is_active,
    })
    formError.value = ''
    blocking.value = null
    dialog.value?.showModal()
    try {
        blocking.value = await store.blockingReferences(row.id)
    } catch {
        blocking.value = null
    }
}

function close() {
    if (store.saving) return
    dialog.value?.close()
}

function onCancelDialog(e: Event) {
    if (store.saving) e.preventDefault()
}

function onBackdrop(e: MouseEvent) {
    if (e.target === dialog.value) close()
}

async function submit() {
    if (store.saving || !canSubmit.value) return
    const payload: IngredientPayload = {
        code: form.code,
        name: form.name,
        unit: form.unit,
        min_stock_level: Number(form.min_stock_level),
        supplier_id: form.supplier_id,
        ingredient_category_id: form.ingredient_category_id,
        is_active: form.is_active,
    }
    try {
        if (editingId.value) {
            await store.update(editingId.value, payload)
        } else {
            await store.create(payload)
        }
        dialog.value?.close()
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
        if (e?.response?.data?.errors) {
            formError.value = Object.values(e.response.data.errors).flat().join(' ')
        } else {
            formError.value = e?.response?.data?.message ?? (err as Error).message ?? 'Không lưu được. Vui lòng thử lại.'
        }
    }
}

// Filters thay đổi → fetch lại sau 250ms (debounce nhẹ)
let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch([() => filters.q, () => filters.low_stock, () => filters.is_active, () => filters.ingredient_category_id], () => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => refresh(), 250)
})

onMounted(() => {
    refresh()
    loadSuppliers()
    loadCategories()
})

useRealtimeRefresh(() => refresh(true), () => !refreshing.value && !store.saving)

function formatVND(value: number): string {
    if (!value) return '0 đ'
    return value.toLocaleString('vi-VN') + ' đ'
}
function formatNumber(value: number): string {
    return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 3 })
}
</script>

<style scoped>
/* ================================================================
   Manager — Ingredients CRUD
   Cream surface · chili-red accent · follows restaurant-ui.mdc tokens
   ================================================================ */
.mgr-ingredients { display: flex; flex-direction: column; gap: 24px; }

.mgr-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; flex-wrap: wrap; }
.mgr-header__intro { max-width: 720px; }
.mgr-header__title { font-size: clamp(24px, 3vw, 32px); line-height: 1.2; font-weight: 800;
                     letter-spacing: -0.5px; margin: 6px 0 8px; text-transform: capitalize; }
.mgr-header__sub { font-size: 14px; color: var(--mgr-gray-500); line-height: 1.6; margin: 0; }
.mgr-header__sub strong { color: var(--mgr-charcoal-black); font-weight: 700; }
.mgr-eyebrow { color: var(--mgr-chili-red); font-size: 11px; font-weight: 800; letter-spacing: 2px;
               display: inline-flex; align-items: center; gap: 6px; }
.mgr-eyebrow::before { content: ''; width: 18px; height: 2px; background: var(--mgr-chili-red); }
.mgr-header__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ---- Buttons ---- */
.mgr-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 9999px;
           font-size: 14px; font-weight: 600; font-family: inherit; cursor: pointer; border: 1.5px solid transparent;
           transition: background-color 150ms ease, color 150ms ease, transform 150ms ease, box-shadow 150ms ease; }
.mgr-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.mgr-btn--primary { background: var(--mgr-chili-red); color: #fff; }
.mgr-btn--primary:hover:not(:disabled) { background: #a80f17; transform: translateY(-1px); }
.mgr-btn--ghost { background: #fff; border-color: var(--mgr-gray-200); color: var(--mgr-charcoal-black); }
.mgr-btn--ghost:hover:not(:disabled) { border-color: var(--mgr-chili-red); color: var(--mgr-chili-red); }
.mgr-btn__icon--spin { animation: mgr-spin 0.9s linear infinite; }
@keyframes mgr-spin { to { transform: rotate(360deg); } }

/* ---- Stats ---- */
.mgr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.mgr-stat { background: #fff; border: 1px solid var(--mgr-gray-200); border-radius: 16px; padding: 18px 20px; }
.mgr-stat__label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px;
                   color: var(--mgr-gray-600); display: block; }
.mgr-stat__value { font-size: 32px; font-weight: 800; line-height: 1.1; color: var(--mgr-charcoal-black);
                   font-variant-numeric: tabular-nums; margin: 6px 0 4px; }
.mgr-stat__hint { font-size: 12px; color: var(--mgr-gray-500); margin: 0; }
.mgr-stat--warn .mgr-stat__value { color: #b45309; }
.mgr-stat--ok .mgr-stat__value { color: #15803d; }
.mgr-stat--info .mgr-stat__value { color: #4338ca; }

/* ---- Filter Bar ---- */
.mgr-filterbar { display: flex; gap: 16px; align-items: center; flex-wrap: wrap;
                 background: #fff; border: 1px solid var(--mgr-gray-200); border-radius: 14px; padding: 12px 16px; }
.mgr-search { display: flex; align-items: center; gap: 8px; background: var(--mgr-cream-dark, #fff5e6);
              padding: 8px 14px; border-radius: 9999px; flex: 1; min-width: 220px; color: var(--mgr-gray-700); }
.mgr-search input { border: 0; background: transparent; font: inherit; outline: none; flex: 1; color: var(--mgr-charcoal-black); }
.mgr-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--mgr-gray-700); cursor: pointer; }
.mgr-check input { accent-color: var(--mgr-chili-red); width: 16px; height: 16px; }
.mgr-filterbar__count { font-size: 12px; color: var(--mgr-gray-500); font-weight: 600; margin-left: auto; }

/* ---- State ---- */
.mgr-state { display: flex; align-items: center; gap: 14px; padding: 18px 22px; border-radius: 14px; font-size: 14px; }
.mgr-state--loading { background: var(--mgr-cream-dark, #fff5e6); color: var(--mgr-gray-600); }
.mgr-state--error { background: #fee2e2; color: #991b1b; justify-content: space-between; }
.mgr-state--error p { margin: 4px 0 0; font-size: 13px; opacity: 0.85; }
.mgr-state__icon { width: 28px; height: 28px; border-radius: 50%; background: #991b1b; color: #fff;
                   display: flex; align-items: center; justify-content: center; font-weight: 800; }
.mgr-spinner { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--mgr-gray-300);
               border-top-color: var(--mgr-chili-red); animation: mgr-spin 0.8s linear infinite; }

/* ---- Table ---- */
.mgr-tablecard { background: #fff; border: 1px solid var(--mgr-gray-200); border-radius: 16px; overflow: hidden; }
.mgr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mgr-table thead th { background: var(--mgr-cream-dark, #fff5e6); padding: 14px 16px;
                      text-align: left; font-size: 11px; text-transform: uppercase;
                      letter-spacing: 1px; color: var(--mgr-gray-700); font-weight: 800;
                      border-bottom: 1px solid var(--mgr-gray-200); }
.mgr-table tbody td { padding: 14px 16px; border-bottom: 1px solid var(--mgr-gray-100); vertical-align: middle; }
.mgr-table tbody tr:last-child td { border-bottom: 0; }
.mgr-table tbody tr:hover { background: var(--mgr-gray-50); }
.mgr-table__row--low td { background: rgba(180, 83, 9, 0.04); }
.mgr-num { text-align: right; font-variant-numeric: tabular-nums; }
.mgr-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--mgr-gray-800); }
.mgr-name { display: flex; flex-direction: column; gap: 2px; }
.mgr-name strong { color: var(--mgr-charcoal-black); font-weight: 700; }
.mgr-name__unit { font-size: 11px; color: var(--mgr-gray-500); text-transform: uppercase; letter-spacing: 0.5px; }
.mgr-mute { color: var(--mgr-gray-500); font-size: 12px; }
.mgr-money { font-weight: 700; color: var(--mgr-charcoal-black); }
.mgr-stock { font-weight: 800; color: var(--mgr-charcoal-black); display: inline-flex; gap: 4px; align-items: baseline; }
.mgr-stock small { font-size: 11px; font-weight: 600; color: var(--mgr-gray-500); text-transform: lowercase; }
.mgr-stock--low { color: #b45309; }

/* ---- Pills ---- */
.mgr-pill { display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; }
.mgr-pill--ok { background: #dcfce7; color: #166534; }
.mgr-pill--warn { background: #fef3c7; color: #92400e; }
.mgr-pill--muted { background: var(--mgr-gray-100); color: var(--mgr-gray-600); }

.mgr-rowactions { display: flex; gap: 12px; }
.mgr-linkbtn { background: transparent; border: 0; color: var(--mgr-chili-red); font-weight: 700;
               font-size: 13px; cursor: pointer; padding: 0; }
.mgr-linkbtn:hover { color: #a80f17; text-decoration: underline; }

/* ---- Empty ---- */
.mgr-empty { background: #fff; border: 1px dashed var(--mgr-gray-200); border-radius: 16px; padding: 56px 24px;
             text-align: center; }
.mgr-empty__title { font-size: 16px; font-weight: 700; color: var(--mgr-charcoal-black); margin: 0 0 6px; }
.mgr-empty__hint { font-size: 13px; color: var(--mgr-gray-500); margin: 0; }

/* ---- Dialog ---- */
.mgr-dialog { width: min(720px, calc(100% - 32px)); max-height: 90vh; padding: 0; border: 0;
              border-radius: 16px; background: var(--mgr-cream, #fff8ee); color: var(--mgr-charcoal-black); font-family: inherit; }
.mgr-dialog::backdrop { background: rgba(17, 17, 17, 0.55); }
.mgr-form { display: flex; flex-direction: column; max-height: 90vh; }
.mgr-form__head { padding: 18px 24px; display: flex; align-items: center; justify-content: space-between;
                  border-bottom: 1px solid var(--mgr-gray-200); }
.mgr-form__title { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -0.3px; }
.mgr-iconbtn { width: 32px; height: 32px; border-radius: 50%; border: 0; background: var(--mgr-gray-100);
               color: var(--mgr-gray-700); font-size: 22px; line-height: 1; cursor: pointer; display: flex;
               align-items: center; justify-content: center; }
.mgr-iconbtn:hover:not(:disabled) { background: var(--mgr-gray-200); color: var(--mgr-charcoal-black); }

.mgr-form__body { padding: 24px; display: flex; flex-direction: column; gap: 18px; overflow-y: auto; }
.mgr-form__foot { padding: 16px 24px; display: flex; gap: 10px; justify-content: flex-end;
                  border-top: 1px solid var(--mgr-gray-200); background: #fff; }

.mgr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.mgr-field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--mgr-gray-700); }
.mgr-field span { font-weight: 700; }
.mgr-field input, .mgr-field select { font: inherit; padding: 10px 12px; border: 1px solid var(--mgr-gray-300);
                                      border-radius: 10px; background: #fff; color: var(--mgr-charcoal-black); }
.mgr-field input:focus, .mgr-field select:focus { outline: 2px solid var(--mgr-chili-red); outline-offset: 1px;
                                                   border-color: var(--mgr-chili-red); }
.mgr-field__hint { font-size: 11px; color: var(--mgr-gray-500); margin-top: 2px; }
.mgr-field__hint--warn { color: #b45309; }
.mgr-req { color: var(--mgr-chili-red); font-style: normal; font-weight: 700; }

.mgr-check { display: inline-flex; gap: 8px; align-items: center; font-size: 13px; color: var(--mgr-charcoal-black); }
.mgr-check input { width: 18px; height: 18px; accent-color: var(--mgr-chili-red); }

.mgr-alert { padding: 12px 14px; border-radius: 10px; font-size: 13px; }
.mgr-alert--error { background: #fee2e2; color: #991b1b; }
.mgr-alert--warn { background: #fef3c7; color: #92400e; }
.mgr-alert p { margin: 6px 0 4px; font-size: 12px; }
.mgr-alert ul { margin: 4px 0 0; padding-left: 20px; }

@media (max-width: 720px) {
    .mgr-header { flex-direction: column; align-items: stretch; }
    .mgr-header__actions { justify-content: stretch; }
    .mgr-header__actions .mgr-btn { flex: 1; justify-content: center; }
    .mgr-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mgr-grid { grid-template-columns: 1fr; }
    .mgr-table { font-size: 12px; }
    .mgr-table thead th, .mgr-table tbody td { padding: 10px 12px; }
}
</style>
