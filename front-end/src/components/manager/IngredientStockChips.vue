<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
    inventoryBalanceApi,
    type IngredientInventoryBalance,
} from '@/api/ingredients'
import { recipesApi, type RecipeLine } from '@/api/ingredients'

const props = defineProps<{ menuItemId: number }>()

const loading = ref(false)
const error = ref('')
const lines = ref<Array<{ ingredient: RecipeLine; balance: IngredientInventoryBalance | null }>>([])

async function load() {
    if (!props.menuItemId) return
    loading.value = true
    error.value = ''
    try {
        const recipe = await recipesApi.get(props.menuItemId)
        const critical = recipe.lines.filter((i: RecipeLine) => i.availability_required)
        const results = await Promise.all(
            critical.map(async (ingredient: RecipeLine) => {
                try {
                    const balance = await inventoryBalanceApi.forIngredient(ingredient.ingredient_id)
                    return { ingredient, balance }
                } catch {
                    return { ingredient, balance: null }
                }
            }),
        )
        lines.value = results
    } catch (err: unknown) {
        error.value = err instanceof Error ? err.message : 'Không tải được công thức.'
        lines.value = []
    } finally {
        loading.value = false
    }
}

watch(() => props.menuItemId, load, { immediate: true })

const hasData = computed(() => lines.value.length > 0)
</script>

<template>
    <div v-if="loading" class="stock-chips stock-chips--loading">Đang tải tồn NL trọng yếu…</div>
    <p v-else-if="error" class="stock-chips stock-chips--error" role="alert">{{ error }}</p>
    <div v-else-if="hasData" class="stock-chips" role="group" aria-label="Tồn nguyên liệu trọng yếu">
        <span class="stock-chips__title">NL trọng yếu:</span>
        <span
            v-for="line in lines"
            :key="line.ingredient.ingredient_id"
            :class="['stock-chip', stockChipTone(line.balance)]"
            :title="stockChipTitle(line.balance, line.ingredient)"
        >
            {{ line.ingredient.ingredient_name ?? line.ingredient.ingredient_id }}
            <strong v-if="line.balance && line.balance.estimated_available !== null">
                {{ formatQty(line.balance.estimated_available) }}{{ line.balance.unit }}
            </strong>
            <strong v-else>—</strong>
        </span>
    </div>
    <p v-else class="stock-chips__empty">Món này chưa gắn NL trọng yếu.</p>
</template>

<script lang="ts">
function stockChipTone(balance: IngredientInventoryBalance | null): string {
    if (!balance || balance.estimated_available === null) return 'stock-chip--unknown'
    if (balance.estimated_available <= 0) return 'stock-chip--empty'
    if (balance.data_status !== 'ready') return 'stock-chip--unknown'
    return 'stock-chip--ok'
}

function stockChipTitle(balance: IngredientInventoryBalance | null, ing: RecipeLine): string {
    if (!balance) return `${ing.ingredient_name ?? 'NL'}: chưa có dữ liệu kho`
    if (balance.estimated_available === null) return `${balance.name}: không thể ước tính (thiếu dữ liệu)`
    return `${balance.name}: khả dụng ước tính ${formatQty(balance.estimated_available)} ${balance.unit}`
}

function formatQty(value: number): string {
    return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
}

export default { name: 'IngredientStockChips' }
</script>

<style scoped>
.stock-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 11px;
               color: var(--mgr-gray-600); }
.stock-chips--loading, .stock-chips--error, .stock-chips__empty { font-size: 11px; color: var(--mgr-gray-500);
                                                                     font-style: italic; }
.stock-chips--error { color: #991b1b; font-style: normal; }
.stock-chips__title { font-weight: 700; color: var(--mgr-gray-700); margin-right: 2px; }
.stock-chip { display: inline-flex; gap: 4px; align-items: baseline; padding: 3px 8px; border-radius: 9999px;
              font-weight: 600; font-size: 11px; border: 1px solid transparent; }
.stock-chip strong { font-weight: 800; }
.stock-chip--ok { background: #ecfdf5; color: #166534; border-color: #bbf7d0; }
.stock-chip--empty { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.stock-chip--unknown { background: #fef3c7; color: #92400e; border-color: #fde68a; }
</style>