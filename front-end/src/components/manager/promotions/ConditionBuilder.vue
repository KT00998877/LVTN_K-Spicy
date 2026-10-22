<template>
    <fieldset class="condition-builder" :disabled="disabled">
        <legend>Điều kiện kích hoạt</legend>
        <p class="hint">Bỏ trống phần nào thì phần đó không bị ràng buộc.</p>

        <div class="grid-2">
            <label>
                Đơn tối thiểu (VND)
                <input
                    type="number"
                    min="0"
                    step="1000"
                    :value="conditions.min_subtotal ?? ''"
                    :disabled="disabled"
                    placeholder="Không yêu cầu"
                    @input="update('min_subtotal', $event)"
                />
            </label>
            <label>
                <input
                    type="checkbox"
                    :checked="!!conditions.first_order_only"
                    :disabled="disabled"
                    @change="update('first_order_only', $event)"
                />
                Chỉ áp dụng cho khách đặt đơn đầu tiên
            </label>
        </div>

        <div class="grid-2">
            <label>
                Từ giờ
                <input
                    type="time"
                    :value="conditions.time_from ?? ''"
                    :disabled="disabled"
                    @input="update('time_from', $event)"
                />
            </label>
            <label>
                Đến giờ
                <input
                    type="time"
                    :value="conditions.time_to ?? ''"
                    :disabled="disabled"
                    @input="update('time_to', $event)"
                />
            </label>
        </div>

        <div>
            <span class="field-label">Ngày trong tuần</span>
            <div class="day-chips">
                <button
                    v-for="d in dayOptions"
                    :key="d.value"
                    type="button"
                    class="day-chip"
                    :class="{ on: isDayActive(d.value) }"
                    :disabled="disabled"
                    @click="toggleDay(d.value)"
                >
                    {{ d.label }}
                </button>
            </div>
        </div>

        <div>
            <span class="field-label">Kênh bán</span>
            <div class="day-chips">
                <button
                    v-for="c in channelOptions"
                    :key="c.value"
                    type="button"
                    class="day-chip"
                    :class="{ on: isChannelActive(c.value) }"
                    :disabled="disabled"
                    @click="toggleChannel(c.value)"
                >
                    {{ c.label }}
                </button>
            </div>
        </div>

        <label>
            Hạng thành viên áp dụng (ID, phân cách dấu phẩy)
            <input
                type="text"
                :value="(conditions.loyalty_tier_ids ?? []).join(', ')"
                :disabled="disabled"
                placeholder="VD: 1, 2, 3 — bỏ trống = mọi hạng"
                @input="updateLoyalty($event)"
            />
        </label>
    </fieldset>
</template>

<script setup lang="ts">
import type { PromotionConditions } from '@/types/promotion'

interface Props {
    modelValue: PromotionConditions
    disabled?: boolean
}
const props = withDefaults(defineProps<Props>(), { disabled: false })
const emit = defineEmits<{ 'update:modelValue': [PromotionConditions] }>()

const conditions = props.modelValue ?? {}

const dayOptions = [
    { value: 0, label: 'CN' },
    { value: 1, label: 'T2' },
    { value: 2, label: 'T3' },
    { value: 3, label: 'T4' },
    { value: 4, label: 'T5' },
    { value: 5, label: 'T6' },
    { value: 6, label: 'T7' },
]

const channelOptions = [
    { value: 'ONLINE', label: 'Online' },
    { value: 'DINE_IN', label: 'Tại bàn' },
    { value: 'TAKEAWAY', label: 'Mang đi' },
] as const

function patch(values: Partial<PromotionConditions>): void {
    emit('update:modelValue', { ...conditions, ...values })
}

function isDayActive(d: number): boolean {
    return (conditions.days_of_week ?? []).includes(d)
}

function toggleDay(d: number): void {
    const cur = conditions.days_of_week ?? []
    const next = cur.includes(d) ? cur.filter((x) => x !== d) : [...cur, d].sort()
    patch({ days_of_week: next })
}

function isChannelActive(c: 'ONLINE' | 'DINE_IN' | 'TAKEAWAY'): boolean {
    return (conditions.channels ?? []).includes(c)
}

function toggleChannel(c: 'ONLINE' | 'DINE_IN' | 'TAKEAWAY'): void {
    const cur = conditions.channels ?? []
    const next = cur.includes(c) ? cur.filter((x) => x !== c) : [...cur, c]
    patch({ channels: next })
}

function update(field: keyof PromotionConditions, e: Event): void {
    const target = e.target as HTMLInputElement
    const raw = target.value
    if (field === 'min_subtotal') {
        patch({ min_subtotal: raw === '' ? undefined : Number(raw) })
    } else if (field === 'first_order_only') {
        patch({ first_order_only: target.checked })
    } else if (field === 'time_from') {
        patch({ time_from: raw || undefined })
    } else if (field === 'time_to') {
        patch({ time_to: raw || undefined })
    }
}

function updateLoyalty(e: Event): void {
    const target = e.target as HTMLInputElement
    const raw = target.value.trim()
    if (!raw) {
        patch({ loyalty_tier_ids: undefined })
        return
    }
    const ids = raw
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => Number.isFinite(n) && n > 0)
    patch({ loyalty_tier_ids: ids })
}
</script>

<style scoped>
.condition-builder {
    border: 1px solid rgba(26, 14, 6, 0.1);
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: rgba(26, 14, 6, 0.02);
}
.condition-builder legend {
    padding: 0 8px;
    font-weight: 700;
    font-size: 13px;
    color: #1a1a1a;
}
.hint {
    margin: 0 0 4px;
    font-size: 12px;
    color: #6b5f54;
}
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.condition-builder label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: #1a1a1a;
    font-weight: 600;
}
.condition-builder label:has(input[type='checkbox']) {
    flex-direction: row;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}
.condition-builder input[type='number'],
.condition-builder input[type='time'],
.condition-builder input[type='text'] {
    padding: 8px 10px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.condition-builder input:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
}
.day-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.day-chip {
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
.day-chip.on {
    background: var(--chili, #d71920);
    color: #fff;
    border-color: transparent;
}
.day-chip:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
@media (max-width: 640px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>
