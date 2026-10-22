<script setup lang="ts">
/**
 * StarRating — hiển thị + chọn số sao (1..5).
 *
 * Props:
 *  - value:     số sao hiện tại (cho chế độ display).
 *  - modelValue: cho v-model (cho chế độ tương tác).
 *  - readonly:  không cho click.
 *  - size:      'sm' | 'md' | 'lg'
 *  - showNumber: hiển thị số bên cạnh (vd: "4.5 (12)")
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    value?: number
    modelValue?: number
    readonly?: boolean
    size?: 'sm' | 'md' | 'lg'
    showNumber?: boolean
    count?: number
}>(), {
    value: 0,
    modelValue: 0,
    readonly: false,
    size: 'md',
    showNumber: false,
    count: 0,
})

const emit = defineEmits<{
    'update:modelValue': [value: number]
}>()

const displayValue = computed(() => {
    return props.modelValue || props.value || 0
})

const stars = computed(() => {
    const v = displayValue.value
    return Array.from({ length: 5 }, (_, i) => {
        const idx = i + 1
        let fill = 0
        if (v >= idx) fill = 1
        else if (v > i) fill = v - i
        return { idx, fill }
    })
})

function select(idx: number) {
    if (props.readonly) return
    emit('update:modelValue', idx)
}

const sizeClass = computed(() => `size-${props.size}`)
const formattedValue = computed(() => {
    const v = displayValue.value
    return v > 0 ? v.toFixed(1) : '—'
})
</script>

<template>
    <div class="star-rating" :class="[sizeClass, { readonly }]" role="img" :aria-label="`Đánh giá ${formattedValue} trên 5`">
        <button
            v-for="s in stars"
            :key="s.idx"
            type="button"
            class="star"
            :class="{ readonly }"
            :disabled="readonly"
            :aria-label="`${s.idx} sao`"
            @click="select(s.idx)"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <defs>
                    <linearGradient :id="`grad-${s.idx}-${displayValue}`" x1="0" x2="1" y1="0" y2="0">
                        <stop offset="0%" stop-color="#FFB000" />
                        <stop offset="100%" stop-color="#FF7A00" />
                    </linearGradient>
                </defs>
                <path
                    d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
                    :fill="readonly ? '#F1E9DA' : '#E8E2D5'"
                />
                <path
                    v-if="s.fill > 0"
                    d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
                    :fill="readonly ? `url(#grad-${s.idx}-${displayValue})` : `url(#grad-${s.idx}-${displayValue})`"
                    :clip-path="`inset(0 ${(1 - s.fill) * 100}% 0 0)`"
                />
            </svg>
        </button>

        <span v-if="showNumber" class="rating-number">
            <strong>{{ formattedValue }}</strong>
            <span v-if="count > 0" class="rating-count">({{ count }})</span>
        </span>
    </div>
</template>

<style scoped>
.star-rating {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.star {
    padding: 0;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: transform 0.15s ease;
    line-height: 1;
}

.star:not(.readonly):hover {
    transform: scale(1.15);
}

.star:focus-visible {
    outline: 2px solid var(--color-chili-red, #D71920);
    outline-offset: 2px;
    border-radius: 2px;
}

.star svg {
    display: block;
    width: 16px;
    height: 16px;
}

.size-sm .star svg { width: 14px; height: 14px; }
.size-md .star svg { width: 18px; height: 18px; }
.size-lg .star svg { width: 26px; height: 26px; }

.star.readonly { cursor: default; }

.rating-number {
    font-size: 14px;
    color: var(--color-charcoal-black, #111);
    margin-left: 6px;
    display: inline-flex;
    align-items: baseline;
    gap: 4px;
}

.size-sm .rating-number { font-size: 12px; }
.size-lg .rating-number { font-size: 16px; }

.rating-number strong {
    font-weight: 800;
    color: var(--color-charcoal-black, #111);
}

.rating-count {
    font-weight: 500;
    color: var(--color-gray-500, #6b7280);
}
</style>