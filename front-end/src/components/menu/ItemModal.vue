<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { fetchToppings } from '@/api/cart'
import type { ToppingItem } from '@/types/cart'

const props = defineProps<{
    visible: boolean
    menuItem?: {
        id: number
        name: string
        price: number
        price_formatted: string
        default_spicy: number
        image_url: string
    } | null
    cartItem?: {
        id: number
        quantity: number
        spicy_level: number
        note: string | null
        topping_ids: number[]
        unit_price: number
        topping_price: number
        subtotal: number
        toppings: ToppingItem[]
    } | null
    loading?: boolean
}>()

const emit = defineEmits<{
    'update:visible': [value: boolean]
    submit: [payload: {
        menu_item_id: number
        quantity: number
        spicy_level: number
        note: string
        topping_ids: number[]
    }]
}>()

const toppings = ref<ToppingItem[]>([])
const loadingToppings = ref(false)
const quantity = ref(1)
const spicyLevel = ref(0)
const note = ref('')
const selectedToppingIds = ref<number[]>([])
const toppingsLoaded = ref(false)

interface SpicyLevelConfig {
    value: number
    label: string
    color: string
    group: 1 | 2 | 3 | 4
}

// Spicy level configuration with colors
const SPICY_LEVELS: SpicyLevelConfig[] = [
    { value: 0, label: 'Không cay', color: '#22C55E', group: 1 },
    { value: 1, label: 'Cấp 1', color: '#FFB000', group: 2 },
    { value: 2, label: 'Cấp 2', color: '#FF7A00', group: 2 },
    { value: 3, label: 'Cấp 3', color: '#FF7A00', group: 3 },
    { value: 4, label: 'Cấp 4', color: '#E83A14', group: 3 },
    { value: 5, label: 'Cấp 5', color: '#E83A14', group: 4 },
    { value: 6, label: 'Cấp 6', color: '#A80F17', group: 4 },
    { value: 7, label: 'Cấp 7', color: '#A80F17', group: 4 },
]

const GROUP_LABELS = {
    1: { name: 'Nhẹ nhàng', icon: '🌱' },
    2: { name: 'Vừa vị', icon: '🌶️' },
    3: { name: 'Cay nồng', icon: '🔥' },
    4: { name: 'Cực hạn', icon: '💥' },
}

const isEditMode = computed(() => !!props.cartItem)
const title = computed(() => isEditMode.value ? 'Cập nhật món' : 'Tùy chỉnh món')
const displayName = computed(() =>
    props.cartItem?.toppings?.length
        ? props.menuItem?.name
        : props.menuItem?.name ?? ''
)

const selectedSpicyInfo = computed<SpicyLevelConfig>(
    () => SPICY_LEVELS.find((s) => s.value === spicyLevel.value) ?? SPICY_LEVELS[0]!,
)

const selectedToppingPrice = computed(() => {
    return toppings.value
        .filter(t => selectedToppingIds.value.includes(t.id))
        .reduce((sum, t) => sum + t.price, 0)
})

const totalPrice = computed(() => {
    const base = props.menuItem?.price ?? props.cartItem?.unit_price ?? 0
    return (base + selectedToppingPrice.value) * quantity.value
})

const totalPriceFormatted = computed(() =>
    new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(totalPrice.value)
)

const canSubmit = computed(() => quantity.value > 0 && quantity.value <= 99)

function toggleTopping(id: number) {
    const idx = selectedToppingIds.value.indexOf(id)
    if (idx >= 0) {
        selectedToppingIds.value.splice(idx, 1)
    } else {
        selectedToppingIds.value.push(id)
    }
}

function increaseQty() {
    if (quantity.value < 99) quantity.value++
}

function decreaseQty() {
    if (quantity.value > 1) quantity.value--
}

async function loadToppings() {
    if (toppingsLoaded.value) return
    loadingToppings.value = true
    try {
        toppings.value = await fetchToppings()
        toppingsLoaded.value = true
    } catch {
        console.error('[ItemModal] Failed to load toppings')
    } finally {
        loadingToppings.value = false
    }
}

function resetForm() {
    if (props.cartItem) {
        quantity.value = props.cartItem.quantity
        spicyLevel.value = props.cartItem.spicy_level
        note.value = props.cartItem.note ?? ''
        selectedToppingIds.value = [...(props.cartItem.topping_ids ?? [])]
    } else if (props.menuItem) {
        quantity.value = 1
        spicyLevel.value = props.menuItem.default_spicy
        note.value = ''
        selectedToppingIds.value = []
    } else {
        quantity.value = 1
        spicyLevel.value = 0
        note.value = ''
        selectedToppingIds.value = []
    }
}

function handleSubmit() {
    if (!canSubmit.value) return
    const menuId = props.menuItem?.id ?? 0
    emit('submit', {
        menu_item_id: menuId,
        quantity: quantity.value,
        spicy_level: spicyLevel.value,
        note: note.value.trim(),
        topping_ids: [...selectedToppingIds.value],
    })
}

function close() {
    emit('update:visible', false)
}

watch(() => props.visible, (val) => {
    if (val) {
        resetForm()
        void loadToppings()
    }
})
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="visible" class="modal-overlay" @click.self="close">
                <div class="modal-panel" role="dialog" :aria-label="title">
                    <!-- Header -->
                    <div class="modal-header">
                        <div class="header-content">
                            <span class="modal-badge">{{ isEditMode ? 'Cập nhật' : 'Thêm món' }}</span>
                            <h3 class="modal-title">{{ displayName }}</h3>
                        </div>
                        <button type="button" class="modal-close" @click="close" aria-label="Đóng">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>

                    <div class="modal-body">
                        <!-- ===== SPICY LEVEL SELECTOR ===== -->
                        <section class="modal-section spicy-section">
                            <div class="section-header">
                                <h4 class="section-title">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2c1.5 2 3 4 3 6 0 3-2 4-3 4s-3-1-3-4c0-2 1.5-4 3-6z"/>
                                        <path d="M12 12v10"/>
                                    </svg>
                                    Cấp độ cay
                                </h4>
                                <div class="selected-spicy-info" :style="{ color: selectedSpicyInfo.color }">
                                    {{ GROUP_LABELS[String(selectedSpicyInfo.group) as '1' | '2' | '3' | '4']?.icon }}
                                    {{ selectedSpicyInfo.label }}
                                </div>
                            </div>
                            
                            <!-- Visual spicy selector -->
                            <div class="spicy-selector">
                                <div class="spicy-track">
                                    <div 
                                        class="spicy-progress" 
                                        :style="{ 
                                            width: `${(spicyLevel / 7) * 100}%`,
                                            background: `linear-gradient(90deg, #22C55E 0%, #FFB000 14%, #FF7A00 28%, #E83A14 57%, #A80F17 100%)`
                                        }"
                                    ></div>
                                </div>
                                
                                <div class="spicy-levels">
                                    <button
                                        v-for="level in SPICY_LEVELS"
                                        :key="level.value"
                                        type="button"
                                        class="spicy-level-btn"
                                        :class="{ active: spicyLevel === level.value }"
                                        :style="spicyLevel === level.value ? { backgroundColor: level.color } : {}"
                                        @click="spicyLevel = level.value"
                                        :aria-label="level.label"
                                        :aria-pressed="spicyLevel === level.value"
                                    >
                                        <span class="level-number">{{ level.value === 0 ? '🚫' : level.value }}</span>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Spicy group labels -->
                            <div class="spicy-groups">
                                <div 
                                    v-for="(info, group) in GROUP_LABELS" 
                                    :key="group"
                                    class="spicy-group"
                                    :class="{ active: selectedSpicyInfo.group === Number(group) }"
                                >
                                    <span class="group-icon">{{ info.icon }}</span>
                                    <span class="group-name">{{ info.name }}</span>
                                </div>
                            </div>
                        </section>

                        <!-- ===== TOPPINGS ===== -->
                        <section class="modal-section">
                            <h4 class="section-title">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                </svg>
                                Topping thêm
                            </h4>
                            
                            <div v-if="loadingToppings" class="section-loading">
                                <svg class="spinner" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                </svg>
                                Đang tải topping...
                            </div>
                            <div v-else-if="toppings.length === 0" class="section-empty">
                                Hiện không có topping nào khả dụng.
                            </div>
                            <div v-else class="topping-list">
                                <button
                                    v-for="topping in toppings"
                                    :key="topping.id"
                                    type="button"
                                    class="topping-btn"
                                    :class="{ selected: selectedToppingIds.includes(topping.id) }"
                                    @click="toggleTopping(topping.id)"
                                >
                                    <div class="topping-check" :class="{ checked: selectedToppingIds.includes(topping.id) }">
                                        <svg v-if="selectedToppingIds.includes(topping.id)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"></polyline>
                                        </svg>
                                    </div>
                                    <span class="topping-name">{{ topping.name }}</span>
                                    <span class="topping-price">
                                        +{{ new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(topping.price) }}
                                    </span>
                                </button>
                            </div>
                        </section>

                        <!-- ===== NOTES ===== -->
                        <section class="modal-section">
                            <h4 class="section-title">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                </svg>
                                Ghi chú cho bếp
                            </h4>
                            <div class="note-input-wrap">
                                <textarea
                                    v-model="note"
                                    class="note-input"
                                    placeholder="Ví dụ: ít hành, thêm nhiều rau..."
                                    rows="3"
                                    maxlength="200"
                                ></textarea>
                                <div class="note-hint">{{ note.length }}/200</div>
                            </div>
                        </section>

                        <!-- ===== QUANTITY ===== -->
                        <section class="modal-section qty-section">
                            <h4 class="section-title">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="12" y1="8" x2="12" y2="16"></line>
                                    <line x1="8" y1="12" x2="16" y2="12"></line>
                                </svg>
                                Số lượng
                            </h4>
                            <div class="qty-control">
                                <button
                                    type="button"
                                    class="qty-btn"
                                    :disabled="quantity <= 1"
                                    @click="decreaseQty"
                                    aria-label="Giảm số lượng"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </button>
                                <span class="qty-value">{{ quantity }}</span>
                                <button
                                    type="button"
                                    class="qty-btn"
                                    :disabled="quantity >= 99"
                                    @click="increaseQty"
                                    aria-label="Tăng số lượng"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </button>
                            </div>
                        </section>

                        <!-- ===== TOTAL ===== -->
                        <div class="modal-total">
                            <span class="total-label">Tổng cộng</span>
                            <span class="total-price">{{ totalPriceFormatted }}</span>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-outline"
                            @click="close"
                        >
                            Hủy
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!canSubmit || loading"
                            @click="handleSubmit"
                        >
                            <svg v-if="loading" class="btn-spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                            </svg>
                            <span v-if="loading">Đang xử lý...</span>
                            <span v-else>
                                <svg v-if="!isEditMode" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="9" cy="21" r="1"></circle>
                                    <circle cx="20" cy="21" r="1"></circle>
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                                </svg>
                                {{ isEditMode ? 'Lưu thay đổi' : 'Thêm vào giỏ' }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ===== OVERLAY ===== */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17, 17, 17, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

/* ===== PANEL ===== */
.modal-panel {
    background: var(--color-white);
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    border-radius: var(--radius-2xl);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.2);
}

/* ===== HEADER ===== */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 24px;
    background: var(--color-cream);
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    flex-shrink: 0;
}

.header-content {
    flex: 1;
    min-width: 0;
}

.modal-badge {
    display: inline-block;
    padding: 4px 10px;
    background: var(--color-chili-red);
    color: var(--color-white);
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.modal-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin: 0;
    letter-spacing: -0.5px;
    line-height: 1.3;
}

.modal-close {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    margin-left: 16px;
    background: var(--color-white);
    border: none;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-gray-600);
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.modal-close:hover {
    background: var(--color-charcoal-black);
    color: var(--color-white);
}

/* ===== BODY ===== */
.modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 28px;
}

/* ===== SECTIONS ===== */
.modal-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 700;
    color: var(--color-gray-500);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.section-title svg {
    color: var(--color-chili-red);
}

.selected-spicy-info {
    font-size: 14px;
    font-weight: 700;
    transition: color 0.2s ease;
}

.section-loading,
.section-empty {
    color: var(--color-gray-500);
    font-size: 14px;
    padding: 16px 0;
    text-align: center;
}

.section-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ===== SPICY SELECTOR ===== */
.spicy-selector {
    position: relative;
}

.spicy-track {
    position: absolute;
    top: 50%;
    left: 24px;
    right: 24px;
    height: 6px;
    background: var(--color-gray-200);
    border-radius: 3px;
    transform: translateY(-50%);
    z-index: 1;
    overflow: hidden;
}

.spicy-progress {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.spicy-levels {
    display: flex;
    justify-content: space-between;
    position: relative;
    z-index: 2;
    padding: 0 4px;
}

.spicy-level-btn {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-full);
    background: var(--color-gray-100);
    border: 3px solid transparent;
    color: var(--color-gray-400);
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.spicy-level-btn:hover {
    transform: scale(1.1);
    background: var(--color-gray-200);
}

.spicy-level-btn.active {
    transform: scale(1.15);
    border-color: var(--color-white);
    color: var(--color-white);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.level-number {
    line-height: 1;
}

.spicy-groups {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 16px;
}

.spicy-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 10px 8px;
    background: var(--color-gray-50);
    border-radius: var(--radius-lg);
    opacity: 0.5;
    transition: all 0.2s ease;
}

.spicy-group.active {
    opacity: 1;
    background: var(--color-cream);
}

.group-icon {
    font-size: 16px;
}

.group-name {
    font-size: 10px;
    font-weight: 600;
    color: var(--color-gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== TOPPINGS ===== */
.topping-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.topping-btn {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--color-gray-50);
    border: 2px solid transparent;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: left;
    font-size: 15px;
}

.topping-btn:hover {
    background: var(--color-gray-100);
}

.topping-btn.selected {
    background: rgba(215, 25, 32, 0.05);
    border-color: var(--color-chili-red);
}

.topping-check {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 2px solid var(--color-gray-300);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.topping-check.checked {
    background: var(--color-chili-red);
    border-color: var(--color-chili-red);
    color: var(--color-white);
}

.topping-name {
    flex: 1;
    color: var(--color-charcoal-black);
    font-weight: 600;
}

.topping-price {
    color: var(--color-gray-500);
    font-weight: 500;
    font-size: 14px;
}

/* ===== NOTES ===== */
.note-input-wrap {
    position: relative;
}

.note-input {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font-size: 15px;
    font-family: inherit;
    resize: vertical;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: all 0.2s ease;
    min-height: 80px;
}

.note-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.1);
}

.note-input::placeholder {
    color: var(--color-gray-400);
}

.note-hint {
    font-size: 12px;
    color: var(--color-gray-400);
    text-align: right;
    margin-top: 6px;
}

/* ===== QUANTITY ===== */
.qty-section {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
}

.qty-section .section-title {
    margin: 0;
}

.qty-control {
    display: flex;
    align-items: center;
    background: var(--color-gray-100);
    border-radius: var(--radius-full);
    padding: 4px;
}

.qty-btn {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-full);
    background: var(--color-white);
    border: none;
    color: var(--color-charcoal-black);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.qty-btn:hover:not(:disabled) {
    color: var(--color-chili-red);
    transform: scale(1.05);
}

.qty-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    box-shadow: none;
    background: transparent;
}

.qty-value {
    width: 52px;
    text-align: center;
    font-size: 18px;
    font-weight: 800;
    color: var(--color-charcoal-black);
}

/* ===== TOTAL ===== */
.modal-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    background: var(--color-charcoal-black);
    border-radius: var(--radius-xl);
    margin: 0 -24px;
    width: calc(100% + 48px);
}

.total-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-gray-300);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.total-price {
    font-size: 28px;
    font-weight: 800;
    color: var(--color-chili-red);
}

/* ===== FOOTER ===== */
.modal-footer {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    background: var(--color-gray-50);
    border-top: 1px solid rgba(0, 0, 0, 0.06);
    flex-shrink: 0;
}

.modal-footer .btn {
    flex: 1;
    padding: 14px 20px;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-primary svg:not(.btn-spinner) {
    width: 18px;
    height: 18px;
}

.btn-spinner {
    animation: spin 1s linear infinite;
}

/* ===== MODAL TRANSITION ===== */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.3s ease;
}

.modal-enter-active .modal-panel,
.modal-leave-active .modal-panel {
    transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-from .modal-panel {
    transform: translateY(40px) scale(0.95);
}

.modal-leave-to .modal-panel {
    transform: translateY(40px) scale(0.95);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 600px) {
    .modal-overlay {
        align-items: flex-end;
        padding: 0;
    }
    
    .modal-panel {
        max-width: 100%;
        border-radius: var(--radius-2xl) var(--radius-2xl) 0 0;
        max-height: 95vh;
    }
    
    .spicy-levels {
        gap: 0;
    }
    
    .spicy-level-btn {
        width: 40px;
        height: 40px;
    }
    
    .spicy-groups {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .modal-total {
        margin: 0 -20px;
        width: calc(100% + 40px);
        padding: 16px 20px;
    }
    
    .total-price {
        font-size: 24px;
    }
    
    .modal-footer .btn {
        padding: 12px 16px;
        font-size: 14px;
    }
}

@media (max-width: 400px) {
    .modal-body {
        padding: 20px;
        gap: 24px;
    }
    
    .spicy-level-btn {
        width: 36px;
        height: 36px;
        font-size: 12px;
    }
    
    .level-number {
        font-size: 12px;
    }
}

/* ===== REDUCED MOTION ===== */
@media (prefers-reduced-motion: reduce) {
    .spicy-level-btn,
    .qty-btn,
    .topping-btn,
    .modal-close {
        transition: none;
    }
    
    .spicy-level-btn:hover,
    .qty-btn:hover {
        transform: none;
    }
    
    .spinner,
    .btn-spinner {
        animation: none;
    }
    
    .modal-enter-active,
    .modal-leave-active {
        transition: none;
    }
}
</style>
