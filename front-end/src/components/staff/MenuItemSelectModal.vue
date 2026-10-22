<template>
    <Teleport to="body">
        <div v-if="show" class="modal-backdrop" @click.self="$emit('close')">
            <div class="modal-dialog big">
                <div class="modal-header">
                    <h3>{{ orderItem ? 'Cập nhật món' : 'Thêm món vào đơn' }}</h3>
                    <button class="modal-close" @click="$emit('close')">✕</button>
                </div>
                <div class="modal-body">
                    <input v-model="searchQuery" type="text" class="search-input" placeholder="🔍 Tìm món..." />
                    <div class="menu-list">
                        <button type="button"
                            v-for="item in filteredMenuItems"
                            :key="item.id"
                            class="menu-item"
                            :class="{ selected: selectedMenuItem?.id === item.id, unavailable: !item.is_available }"
                            :disabled="item.is_available === false"
                            @click="selectItem(item)"
                        >
                            <div class="menu-info">
                                <div class="menu-name">{{ item.name }}</div>
                                <div class="menu-price">{{ formatVND(item.price) }}</div>
                            </div>
                            <div v-if="!item.is_available" class="unavailable-badge">Hết món</div>
                        </button>
                    </div>
                    <div v-if="selectedMenuItem" class="item-options">
                        <h4 class="options-title">{{ selectedMenuItem.name }}</h4>
                        <section class="option-section">
                            <label class="section-label">Cấp độ cay</label>
                            <div class="spicy-grid">
                                <label v-for="lvl in SPICY_LEVELS" :key="lvl.value" class="spicy-option" :class="{ active: spicyLevel === lvl.value }">
                                    <input type="radio" :value="lvl.value" v-model.number="spicyLevel" class="spicy-radio" />
                                    <span class="spicy-label">{{ lvl.label }}</span>
                                </label>
                            </div>
                        </section>
                        <section v-if="toppings.length > 0" class="option-section">
                            <label class="section-label">Topping thêm</label>
                            <div class="topping-list">
                                <label v-for="t in toppings" :key="t.id" class="topping-item">
                                    <input type="checkbox" :value="t.id" v-model="selectedToppingIds" :disabled="t.is_available === false" />
                                    <span class="topping-name">{{ t.name }}</span>
                                    <span class="topping-price">+{{ formatVND(t.price) }}</span>
                                    <span v-if="t.is_available === false">Hết</span>
                                </label>
                            </div>
                        </section>
                        <section class="option-section">
                            <label class="section-label">Ghi chú</label>
                            <textarea v-model="note" class="note-input" rows="2" placeholder="Ví dụ: ít hành, không cay..." />
                        </section>
                        <section class="option-section">
                            <label class="section-label">Số lượng</label>
                            <div class="qty-control">
                                <button class="qty-btn" @click="quantity = Math.max(1, quantity - 1)">−</button>
                                <input v-model.number="quantity" type="number" min="1" max="50" class="qty-input" />
                                <button class="qty-btn" @click="quantity = Math.min(50, quantity + 1)">+</button>
                            </div>
                        </section>
                        <div class="total-line"><span>Tạm tính:</span><strong>{{ formatVND(estimatedSubtotal) }}</strong></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="$emit('close')">Hủy</button>
                    <button class="btn btn-primary" :disabled="!selectedMenuItem || submitting" @click="submit">
                        {{ submitting ? 'Đang thêm...' : (orderItem ? 'Cập nhật' : 'Thêm vào đơn') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, ref, watch } from 'vue'
import { useMenuStore } from '@/stores/menu'
import { useToast, formatVND } from '@/composables/useStaffUtils'
import type { OrderItem } from '@/types/staff'
import type { MenuItemCard } from '@/types/home'

const props = defineProps<{
    show: boolean
    orderItem?: OrderItem | null
}>()

const emit = defineEmits<{
    close: []
    submit: [payload: {
        menu_item_id: number
        quantity: number
        spicy_level: number
        note: string
        topping_ids: number[]
    }]
}>()

const toast = useToast()
const menuStore = useMenuStore()

const SPICY_LEVELS = [
    { value: 0, label: 'Không cay' },
    { value: 1, label: 'Cấp 1' },
    { value: 2, label: 'Cấp 2' },
    { value: 3, label: 'Cấp 3' },
    { value: 4, label: 'Cấp 4' },
    { value: 5, label: 'Cấp 5' },
    { value: 6, label: 'Cấp 6' },
    { value: 7, label: 'Cấp 7' },
] as const

const searchQuery = ref('')
const selectedMenuItem = ref<MenuItemCard | null>(null)
const spicyLevel = ref(0)
const note = ref('')
const quantity = ref(1)
const selectedToppingIds = ref<number[]>([])
const submitting = ref(false)

onMounted(() => menuStore.loadCategories())

watch(() => props.show, async (val) => {
    if (val) {
        searchQuery.value = ''
        selectedMenuItem.value = null
        spicyLevel.value = 0
        note.value = ''
        quantity.value = 1
        selectedToppingIds.value = []
        if (menuStore.items.length === 0) {
            await menuStore.loadCategories()
        }
    }
})

const menuItems = computed(() => menuStore.items)
const toppings = computed(() => menuItems.value.filter(m => (m.category_name ?? '').toLowerCase().includes('topping')))

const filteredMenuItems = computed(() => {
    let list = menuItems.value.filter(m => !toppings.value.some(t => t.id === m.id))
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase().trim()
        list = list.filter(m => m.name.toLowerCase().includes(q))
    }
    return list
})

const estimatedSubtotal = computed(() => {
    if (!selectedMenuItem.value) return 0
    const toppingPrice = selectedToppingIds.value.reduce((sum, id) => {
        const t = toppings.value.find(x => x.id === id)
        return sum + (t?.price ?? 0)
    }, 0)
    return (selectedMenuItem.value.price + toppingPrice) * quantity.value
})

function selectItem(item: MenuItemCard): void {
    if (!item.is_available) return
    selectedMenuItem.value = item
}

async function submit(): Promise<void> {
    if (!selectedMenuItem.value) return
    submitting.value = true
    emit('submit', {
        menu_item_id: selectedMenuItem.value.id,
        quantity: quantity.value,
        spicy_level: spicyLevel.value,
        note: note.value,
        topping_ids: selectedToppingIds.value,
    })
    setTimeout(() => {
        submitting.value = false
        emit('close')
    }, 200)
}
useRealtimeRefresh(() => Promise.all([menuStore.loadItems(true), menuStore.loadCategories(true)]), () => props.show && !submitting.value && !menuStore.loading)
</script>

<style scoped>
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.modal-dialog {
    background: #fff; border-radius: 14px; max-width: 560px; width: 100%;
    max-height: 92vh; overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-dialog.big { max-width: 720px; }
.modal-header { padding: 14px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.modal-header h3 { margin: 0; font-size: 17px; font-weight: 700; }
.modal-close { background: transparent; border: none; font-size: 22px; color: #6b7280; cursor: pointer; }
.modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.modal-footer { padding: 12px 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 8px; }

.search-input { width: 100%; padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin-bottom: 12px; font-family: inherit; }
.menu-list { max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 16px; }
.menu-item { width: 100%; padding: 10px 14px; border: 0; border-bottom: 1px solid #f3f4f6; background: #fff; text-align: left; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.15s; }
.menu-item:focus-visible { outline: 2px solid #c2410c; outline-offset: -2px; }
.menu-item:last-child { border-bottom: none; }
.menu-item:hover:not(.unavailable) { background: #f9fafb; }
.menu-item.selected { background: #fff7ed; border-left: 3px solid #f97316; }
.menu-item.unavailable { opacity: 0.5; cursor: not-allowed; }
.menu-name { font-weight: 600; font-size: 14px; color: #111827; }
.menu-price { font-weight: 600; color: #f97316; font-size: 13px; }
.unavailable-badge { background: #ef4444; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 4px; }

.item-options { background: #f9fafb; padding: 16px; border-radius: 10px; }
.options-title { margin: 0 0 12px; font-size: 16px; font-weight: 700; }
.option-section { margin-bottom: 14px; }
.section-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; }
.spicy-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.spicy-option { display: flex; align-items: center; justify-content: center; padding: 8px 4px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; background: #fff; transition: all 0.15s; font-size: 11px; }
.spicy-option.active { background: #f97316; color: #fff; border-color: #f97316; }
.spicy-radio { display: none; }
.spicy-label { font-size: 11px; }

.topping-list { display: flex; flex-direction: column; gap: 6px; }
.topping-item { display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; }
.topping-item input { accent-color: #f97316; }
.topping-name { flex: 1; font-size: 13px; }
.topping-price { font-size: 12px; color: #f97316; font-weight: 600; }

.note-input { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical; }

.qty-control { display: flex; align-items: center; gap: 8px; }
.qty-btn { width: 36px; height: 36px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; font-size: 18px; cursor: pointer; }
.qty-input { width: 60px; text-align: center; padding: 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }

.total-line { display: flex; justify-content: space-between; padding-top: 12px; border-top: 1px dashed #d1d5db; font-size: 15px; }
.total-line strong { color: #f97316; font-size: 17px; }

.btn { padding: 9px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; border: none; transition: all 0.15s; }
.btn-primary { background: #f97316; color: #fff; }
.btn-primary:hover { background: #ea580c; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: #f3f4f6; color: #374151; }
</style>
