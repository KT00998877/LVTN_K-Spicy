<template>
    <div class="mgr-recipe">
        <header class="mgr-header">
            <div class="mgr-header__intro">
                <span class="mgr-eyebrow">QUẢN LÝ THỰC ĐƠN</span>
                <h1 class="mgr-header__title">Công thức món ăn</h1>
                <p class="mgr-header__sub">
                    Mỗi món được ghép với <strong>nguyên liệu</strong> và <strong>định lượng</strong>.
                    Hệ thống dùng công thức này để <strong>tính food cost</strong> và dự kiến tiêu hao nguyên liệu.
                </p>
            </div>
            <div class="mgr-header__actions">
                <button class="mgr-btn mgr-btn--ghost" :disabled="loadingMenus" @click="loadMenus(true)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" :class="['mgr-btn__icon', { 'mgr-btn__icon--spin': loadingMenus }]">
                        <path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
                        <polyline points="21 3 21 8 16 8" />
                    </svg>
                    {{ loadingMenus ? 'Đang tải…' : 'Làm mới' }}
                </button>
                <button v-if="activeMenuItem" class="mgr-btn mgr-btn--primary" @click="openAddLine">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Thêm nguyên liệu
                </button>
            </div>
        </header>

        <div class="mgr-recipe__layout">
            <!-- =========================================================
                 LEFT — Menu item picker
                 ========================================================= -->
            <aside class="mgr-recipe__pickside" aria-label="Danh sách món">
                <div class="mgr-searchbox">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                    <input v-model.trim="menuSearch" type="search" placeholder="Tìm món…" aria-label="Tìm món" />
                </div>
                <div v-if="loadingMenus" class="mgr-mini-state">Đang tải…</div>
                <div v-else-if="!filteredMenus.length" class="mgr-mini-state">Chưa có món nào.</div>
                <ul v-else class="mgr-menulist">
                    <li v-for="m in filteredMenus" :key="m.id">
                        <button
                            type="button"
                            class="mgr-menulist__item"
                            :class="{ 'mgr-menulist__item--active': m.id === activeMenuId }"
                            @click="selectMenu(m.id)"
                        >
                            <div class="mgr-menulist__main">
                                <strong>{{ m.name }}</strong>
                                <small>{{ m.category }}</small>
                            </div>
                            <div class="mgr-menulist__meta">
                                <span>{{ formatVND(m.price) }}</span>
                                <small v-if="m.size === 'SMALL'">Nhỏ</small>
                                <small v-else-if="m.size === 'LARGE'">Lớn</small>
                            </div>
                        </button>
                    </li>
                </ul>
            </aside>

            <!-- =========================================================
                 RIGHT — Recipe editor
                 ========================================================= -->
            <section class="mgr-recipe__editor">
                <div v-if="!activeMenuItem" class="mgr-emptycard">
                    <p class="mgr-emptycard__title">Chọn một món để bắt đầu</p>
                    <p class="mgr-emptycard__hint">Mỗi món có thể có nhiều nguyên liệu kèm định lượng và hệ số hao hụt.</p>
                </div>
                <template v-else>
                    <!-- Recipe header / food cost summary -->
                    <header class="mgr-recipe__head">
                        <div>
                            <h2 class="mgr-recipe__title">{{ activeMenuItem.name }}</h2>
                            <p class="mgr-recipe__sub">
                                {{ activeMenuItem.category }} ·
                                {{ formatVND(activeMenuItem.price) }} ·
                                {{ rows.length }} nguyên liệu
                            </p>
                        </div>
                        <button class="mgr-btn mgr-btn--primary" :disabled="store.saving || !hasChanges" @click="saveRecipe">
                            {{ store.saving ? 'Đang lưu…' : (hasChanges ? 'Lưu công thức' : 'Đã lưu') }}
                        </button>
                        <div class="mgr-auto">
                            <button
                                :class="['mgr-btn', recipe?.menu_item.auto_availability_enabled ? 'mgr-btn--auto-on' : 'mgr-btn--ghost']"
                                :disabled="store.saving || hasChanges || autoSaving || !canToggleAuto"
                                :aria-pressed="recipe?.menu_item.auto_availability_enabled ? 'true' : 'false'"
                                :title="autoButtonTitle"
                                @click="toggleAuto"
                            >
                                <svg v-if="recipe?.menu_item.auto_availability_enabled" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12" /></svg>
                                {{ autoSaving ? 'Đang lưu…' : recipe?.menu_item.auto_availability_enabled ? 'Đang tự báo hết' : 'Bật tự báo hết' }}
                            </button>
                            <p v-if="!hasChanges && !canToggleAuto && !recipe?.menu_item.auto_availability_enabled" class="mgr-auto__hint">
                                Tích <strong>Trọng yếu</strong> cho ít nhất 1 nguyên liệu để có thể bật tự báo hết.
                            </p>
                            <p v-else-if="recipe?.menu_item.auto_availability_enabled" class="mgr-auto__hint mgr-auto__hint--on">
                                Tự động ẩn món khi nguyên liệu trọng yếu hết trong kho.
                            </p>
                            <p v-if="hasChanges" class="mgr-auto__hint">Lưu công thức trước khi bật/tắt tự báo hết.</p>
                            <p v-if="autoError" class="mgr-auto__error" role="alert">{{ autoError }}</p>
                            <p v-if="availabilityBanner" class="mgr-banner" :class="`mgr-banner--${availabilityBanner.tone}`" role="status">
                                <strong>{{ availabilityBanner.label }}</strong>
                                <span>{{ availabilityBanner.detail }}</span>
                            </p>
                        </div>
                    </header>

                    <div v-if="preview" class="mgr-costcard">
                        <div class="mgr-costcard__cell">
                            <span class="mgr-costcard__label">Giá bán</span>
                            <strong>{{ formatVND(preview.selling_price) }}</strong>
                        </div>
                        <div class="mgr-costcard__cell">
                            <span class="mgr-costcard__label">Chi phí NVL</span>
                            <strong>{{ formatVND(preview.ingredient_cost) }}</strong>
                        </div>
                        <div class="mgr-costcard__cell">
                            <span class="mgr-costcard__label">Lợi nhuận gộp</span>
                            <strong :class="profitClass">{{ formatVND(preview.gross_profit) }}</strong>
                        </div>
                        <div class="mgr-costcard__cell">
                            <span class="mgr-costcard__label">Food Cost %</span>
                            <strong :class="foodCostClass">{{ preview.food_cost_pct.toFixed(1) }}%</strong>
                        </div>
                    </div>

                    <!-- Loading recipe -->
                    <div v-if="loadingRecipe" class="mgr-state mgr-state--loading">
                        <span class="mgr-spinner"></span>
                        <p>Đang tải công thức…</p>
                    </div>
                    <div v-else-if="formError" class="mgr-alert mgr-alert--error">{{ formError }}</div>

                    <!-- Lines table -->
                    <table v-if="recipe" class="mgr-table">
                        <thead>
                            <tr>
                                <th>Nguyên liệu</th>
                                <th>Đơn vị</th>
                                <th class="mgr-num">Lượng cần</th>
                                <th class="mgr-num">Hao hụt</th>
                                <th class="mgr-num">Lượng dự kiến</th>
                                <th class="mgr-col-critical">
                                    <span>Trọng yếu</span>
                                    <span class="mgr-col-critical__hint" aria-label="Giải thích" title="Nguyên liệu trọng yếu dùng để tự báo hết món khi thiếu tồn. Rau/gia vị có lượng linh hoạt nên bỏ trống.">?</span>
                                </th>
                                <th class="mgr-num">Thành tiền</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.work.ingredient_id"
                                :class="{ 'mgr-row--warn': row.shortage }">
                                <td>
                                    <div class="mgr-line__ingredient">
                                        <div class="mgr-line__name-row">
                                            <strong>{{ row.meta.ingredient_name }}</strong>
                                            <span v-if="row.work.availability_required" class="mgr-pill mgr-pill--critical" title="Nguyên liệu trọng yếu — thiếu sẽ khiến món tự động báo hết khi bật tự báo hết">Trọng yếu</span>
                                        </div>
                                        <small class="mgr-mute">{{ row.meta.ingredient_code }}</small>
                                        <small v-if="row.meta.ingredient_active === false" class="mgr-warn-inline">Đã ẩn</small>
                                    </div>
                                </td>
                                <td>
                                    <select v-model="row.work.unit_override" class="mgr-inline-input">
                                        <option :value="null">— {{ row.meta.ingredient_unit }} —</option>
                                        <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
                                    </select>
                                </td>
                                <td class="mgr-num">
                                    <input
                                        v-model.number="row.work.quantity_required"
                                        type="number" min="0.001" step="0.001"
                                        class="mgr-inline-input mgr-inline-input--num"
                                    />
                                </td>
                                <td class="mgr-num">
                                    <input
                                        v-model.number="row.work.waste_factor"
                                        type="number" min="1" max="9.999" step="0.01"
                                        class="mgr-inline-input mgr-inline-input--num"
                                    />
                                </td>
                                <td class="mgr-num mgr-mute">
                                    {{ formatNumber(row.work.quantity_required * row.work.waste_factor) }}
                                    {{ row.work.unit_override || row.meta.ingredient_unit }}
                                </td>
                                <td>
                                    <label class="mgr-critical-toggle">
                                        <input v-model="row.work.availability_required" type="checkbox" :aria-label="`Đánh dấu ${row.meta.ingredient_name} là nguyên liệu trọng yếu`" />
                                        <span aria-hidden="true">{{ row.work.availability_required ? 'Có' : 'Không' }}</span>
                                    </label>
                                </td>
                                <td class="mgr-num mgr-money">
                                    {{ formatVND(lineCost(row.work)) }}
                                </td>
                                <td>
                                    <button type="button" class="mgr-linkbtn mgr-linkbtn--danger"
                                            :disabled="store.saving" @click="removeLine(row.work.ingredient_id)">Xoá</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="recipe && !rows.length" class="mgr-emptycard mgr-emptycard--inset">
                        <p class="mgr-emptycard__title">Chưa có nguyên liệu nào</p>
                        <p class="mgr-emptycard__hint">Bấm <strong>Thêm nguyên liệu</strong> để bắt đầu xây dựng công thức.</p>
                    </div>

                    <p class="mgr-footnote">
                        Chỉ đánh dấu “Trọng yếu” cho nguyên liệu có định lượng ổn định và cần để bán món. Tự báo hết chỉ bật được sau khi đã lưu công thức và chốt sổ tồn đầu tiên.
                        Hệ số hao hụt dùng cho lượng dự kiến; đơn vị thay thế phải trùng đơn vị kho hoặc có quy đổi được bếp xác nhận.
                    </p>
                </template>
            </section>
        </div>

        <!-- =========================================================
             PICKER DIALOG — chọn nguyên liệu thêm vào công thức
             ========================================================= -->
        <dialog ref="pickerDialog" class="mgr-dialog" @cancel="closePicker" @click="onPickerBackdrop">
            <form class="mgr-form" @submit.prevent="confirmAddLine">
                <header class="mgr-form__head">
                    <h2 class="mgr-form__title">Thêm nguyên liệu vào công thức</h2>
                    <button type="button" class="mgr-iconbtn" :disabled="store.saving" @click="closePicker">×</button>
                </header>
                <div class="mgr-form__body">
                    <label class="mgr-search mgr-search--block">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8" />
                            <path d="M21 21l-4.35-4.35" />
                        </svg>
                        <input v-model.trim="picker.q" type="search" placeholder="Tìm nguyên liệu…" />
                    </label>
                    <div v-if="loadingIngredients" class="mgr-mini-state">Đang tải danh sách nguyên liệu…</div>
                    <ul v-else class="mgr-picklist">
                        <li v-for="ing in pickerResults" :key="ing.id">
                            <button
                                type="button"
                                class="mgr-picklist__item"
                                :class="{ 'mgr-picklist__item--chosen': picker.selectedId === ing.id }"
                                @click="picker.selectedId = ing.id"
                            >
                                <strong>{{ ing.name }}</strong>
                                <small>{{ ing.code }} · tồn {{ formatNumber(ing.quantity_in_stock) }} {{ ing.unit }}</small>
                            </button>
                        </li>
                        <li v-if="!pickerResults.length" class="mgr-mini-state">
                            Không tìm thấy nguyên liệu nào.
                        </li>
                    </ul>
                </div>
                <footer class="mgr-form__foot">
                    <button type="button" class="mgr-btn mgr-btn--ghost" :disabled="store.saving" @click="closePicker">Huỷ</button>
                    <button type="submit" class="mgr-btn mgr-btn--primary" :disabled="store.saving || !picker.selectedId">
                        Thêm vào công thức
                    </button>
                </footer>
            </form>
        </dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import apiClient from '@/api/client'
import { useIngredientsStore } from '@/stores/managerIngredients'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { ALLOWED_UNITS, ingredientsApi, type Ingredient, type RecipeLine, type RecipeResponse } from '@/api/ingredients'

const store = useIngredientsStore()

interface MenuItem {
    id: number
    name: string
    price: number
    cost_price: number
    is_active: boolean
    is_available: boolean
    category_id: number
    category: string | null
    size: 'SMALL' | 'LARGE' | null
}

interface WorkingLine {
    ingredient_id: number
    quantity_required: number
    waste_factor: number
    unit_override: string | null
    availability_required: boolean
}

const menus = ref<MenuItem[]>([])
const menuSearch = ref('')
const activeMenuId = ref<number | null>(null)
const loadingMenus = ref(false)
const loadingRecipe = ref(false)
const autoSaving = ref(false)
/** Lỗi cục bộ của riêng thao tác bật/tắt tự báo hết, hiển thị ngay cạnh button. */
const autoError = ref('')
/** Có ít nhất 1 nguyên liệu đang được đánh dấu Trọng yếu (mọi nguyên liệu, không chỉ trong working). */
const hasCriticalLine = computed(() => workingLines.value.some((l) => l.availability_required))
/** Backend yêu cầu: công thức đã lưu + có ít nhất 1 NL trọng yếu mới cho phép bật. */
const canToggleAuto = computed(() => hasCriticalLine.value)
const formError = ref('')

/** Lý do món đang bị ẩn — lấy từ MenuAvailabilityService để manager biết vì sao. */
type AvailabilityReason = 'manual' | 'ingredient' | 'insufficient_data' | null
const effectiveAvailability = ref<{ is_available: boolean; reason: AvailabilityReason } | null>(null)
const loadingAvailability = ref(false)

const recipe = ref<RecipeResponse | null>(null)
const workingLines = ref<WorkingLine[]>([])
const savedLines = ref<WorkingLine[]>([])

const pickerDialog = ref<HTMLDialogElement | null>(null)
const pickerResults = ref<Ingredient[]>([])
const loadingIngredients = ref(false)
const picker = reactive<{ q: string; selectedId: number | null }>({ q: '', selectedId: null })

const unitOptions = [...ALLOWED_UNITS]

const filteredMenus = computed<MenuItem[]>(() => {
    const kw = menuSearch.value.trim().toLocaleLowerCase('vi')
    if (!kw) return menus.value
    return menus.value.filter((m) => m.name.toLocaleLowerCase('vi').includes(kw))
})

const activeMenuItem = computed<MenuItem | null>(() => menus.value.find((m) => m.id === activeMenuId.value) ?? null)

const hasChanges = computed(() => {
    if (!recipe.value) return false
    const cur = workingLines.value.map((l) => ({ ...l }))
    return JSON.stringify(savedLines.value) !== JSON.stringify(cur)
})

const profitClass = computed(() => {
    if (!preview.value) return ''
    if (preview.value.gross_profit <= 0) return 'mgr-num--danger'
    if (preview.value.food_cost_pct >= 40) return 'mgr-num--warn'
    return ''
})

const foodCostClass = computed(() => {
    if (!preview.value) return ''
    if (preview.value.food_cost_pct >= 45) return 'mgr-num--danger'
    if (preview.value.food_cost_pct >= 35) return 'mgr-num--warn'
    return 'mgr-num--ok'
})

/** Tooltip giải thích lý do button bị disable hoặc hành động khi nhấn. */
const autoButtonTitle = computed(() => {
    if (!recipe.value) return 'Đang tải công thức'
    if (hasChanges.value) return 'Lưu công thức trước khi bật/tắt tự báo hết'
    if (recipe.value.menu_item.auto_availability_enabled) return 'Bấm để tắt chế độ tự báo hết món'
    if (!canToggleAuto.value) return 'Cần đánh dấu ít nhất 1 nguyên liệu là Trọng yếu'
    return 'Bấm để bật tự báo hết khi nguyên liệu trọng yếu hết'
})

/**
 * Banner cảnh báo khi món đang bị ẩn — đồng bộ tone với MenuCard ở trang khách.
 * Chỉ hiện khi:
 *  - auto_availability_enabled=true
 *  - effective_availability.reason != null (nghĩa là hệ thống tự phát hiện nguyên liệu thiếu/dữ liệu không đủ)
 * Manual sold_out_reason do bếp báo vẫn hiện ở bảng khác; ở đây tập trung vào auto.
 */
const availabilityBanner = computed<{ tone: 'warn' | 'muted' | 'manual'; label: string; detail: string } | null>(() => {
    if (!recipe.value?.menu_item.auto_availability_enabled) return null
    const reason = effectiveAvailability.value?.reason
    if (!reason) return null
    const soldOutReason = (recipe.value.menu_item.sold_out_reason as string | null | undefined) ?? null
    if (reason === 'ingredient') {
        return {
            tone: 'warn',
            label: 'Tạm hết nguyên liệu',
            detail: soldOutReason ?? 'Bếp đang chờ nhập hàng.',
        }
    }
    if (reason === 'insufficient_data') {
        return {
            tone: 'muted',
            label: 'Tạm hết món',
            detail: soldOutReason ?? 'Bếp đang kiểm tra lại định lượng.',
        }
    }
    return {
        tone: 'manual',
        label: 'Bếp vừa báo hết',
        detail: soldOutReason ?? 'Bếp trưởng vừa báo hết món.',
    }
})

async function loadMenus(quiet = false) {
    if (!quiet) loadingMenus.value = true
    try {
        const res = await apiClient.get<{ data: MenuItem[] }>('/staff/dashboard/menu-items')
        menus.value = res.data.data
        if (!activeMenuId.value && menus.value.length) {
            await selectMenu(menus.value[0]!.id)
        }
    } finally {
        loadingMenus.value = false
    }
}

async function selectMenu(menuItemId: number) {
    activeMenuId.value = menuItemId
    recipe.value = null
    workingLines.value = []
    savedLines.value = []
    formError.value = ''
    autoError.value = ''
    effectiveAvailability.value = null
    loadingRecipe.value = true
    try {
        const data = await store.fetchRecipe(menuItemId, true)
        recipe.value = data
        workingLines.value = data.lines.map((l) => ({
            ingredient_id: l.ingredient_id,
            quantity_required: Number(l.quantity_required),
            waste_factor: Number(l.waste_factor),
            unit_override: l.unit_override || null,
            availability_required: !!l.availability_required,
        }))
        savedLines.value = workingLines.value.map((line) => ({ ...line }))
        syncUnitPrices(data.lines)
        // Tải lý do khả dụng hiệu lực để cảnh báo manager nếu món đang auto-ẩn.
        // Lỗi fetch không chặn flow — chỉ ẩn banner, manager vẫn dùng được công thức.
        if (recipe.value.menu_item.is_active) void loadAvailability()
    } catch (err) {
        formError.value = err instanceof Error ? err.message : 'Không tải được công thức.'
    } finally {
        loadingRecipe.value = false
    }
}

async function loadAvailability() {
    if (!activeMenuId.value) return
    loadingAvailability.value = true
    try {
        const res = await apiClient.get<{
            data: Array<{ id: number; effective_availability: { is_available: boolean; reason: AvailabilityReason } }>
        }>('/staff/menu-items/availability')
        const row = res.data.data.find((x) => x.id === activeMenuId.value)
        if (row) effectiveAvailability.value = row.effective_availability
    } catch {
        // Im lặng — banner sẽ ẩn, đây là thông tin phụ không chặn thao tác chính.
    } finally {
        loadingAvailability.value = false
    }
}

/** Giá nhập của từng nguyên liệu để tính thành tiền ngay khi đang sửa.
 *  Lấy từ server qua `line_cost / quantity_required`, nên khớp đúng công thức
 *  của RecipeService::summarize (chỉ nhân quantity_required, không nhân waste_factor). */
const unitPrices = ref<Map<number, number>>(new Map())
/** Nguồn sự thật duy nhất cho bảng: mỗi dòng ghép metadata từ server với giá trị đang sửa.
 *  `shortage` tính lại từ lượng đang sửa × hao hụt nên phản ánh ngay khi manager tăng định lượng. */
const rows = computed(() => workingLines.value.map((work) => {
    const meta = recipe.value?.lines.find((l) => l.ingredient_id === work.ingredient_id)
    const expected = (Number(work.quantity_required) || 0) * (Number(work.waste_factor) || 0)
    const stock = meta?.ingredient_in_stock
    return {
        work,
        // Metadata luôn tồn tại sau khi nạp/lưu; fallback rỗng chỉ để template không cần guard.
        meta: meta ?? ({} as RecipeLine),
        shortage: stock !== undefined && expected > stock,
    }
}))

const unitPriceOf = (ingredientId: number): number => unitPrices.value.get(ingredientId) ?? 0

/** Suy ra đơn giá từ line_cost / quantity_required để preview khớp công thức backend.
 *  line_cost = quantity_required × import_price → import_price = line_cost / quantity_required. */
function syncUnitPrices(lines: RecipeLine[]): void {
    const next = new Map<number, number>()
    for (const l of lines) {
        const qty = Number(l.quantity_required)
        const cost = Number(l.line_cost)
        if (qty > 0 && cost >= 0) next.set(l.ingredient_id, cost / qty)
    }
    unitPrices.value = next
}

function lineCost(line: WorkingLine): number {
    return (Number(line.quantity_required) || 0) * unitPriceOf(line.ingredient_id)
}

/** Tóm tắt tính lại từ dòng đang sửa — khi không sửa gì thì bằng đúng giá trị server trả về. */
const preview = computed(() => {
    const base = recipe.value?.summary
    if (!base) return null
    const cost = workingLines.value.reduce((sum, l) => sum + lineCost(l), 0)
    const price = Number(base.selling_price)
    const rounded = Math.round(cost * 100) / 100
    return {
        selling_price: price,
        ingredient_cost: rounded,
        gross_profit: Math.round(Math.max(0, price - rounded) * 100) / 100,
        food_cost_pct: price > 0 ? Math.round((rounded / price) * 1000) / 10 : 0,
    }
})

function removeLine(ingredientId: number) {
    workingLines.value = workingLines.value.filter((l) => l.ingredient_id !== ingredientId)
}

async function openAddLine() {
    if (!activeMenuId.value) return
    picker.q = ''
    picker.selectedId = null
    pickerResults.value = []
    pickerDialog.value?.showModal()
    loadingIngredients.value = true
    try {
        const res = await ingredientsApi.list({ is_active: true, per_page: 100 })
        const taken = new Set(workingLines.value.map((l) => l.ingredient_id))
        pickerResults.value = res.data.filter((x) => !taken.has(x.id))
    } finally {
        loadingIngredients.value = false
    }
}

function closePicker() {
    pickerDialog.value?.close()
}

function onPickerBackdrop(e: MouseEvent) {
    if (e.target === pickerDialog.value) closePicker()
}

function confirmAddLine() {
    const ing = pickerResults.value.find((x) => x.id === picker.selectedId)
    if (!ing) return
    const defaultQty = ['gói', 'chai', 'lon', 'hộp', 'quả', 'cái', 'cục', 'cây', 'phần'].includes(ing.unit) ? 1 : 0.1
    workingLines.value.push({
        ingredient_id: ing.id,
        quantity_required: defaultQty,
        waste_factor: 1.000,
        unit_override: null,
        availability_required: false,
    })
    // Dòng mới chưa có trong recipe.lines → tự thêm metadata để bảng render được ngay
    // và ghi đơn giá để preview thành tiền tính đúng ngay khi đang sửa.
    if (recipe.value) {
        recipe.value.lines.push({
            id: undefined,
            ingredient_id: ing.id,
            ingredient_code: ing.code,
            ingredient_name: ing.name,
            ingredient_unit: ing.unit,
            ingredient_active: ing.is_active,
            ingredient_in_stock: ing.quantity_in_stock,
            quantity_required: defaultQty,
            waste_factor: 1,
            unit_override: null,
            availability_required: false,
            effective_quantity: defaultQty,
            display_unit: ing.unit,
            line_cost: defaultQty * Number(ing.import_price),
        })
    }
    unitPrices.value = new Map(unitPrices.value).set(ing.id, Number(ing.import_price))
    closePicker()
}

async function saveRecipe() {
    if (!activeMenuId.value || !hasChanges.value || store.saving) return
    autoError.value = ''
    try {
        const payload = {
            lines: workingLines.value.map((l) => ({
                ingredient_id: l.ingredient_id,
                quantity_required: Number(l.quantity_required),
                waste_factor: Number(l.waste_factor),
                unit_override: l.unit_override,
                availability_required: l.availability_required,
            })),
        }
        const data = await store.replaceRecipe(activeMenuId.value, payload)
        recipe.value = data
        workingLines.value = data.lines.map((l) => ({
            ingredient_id: l.ingredient_id,
            quantity_required: Number(l.quantity_required),
            waste_factor: Number(l.waste_factor),
            unit_override: l.unit_override || null,
            availability_required: !!l.availability_required,
        }))
        savedLines.value = workingLines.value.map((line) => ({ ...line }))
        syncUnitPrices(data.lines)
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
        if (e?.response?.data?.errors) {
            formError.value = Object.values(e.response.data.errors).flat().join(' ')
        } else {
            formError.value = e?.response?.data?.message ?? (err as Error).message ?? 'Không lưu được công thức.'
        }
    }
}

async function toggleAuto() {
    if (!activeMenuId.value || !recipe.value || hasChanges.value || !canToggleAuto.value) return
    autoSaving.value = true
    autoError.value = ''
    formError.value = ''
    const intended = !recipe.value.menu_item.auto_availability_enabled
    try {
        // Backend trả về toàn bộ menu_item mới; dùng nó để tránh lệch state nếu backend từ chối bật (422).
        const res = await apiClient.patch<{ data: { auto_availability_enabled: boolean } }>(
            `/staff/menu-items/${activeMenuId.value}/auto-availability`,
            { enabled: intended },
        )
        if (recipe.value) recipe.value.menu_item.auto_availability_enabled = !!res.data.data.auto_availability_enabled
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        autoError.value = e.response?.data?.message || 'Không cập nhật được tự báo hết.'
    } finally { autoSaving.value = false }
}

/** Lọc cục bộ trên kết quả vừa tải từ server. KHÔNG dùng store.list —
 *  store.list chỉ được fill khi manager đã mở trang Nguyên liệu trước đó. */
watch(() => picker.q, () => {
    const kw = picker.q.trim().toLowerCase()
    if (!kw) return
    pickerResults.value = pickerResults.value.filter((x) =>
        x.name.toLowerCase().includes(kw) || x.code.toLowerCase().includes(kw))
})

onMounted(() => loadMenus())

useRealtimeRefresh(() => loadMenus(true), () => !loadingMenus.value && !store.saving)

function formatVND(value: number): string {
    return Number(value).toLocaleString('vi-VN') + ' đ'
}
function formatNumber(value: number): string {
    return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 3 })
}
</script>

<style scoped>
.mgr-recipe { display: flex; flex-direction: column; gap: 24px; }

/* ---- Header (same shape as IngredientsView) ---- */
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

.mgr-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 9999px;
           font-size: 14px; font-weight: 600; font-family: inherit; cursor: pointer; border: 1.5px solid transparent;
           transition: background-color 150ms ease, color 150ms ease, transform 150ms ease; }
.mgr-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.mgr-btn--primary { background: var(--mgr-chili-red); color: #fff; }
.mgr-btn--primary:hover:not(:disabled) { background: #a80f17; transform: translateY(-1px); }
.mgr-btn--ghost { background: #fff; border-color: var(--mgr-gray-200); color: var(--mgr-charcoal-black); }
.mgr-btn--ghost:hover:not(:disabled) { border-color: var(--mgr-chili-red); color: var(--mgr-chili-red); }
.mgr-btn__icon--spin { animation: mgr-spin 0.9s linear infinite; }
@keyframes mgr-spin { to { transform: rotate(360deg); } }

/* ---- Layout ---- */
.mgr-recipe__layout { display: grid; grid-template-columns: 320px 1fr; gap: 20px; align-items: start; }
@media (max-width: 960px) { .mgr-recipe__layout { grid-template-columns: 1fr; } }

.mgr-recipe__pickside { background: #fff; border: 1px solid var(--mgr-gray-200); border-radius: 16px;
                        padding: 12px; display: flex; flex-direction: column; gap: 10px; max-height: 70vh; overflow: hidden; }
.mgr-searchbox { display: flex; align-items: center; gap: 8px; padding: 8px 12px;
                 border-radius: 9999px; background: var(--mgr-cream-dark, #fff5e6);
                 color: var(--mgr-gray-700); }
.mgr-searchbox input { border: 0; background: transparent; font: inherit; outline: none; flex: 1;
                       color: var(--mgr-charcoal-black); }
.mgr-menulist { list-style: none; padding: 0; margin: 0; overflow-y: auto; flex: 1;
                display: flex; flex-direction: column; gap: 4px; }
.mgr-menulist__item { width: 100%; display: flex; align-items: center; justify-content: space-between;
                       gap: 12px; padding: 10px 12px; border-radius: 10px; border: 0; background: transparent;
                       cursor: pointer; text-align: left; font: inherit; color: var(--mgr-charcoal-black); }
.mgr-menulist__item:hover { background: var(--mgr-gray-50); }
.mgr-menulist__item--active { background: var(--mgr-chili-red); color: #fff; }
.mgr-menulist__item--active small { color: rgba(255, 255, 255, 0.85); }
.mgr-menulist__main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.mgr-menulist__main strong { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
.mgr-menulist__main small { font-size: 11px; color: var(--mgr-gray-500); text-transform: uppercase; letter-spacing: 0.5px; }
.mgr-menulist__meta { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.mgr-menulist__meta span { font-weight: 800; font-size: 13px; font-variant-numeric: tabular-nums; }
.mgr-menulist__meta small { font-size: 10px; color: var(--mgr-gray-500); text-transform: uppercase; }

.mgr-recipe__editor { background: #fff; border: 1px solid var(--mgr-gray-200); border-radius: 16px;
                      padding: 20px 24px; display: flex; flex-direction: column; gap: 18px; }

.mgr-recipe__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mgr-recipe__head-actions { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.mgr-auto { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; min-width: 220px; }
.mgr-auto__hint { font-size: 12px; color: var(--mgr-gray-600); margin: 0; text-align: right; max-width: 320px; line-height: 1.4; }
.mgr-auto__hint strong { color: var(--mgr-chili-red); }
.mgr-auto__hint--on { color: var(--mgr-chili-red); font-weight: 600; }
.mgr-auto__error { font-size: 12px; color: #b91c1c; background: #fee2e2; padding: 6px 10px; border-radius: 8px; margin: 0; text-align: right; max-width: 320px; line-height: 1.4; }
/* Banner cảnh báo lý do món đang bị ẩn — đồng bộ tone với MenuCard ở trang khách. */
.mgr-banner { font-size: 12px; padding: 8px 12px; border-radius: 8px; margin: 0; text-align: right; max-width: 320px; line-height: 1.45; display: flex; flex-direction: column; gap: 2px; }
.mgr-banner strong { font-size: 13px; font-weight: 700; }
.mgr-banner span { color: var(--mgr-gray-700); }
.mgr-banner--warn   { background: #fff4e5; color: #c2410c; border: 1px solid #fed7aa; }       /* thiếu nguyên liệu */
.mgr-banner--muted  { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }       /* chưa đủ dữ liệu */
.mgr-banner--manual { background: #fee2e2; color: #8b1e24; border: 1px solid #fecaca; }       /* bếp báo hết */
.mgr-btn--auto-on { background: #fff5e6; border-color: var(--mgr-chili-red); color: var(--mgr-chili-red); }
.mgr-btn--auto-on:hover:not(:disabled) { background: var(--mgr-chili-red); color: #fff; }
@media (max-width: 640px) { .mgr-auto { align-items: stretch; } .mgr-auto__hint, .mgr-auto__error, .mgr-banner { text-align: left; } }
.mgr-recipe__title { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -0.3px; }
.mgr-recipe__sub { margin: 4px 0 0; font-size: 12px; color: var(--mgr-gray-500); }

.mgr-costcard { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;
                padding: 14px; background: var(--mgr-cream-dark, #fff5e6); border-radius: 12px; }
.mgr-costcard__cell { display: flex; flex-direction: column; gap: 4px; }
.mgr-costcard__label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
                       font-weight: 800; color: var(--mgr-gray-600); }
.mgr-costcard__cell strong { font-size: 18px; font-weight: 800; font-variant-numeric: tabular-nums;
                              color: var(--mgr-charcoal-black); }

.mgr-num--ok { color: #15803d; }
.mgr-num--warn { color: #b45309; }
.mgr-num--danger { color: #b91c1c; }

.mgr-state { display: flex; align-items: center; gap: 14px; padding: 18px 22px; border-radius: 14px; font-size: 14px; }
.mgr-state--loading { background: var(--mgr-cream-dark, #fff5e6); color: var(--mgr-gray-600); }
.mgr-spinner { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--mgr-gray-300);
               border-top-color: var(--mgr-chili-red); animation: mgr-spin 0.8s linear infinite; }

.mgr-alert { padding: 12px 14px; border-radius: 10px; font-size: 13px; }
.mgr-alert--error { background: #fee2e2; color: #991b1b; }

.mgr-emptycard { padding: 56px 24px; text-align: center; color: var(--mgr-gray-500); }
.mgr-emptycard--inset { background: var(--mgr-gray-50); border-radius: 12px; }
.mgr-emptycard__title { font-size: 16px; font-weight: 700; color: var(--mgr-charcoal-black); margin: 0 0 6px; }
.mgr-emptycard__hint { font-size: 13px; color: var(--mgr-gray-500); margin: 0; }

.mgr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mgr-table thead th { background: var(--mgr-cream-dark, #fff5e6); padding: 12px 14px;
                      text-align: left; font-size: 11px; text-transform: uppercase;
                      letter-spacing: 1px; color: var(--mgr-gray-700); font-weight: 800;
                      border-bottom: 1px solid var(--mgr-gray-200); }
.mgr-table tbody td { padding: 10px 14px; border-bottom: 1px solid var(--mgr-gray-100); vertical-align: middle; }
.mgr-table tbody tr:last-child td { border-bottom: 0; }
.mgr-num { text-align: right; font-variant-numeric: tabular-nums; }
.mgr-mute { color: var(--mgr-gray-500); font-size: 12px; }
.mgr-money { font-weight: 700; color: var(--mgr-charcoal-black); }
.mgr-row--warn td { background: rgba(180, 83, 9, 0.04); }

.mgr-line__ingredient { display: flex; flex-direction: column; gap: 2px; }
.mgr-line__name-row { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.mgr-line__ingredient strong { font-weight: 700; }
.mgr-warn-inline { color: #b45309; font-weight: 700; }

.mgr-pill { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.2px; }
.mgr-pill--critical { background: var(--mgr-chili-red); color: #fff; }

.mgr-col-critical { display: flex; align-items: center; gap: 6px; }
.mgr-col-critical__hint { display: inline-flex; align-items: center; justify-content: center;
                          width: 16px; height: 16px; border-radius: 50%; background: var(--mgr-gray-100);
                          color: var(--mgr-gray-600); font-size: 11px; font-weight: 700; cursor: help; }
.mgr-critical-toggle { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; }
.mgr-critical-toggle input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--mgr-chili-red); }

.mgr-inline-input { font: inherit; padding: 6px 8px; border: 1px solid var(--mgr-gray-300);
                    border-radius: 8px; background: #fff; min-width: 80px; color: var(--mgr-charcoal-black); }
.mgr-inline-input--num { max-width: 100px; text-align: right; font-variant-numeric: tabular-nums; }
.mgr-inline-input:focus { outline: 2px solid var(--mgr-chili-red); border-color: var(--mgr-chili-red); }

.mgr-linkbtn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px;
               padding: 8px 14px; border: 1px solid #d9c8c2; border-radius: 9px; background: #fff8f4;
               color: var(--mgr-chili-red); font-weight: 700; font-size: 13px; cursor: pointer;
               transition: background-color .15s ease, border-color .15s ease, transform .15s ease; }
.mgr-linkbtn:hover:not(:disabled) { background: #fff0ea; border-color: #c9988f; transform: translateY(-1px); }
.mgr-linkbtn:active:not(:disabled) { transform: translateY(0); }
.mgr-linkbtn:focus-visible { outline: 3px solid var(--mgr-chili-red, #d71920); outline-offset: 2px; }
.mgr-linkbtn:disabled { opacity: .55; cursor: not-allowed; }
.mgr-linkbtn--danger { border-color: #fecaca; background: #fff1f2; color: #991b1b; }
.mgr-linkbtn--danger:hover:not(:disabled) { border-color: #fca5a5; background: #ffe4e6; color: #7f1d1d; }

.mgr-footnote { font-size: 12px; color: var(--mgr-gray-500); line-height: 1.6; margin: 0;
                padding-top: 12px; border-top: 1px dashed var(--mgr-gray-200); }
.mgr-footnote strong { color: var(--mgr-charcoal-black); }

/* ---- Dialog ---- */
.mgr-dialog { width: min(640px, calc(100% - 32px)); max-height: 90vh; padding: 0; border: 0;
              border-radius: 16px; background: var(--mgr-cream, #fff8ee); color: var(--mgr-charcoal-black); font-family: inherit; }
.mgr-dialog::backdrop { background: rgba(17, 17, 17, 0.55); }
.mgr-form { display: flex; flex-direction: column; max-height: 90vh; }
.mgr-form__head { padding: 18px 24px; display: flex; align-items: center; justify-content: space-between;
                  border-bottom: 1px solid var(--mgr-gray-200); }
.mgr-form__title { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -0.3px; }
.mgr-iconbtn { width: 32px; height: 32px; border-radius: 50%; border: 0; background: var(--mgr-gray-100);
               color: var(--mgr-gray-700); font-size: 22px; line-height: 1; cursor: pointer; display: flex;
               align-items: center; justify-content: center; }
.mgr-iconbtn:hover:not(:disabled) { background: var(--mgr-gray-200); }
.mgr-form__body { padding: 24px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; }
.mgr-form__foot { padding: 16px 24px; display: flex; gap: 10px; justify-content: flex-end;
                  border-top: 1px solid var(--mgr-gray-200); background: #fff; }

.mgr-search--block { width: 100%; }

.mgr-picklist { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px;
                max-height: 50vh; overflow-y: auto; }
.mgr-picklist__item { width: 100%; display: flex; flex-direction: column; gap: 2px; padding: 12px 14px;
                      border-radius: 10px; border: 1px solid var(--mgr-gray-200); background: #fff;
                      cursor: pointer; text-align: left; font: inherit; color: var(--mgr-charcoal-black); }
.mgr-picklist__item:hover { border-color: var(--mgr-chili-red); }
.mgr-picklist__item--chosen { background: var(--mgr-chili-red); color: #fff; border-color: var(--mgr-chili-red); }
.mgr-picklist__item--chosen small { color: rgba(255, 255, 255, 0.85); }
.mgr-picklist__item strong { font-weight: 700; }
.mgr-picklist__item small { font-size: 11px; color: var(--mgr-gray-500); }

.mgr-mini-state { padding: 14px; text-align: center; font-size: 13px; color: var(--mgr-gray-500); }
</style>
