<template>
  <dialog ref="dialog" class="purchase-order-dialog" @cancel="onCancel">
    <form @submit.prevent="submit">
      <header class="purchase-order-dialog__header">
        <div>
          <h2>{{ editing ? `Sửa yêu cầu ${editing.code}` : 'Tạo yêu cầu nhập hàng' }}</h2>
          <p class="purchase-order-help">Lưu bản nháp trước khi gửi Nhà cung cấp K.</p>
        </div>
        <button class="purchase-order-dialog__close" type="button" aria-label="Đóng form" :disabled="saving" @click="close">×</button>
      </header>

      <div class="purchase-order-dialog__body">
        <div v-if="error" ref="errorSummary" class="purchase-order-error-summary" role="alert" tabindex="-1">
          <strong>Chưa thể lưu yêu cầu.</strong> {{ error }}
        </div>

        <div class="purchase-order-form-grid">
          <div class="purchase-order-field">
            <label for="po-supplier">Nhà cung cấp</label>
            <input id="po-supplier" class="purchase-order-input" value="Nhà cung cấp K" readonly />
          </div>
          <div class="purchase-order-field">
            <label for="po-delivery-date">Ngày cần giao <span class="purchase-order-muted">(không bắt buộc)</span></label>
            <input id="po-delivery-date" v-model="form.requested_delivery_date" class="purchase-order-input" type="date" />
          </div>
          <div class="purchase-order-field purchase-order-form-grid__wide">
            <label for="po-manager-note">Ghi chú cho nhà cung cấp <span class="purchase-order-muted">(không bắt buộc)</span></label>
            <textarea id="po-manager-note" v-model.trim="form.manager_note" class="purchase-order-textarea" maxlength="2000"></textarea>
          </div>
        </div>

        <fieldset class="purchase-order-section">
          <div class="purchase-order-section__heading">
            <div>
              <legend class="purchase-order-field__label">Nguyên liệu cần nhập</legend>
              <p class="purchase-order-help">Chọn đơn vị mua và nhập số lượng cần đặt.</p>
            </div>
          </div>

          <div class="purchase-order-lines">
            <div v-for="(line, index) in form.items" :key="line.key" class="purchase-order-line">
              <div class="purchase-order-field">
                <label :for="`po-ingredient-${line.key}`">Nguyên liệu</label>
                <select :id="`po-ingredient-${line.key}`" v-model.number="line.ingredient_id" class="purchase-order-select" :class="{ 'purchase-order-select--out': ingredientFor(line)?.quantity_in_stock === 0 }" required @change="selectIngredient(line)">
                  <option :value="0" disabled>Chọn nguyên liệu</option>
                  <optgroup v-if="outOfStockIngredients.length" label="Hết hàng — ưu tiên nhập">
                    <option v-for="ingredient in outOfStockIngredients" :key="ingredient.id" :value="ingredient.id">HẾT HÀNG · {{ ingredient.code }} · {{ ingredient.name }}</option>
                  </optgroup>
                  <optgroup v-if="availableIngredients.length" label="Còn hàng">
                    <option v-for="ingredient in availableIngredients" :key="ingredient.id" :value="ingredient.id">{{ ingredient.code }} · {{ ingredient.name }} · tồn {{ formatQuantity(ingredient.quantity_in_stock) }} {{ ingredient.unit }}</option>
                  </optgroup>
                </select>
              </div>
              <div class="purchase-order-field">
                <label :for="`po-packaging-${line.key}`">Quy cách mua</label>
                <select :id="`po-packaging-${line.key}`" v-model="line.ingredient_packaging_id" class="purchase-order-select">
                  <option :value="null">{{ ingredientUnit(line) || 'Đơn vị tồn kho' }}</option>
                  <option v-for="packaging in packagingOptions(line)" :key="packaging.id" :value="packaging.id">
                    {{ packaging.purchase_unit }} ({{ formatQuantity(packaging.conversion_quantity) }} {{ ingredientUnit(line) }})
                  </option>
                </select>
              </div>
              <div class="purchase-order-field">
                <label :for="`po-quantity-${line.key}`">Số lượng ({{ purchaseUnit(line) }})</label>
                <input :id="`po-quantity-${line.key}`" v-model.number="line.requested_purchase_quantity" class="purchase-order-input" type="number" min="1" step="1" required />
                <span v-if="selectedPackaging(line)" class="purchase-order-help">= {{ formatQuantity(stockQuantity(line)) }} {{ ingredientUnit(line) }} tồn kho</span>
              </div>
              <button class="purchase-order-button purchase-order-button--quiet purchase-order-button--danger" type="button" :disabled="form.items.length === 1" :aria-label="`Xóa dòng ${index + 1}`" @click="removeLine(index)">
                Xóa
              </button>
            </div>
          </div>
          <button class="purchase-order-button purchase-order-button--quiet purchase-order-add-line" type="button" aria-label="Thêm dòng nguyên liệu" title="Thêm dòng nguyên liệu" @click="addLine">+</button>
        </fieldset>
      </div>

      <footer class="purchase-order-dialog__footer">
        <button class="purchase-order-button" type="button" :disabled="saving" @click="close">Đóng</button>
        <button class="purchase-order-button purchase-order-button--primary" type="submit" :disabled="saving">
          {{ saving ? 'Đang lưu…' : 'Lưu bản nháp' }}
        </button>
      </footer>
    </form>
  </dialog>
</template>

<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue'
import type { Ingredient, IngredientPackaging } from '@/api/ingredients'
import {
  purchaseOrderError,
  purchaseOrdersApi,
  type PurchaseOrder,
  type PurchaseOrderPayload,
} from '@/api/purchaseOrders'

interface FormLine {
  key: number
  ingredient_id: number
  ingredient_packaging_id: number | null
  requested_purchase_quantity: number
}

const props = defineProps<{ ingredients: Ingredient[] }>()
const emit = defineEmits<{ saved: [order: PurchaseOrder] }>()

const dialog = ref<HTMLDialogElement | null>(null)
const errorSummary = ref<HTMLElement | null>(null)
const editing = ref<PurchaseOrder | null>(null)
const saving = ref(false)
const error = ref('')
let lineKey = 0

const form = reactive<{
  requested_delivery_date: string
  manager_note: string
  items: FormLine[]
}>({
  requested_delivery_date: '',
  manager_note: '',
  items: [],
})

const activeIngredients = computed(() => props.ingredients.filter((ingredient) => ingredient.is_active))
const outOfStockIngredients = computed(() => activeIngredients.value.filter((ingredient) => ingredient.quantity_in_stock === 0))
const availableIngredients = computed(() => activeIngredients.value.filter((ingredient) => ingredient.quantity_in_stock > 0).sort((a, b) => Number(a.quantity_in_stock > a.min_stock_level) - Number(b.quantity_in_stock > b.min_stock_level)))
const supplierId = computed(() => activeIngredients.value.find((ingredient) => ingredient.supplier_id)?.supplier_id ?? null)

function newLine(): FormLine {
  return {
    key: ++lineKey,
    ingredient_id: 0,
    ingredient_packaging_id: null,
    requested_purchase_quantity: 1,
  }
}

async function addLine() {
  const line = newLine()
  form.items.push(line)
  await nextTick()
  dialog.value?.querySelector<HTMLSelectElement>(`#po-ingredient-${line.key}`)?.focus()
}

function removeLine(index: number) {
  if (form.items.length > 1) form.items.splice(index, 1)
}

function ingredientFor(line: FormLine) {
  return activeIngredients.value.find((ingredient) => ingredient.id === line.ingredient_id)
}

function packagingOptions(line: FormLine): IngredientPackaging[] {
  return ingredientFor(line)?.packagings.filter((packaging) => packaging.is_active) ?? []
}

function ingredientUnit(line: FormLine): string {
  return ingredientFor(line)?.unit ?? ''
}

function selectedPackaging(line: FormLine): IngredientPackaging | undefined {
  return packagingOptions(line).find((packaging) => packaging.id === line.ingredient_packaging_id)
}

function purchaseUnit(line: FormLine): string {
  return selectedPackaging(line)?.purchase_unit || ingredientUnit(line) || 'đơn vị tồn kho'
}

function stockQuantity(line: FormLine): number {
  return (Number(line.requested_purchase_quantity) || 0) * (selectedPackaging(line)?.conversion_quantity ?? 1)
}

function selectIngredient(line: FormLine) {
  const defaultPackaging = packagingOptions(line).find((packaging) => packaging.is_default)
  line.ingredient_packaging_id = defaultPackaging?.id ?? null
}

function open(order: PurchaseOrder | null = null) {
  editing.value = order
  error.value = ''
  form.requested_delivery_date = order?.requested_delivery_date ?? ''
  form.manager_note = order?.manager_note ?? ''
  form.items = order
    ? order.items.map((item) => ({
        key: ++lineKey,
        ingredient_id: item.ingredient_id,
        ingredient_packaging_id: item.ingredient_packaging_id,
        requested_purchase_quantity: item.requested_purchase_quantity,
      }))
    : [newLine()]
  dialog.value?.showModal()
}

function close() {
  if (!saving.value) dialog.value?.close()
}

function onCancel(event: Event) {
  if (saving.value) event.preventDefault()
}

async function submit() {
  if (saving.value) return
  error.value = ''
  const currentSupplierId = supplierId.value
  if (!currentSupplierId) {
    error.value = 'Không tìm thấy Nhà cung cấp K trong danh sách nguyên liệu.'
    await focusError()
    return
  }
  if (form.items.some((line) => line.ingredient_id <= 0 || line.requested_purchase_quantity <= 0)) {
    error.value = 'Hãy chọn nguyên liệu và nhập số lượng nguyên dương cho từng dòng.'
    await focusError()
    return
  }

  const payload: PurchaseOrderPayload = {
    supplier_id: currentSupplierId,
    requested_delivery_date: form.requested_delivery_date || null,
    manager_note: form.manager_note || null,
    items: form.items.map((line) => ({
      ingredient_id: line.ingredient_id,
      ingredient_packaging_id: line.ingredient_packaging_id,
      requested_purchase_quantity: line.requested_purchase_quantity,
    })),
  }

  saving.value = true
  try {
    const response = editing.value
      ? await purchaseOrdersApi.update(editing.value.id, payload)
      : await purchaseOrdersApi.create(payload)
    emit('saved', response.data.data)
    dialog.value?.close()
  } catch (requestError) {
    error.value = purchaseOrderError(requestError)
    await focusError()
  } finally {
    saving.value = false
  }
}

async function focusError() {
  await nextTick()
  errorSummary.value?.focus()
}

function formatQuantity(value: number) {
  return value.toLocaleString('vi-VN')
}

defineExpose({ open })
</script>
