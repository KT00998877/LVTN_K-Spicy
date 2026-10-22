<template>
    <fieldset class="scope-picker" :disabled="disabled">
        <legend>Phạm vi áp dụng</legend>

        <label>
            Loại phạm vi
            <select :value="appliesTo" :disabled="disabled" @change="onAppliesToChange">
                <option value="ALL">Toàn menu</option>
                <option value="CATEGORY">Theo danh mục</option>
                <option value="MENU_ITEM">Theo món cụ thể</option>
            </select>
        </label>

        <div v-if="appliesTo === 'CATEGORY' || appliesTo === 'MENU_ITEM'">
            <p class="hint">
                {{
                    appliesTo === 'CATEGORY'
                        ? 'Chọn các danh mục được áp dụng:'
                        : 'Nhập ID các món được áp dụng (phân cách dấu phẩy):'
                }}
            </p>

            <div v-if="appliesTo === 'CATEGORY'" class="chip-list">
                <button
                    v-for="cat in categories"
                    :key="cat.id"
                    type="button"
                    class="chip"
                    :class="{ on: isItemActive(cat.id) }"
                    :disabled="disabled"
                    @click="toggleItem(cat.id)"
                >
                    {{ cat.name }}
                </button>
                <p v-if="!categories.length" class="muted">Chưa có danh mục nào.</p>
            </div>

            <input
                v-else
                type="text"
                :value="rawItemIds"
                :disabled="disabled"
                placeholder="VD: 12, 34, 56"
                @input="onItemIdsInput"
                @blur="commitItemIds"
            />

            <small v-if="resolved.length" class="meta">{{ resolved.length }} mục đã chọn.</small>
        </div>
    </fieldset>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import apiClient from '@/api/client'
import type { PromotionAppliesTo, PromotionConfig } from '@/types/promotion'

interface Props {
    modelValue: PromotionConfig
    disabled?: boolean
}
const props = defineProps<Props>()
const emit = defineEmits<{ 'update:modelValue': [PromotionConfig] }>()

interface CategoryItem {
    id: number
    name: string
}

const categories = ref<CategoryItem[]>([])
const rawItemIds = ref('')

const appliesTo = computed<PromotionAppliesTo>(() => {
    const cfg = props.modelValue
    if (cfg.kind === 'BXGY' || cfg.kind === 'COMBO') return 'ALL'
    return cfg.applies_to ?? 'ALL'
})

const resolved = computed<{ id: number }[]>(() => {
    const cfg = props.modelValue
    if (cfg.kind === 'PERCENT_OFF' || cfg.kind === 'FIXED_OFF') {
        if (cfg.applies_to === 'ALL') return []
        return (cfg.item_ids ?? []).map((id) => ({ id }))
    }
    return []
})

function isItemActive(id: number): boolean {
    const cfg = props.modelValue
    if (cfg.kind !== 'PERCENT_OFF' && cfg.kind !== 'FIXED_OFF') return false
    return (cfg.item_ids ?? []).includes(id)
}

function toggleItem(id: number): void {
    const cfg = props.modelValue
    if (cfg.kind !== 'PERCENT_OFF' && cfg.kind !== 'FIXED_OFF') return
    const cur = cfg.item_ids ?? []
    const next = cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]
    emit('update:modelValue', { ...cfg, item_ids: next })
}

function onItemIdsInput(e: Event): void {
    rawItemIds.value = (e.target as HTMLInputElement).value
}

function commitItemIds(): void {
    const cfg = props.modelValue
    if (cfg.kind !== 'PERCENT_OFF' && cfg.kind !== 'FIXED_OFF') return
    const ids = rawItemIds.value
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => Number.isFinite(n) && n > 0)
    emit('update:modelValue', { ...cfg, item_ids: ids })
}

function onAppliesToChange(e: Event): void {
    const target = e.target as HTMLSelectElement
    const value = target.value as PromotionAppliesTo
    const cfg = props.modelValue
    if (cfg.kind !== 'PERCENT_OFF' && cfg.kind !== 'FIXED_OFF') return
    const item_ids = value === 'ALL' ? undefined : cfg.item_ids ?? []
    emit('update:modelValue', { ...cfg, applies_to: value, item_ids })
}

async function loadCategories(): Promise<void> {
    try {
        const r = await apiClient.get<{ data: CategoryItem[] }>('/home/categories')
        categories.value = Array.isArray(r.data?.data) ? r.data.data : []
    } catch {
        categories.value = []
    }
}

watch(
    () => props.modelValue,
    (cfg) => {
        if (cfg.kind === 'PERCENT_OFF' || cfg.kind === 'FIXED_OFF') {
            rawItemIds.value = (cfg.item_ids ?? []).join(', ')
        }
    },
    { immediate: true, deep: true },
)

void loadCategories()
</script>

<style scoped>
.scope-picker {
    border: 1px solid rgba(26, 14, 6, 0.1);
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: rgba(26, 14, 6, 0.02);
}
.scope-picker legend {
    padding: 0 8px;
    font-weight: 700;
    font-size: 13px;
}
.scope-picker label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
}
.scope-picker select,
.scope-picker input[type='text'] {
    padding: 8px 10px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.scope-picker input:focus,
.scope-picker select:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    border: 1px solid rgba(26, 14, 6, 0.14);
    background: #fff;
    color: #1a1a1a;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.chip.on {
    background: var(--chili, #d71920);
    color: #fff;
    border-color: transparent;
}
.hint {
    margin: 0;
    font-size: 12px;
    color: #6b5f54;
}
.meta {
    color: #6b5f54;
    font-size: 11px;
}
.muted {
    color: #6b5f54;
    font-size: 12px;
}
</style>
