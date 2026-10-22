<template>
    <div class="campaign-picker">
        <label>
            Chiến dịch (tuỳ chọn)
            <div class="picker-row">
                <select v-model="selectedId" :disabled="disabled || loading">
                    <option :value="null">— Không thuộc chiến dịch nào —</option>
                    <option v-for="c in options" :key="c.id" :value="c.id">
                        {{ c.name }}{{ !c.is_active ? ' (đã tắt)' : '' }}
                    </option>
                </select>
                <button
                    type="button"
                    class="icon-btn"
                    :disabled="disabled || loading"
                    aria-label="Tải lại danh sách"
                    @click="reload"
                >
                    ↻
                </button>
            </div>
            <small v-if="loading">Đang tải…</small>
            <small v-else-if="options.length === 0">Chưa có chiến dịch nào — vào tab Chiến dịch để tạo.</small>
            <small v-else>Gom nhóm nhiều khuyến mãi vào cùng chiến dịch để khách xem dễ.</small>
        </label>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useManagerPromotionsStore } from '@/stores/managerPromotions'

interface Props {
    modelValue: number | null
    disabled?: boolean
}
const props = defineProps<Props>()
const emit = defineEmits<{ 'update:modelValue': [number | null] }>()

const store = useManagerPromotionsStore()
const selectedId = ref<number | null>(props.modelValue ?? null)
const loading = ref(false)

const options = ref(store.campaignOptions)
watch(
    () => store.campaignOptions,
    (v) => (options.value = v),
    { deep: true },
)

watch(selectedId, (v) => emit('update:modelValue', v))

async function ensureLoaded(): Promise<void> {
    if (store.campaignOptions.length > 0) {
        options.value = store.campaignOptions
        return
    }
    loading.value = true
    try {
        await store.loadCampaignOptions(true)
        options.value = store.campaignOptions
    } finally {
        loading.value = false
    }
}

async function reload(): Promise<void> {
    loading.value = true
    try {
        await store.loadCampaignOptions(true)
        options.value = store.campaignOptions
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    void ensureLoaded()
})
</script>

<style scoped>
.campaign-picker label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: #1a1a1a;
}
.picker-row {
    display: flex;
    gap: 6px;
    align-items: center;
}
.picker-row select {
    flex: 1;
    padding: 9px 11px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.picker-row select:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.icon-btn {
    background: transparent;
    border: 1px solid rgba(26, 14, 6, 0.12);
    width: 34px;
    height: 34px;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    color: #1a1a1a;
}
.icon-btn:hover {
    background: rgba(26, 14, 6, 0.05);
}
.campaign-picker small {
    color: #6b5f54;
    font-size: 11px;
}
</style>
