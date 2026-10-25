<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
/**
 * FoodDetailModal - Redesigned food customization experience
 * 
 * Core flow: Dish → Spicy Level → Toppings → Quantity → Add to Cart
 * 
 * Features:
 * - Split layout: Large food image + customization panel
 * - Signature spicy level selector (segmented control)
 * - Clear topping selection with pricing
 * - Large touch-friendly quantity controls
 * - Mobile sticky bottom action bar
 * - Real-time price calculation
 */
import { ref, computed, watch } from 'vue'
import { fetchToppings } from '@/api/cart'
import type { ToppingItem } from '@/types/cart'
import type { MenuSuggestion } from '@/types/menuSuggestion'
import { imageUrl } from '@/utils/image'

const props = defineProps<{
    visible: boolean
    menuItem?: {
        id: number
        name: string
        price: number
        price_formatted: string
        default_spicy: number
        image_url: string
        description?: string
        /** Gợi ý combo nước uống do quản lý cấu hình (Phase 3). */
        suggestions?: MenuSuggestion[]
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
    /** Thêm nhanh combo: món chính (theo tùy chỉnh) + món gợi ý. */
    'add-combo': [payload: {
        main: { menu_item_id: number; quantity: number; spicy_level: number; note: string; topping_ids: number[] }
        suggested_item_id: number
    }]
}>()

// ===== STATE =====
const toppings = ref<ToppingItem[]>([])
const loadingToppings = ref(false)
const quantity = ref(1)
const spicyLevel = ref(0)
const note = ref('')
const selectedToppingIds = ref<number[]>([])
const toppingsLoaded = ref(false)
const imageLoaded = ref(false)

interface SpicyLevelConfig {
    value: number
    label: string
    color: string
    bgColor: string
    group: 1 | 2 | 3 | 4
}

// ===== SPICY LEVEL CONFIGURATION =====
const SPICY_LEVELS: SpicyLevelConfig[] = [
    { value: 0, label: 'Không cay', color: '#22C55E', bgColor: 'rgba(34, 197, 94, 0.15)', group: 1 },
    { value: 1, label: 'Cấp 1', color: '#FFB000', bgColor: 'rgba(255, 176, 0, 0.15)', group: 2 },
    { value: 2, label: 'Cấp 2', color: '#FF7A00', bgColor: 'rgba(255, 122, 0, 0.15)', group: 2 },
    { value: 3, label: 'Cấp 3', color: '#FF7A00', bgColor: 'rgba(255, 122, 0, 0.15)', group: 3 },
    { value: 4, label: 'Cấp 4', color: '#E83A14', bgColor: 'rgba(232, 58, 20, 0.15)', group: 3 },
    { value: 5, label: 'Cấp 5', color: '#E83A14', bgColor: 'rgba(232, 58, 20, 0.15)', group: 3 },
    { value: 6, label: 'Cấp 6', color: '#A80F17', bgColor: 'rgba(168, 15, 23, 0.15)', group: 4 },
    { value: 7, label: 'Cấp 7', color: '#A80F17', bgColor: 'rgba(168, 15, 23, 0.15)', group: 4 },
]

const SPICY_GROUPS = {
    1: { name: 'Nhẹ nhàng', icon: '🌱', color: '#22C55E' },
    2: { name: 'Vừa vị', icon: '🌶️', color: '#FFB000' },
    3: { name: 'Cay nồng', icon: '🔥', color: '#E83A14' },
    4: { name: 'Cực hạn', icon: '💥', color: '#A80F17' },
}

// ===== COMPUTED =====
const isEditMode = computed(() => !!props.cartItem)
const title = computed(() => isEditMode.value ? 'Cập nhật món' : 'Tùy chỉnh món')

const selectedSpicyInfo = computed<SpicyLevelConfig>(
    () => SPICY_LEVELS.find((s) => s.value === spicyLevel.value) ?? SPICY_LEVELS[0]!,
)

const selectedGroupInfo = computed(() => 
    SPICY_GROUPS[selectedSpicyInfo.value.group as keyof typeof SPICY_GROUPS]
)

const selectedToppingPrice = computed(() => 
    toppings.value
        .filter(t => selectedToppingIds.value.includes(t.id))
        .reduce((sum, t) => sum + t.price, 0)
)

const basePrice = computed(() => 
    props.menuItem?.price ?? props.cartItem?.unit_price ?? 0
)

const unitPrice = computed(() => basePrice.value + selectedToppingPrice.value)

const totalPrice = computed(() => unitPrice.value * quantity.value)

const totalPriceFormatted = computed(() =>
    new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(totalPrice.value)
)

const unitPriceFormatted = computed(() =>
    new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(unitPrice.value)
)

const toppingTotalFormatted = computed(() =>
    new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(selectedToppingPrice.value)
)

const canSubmit = computed(() => quantity.value > 0 && quantity.value <= 99)

/** Gợi ý combo khả dụng (món gợi ý còn bán). */
const availableSuggestions = computed(() =>
    (props.menuItem?.suggestions ?? []).filter((s) => s.suggested_item?.is_available !== false),
)

const addingComboId = ref<number | null>(null)

const selectedToppings = computed(() =>
    toppings.value.filter(t => selectedToppingIds.value.includes(t.id))
)

// ===== ACTIONS =====
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
        console.error('[FoodDetailModal] Failed to load toppings')
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
        spicyLevel.value = props.menuItem.default_spicy ?? 0
        note.value = ''
        selectedToppingIds.value = []
    } else {
        quantity.value = 1
        spicyLevel.value = 0
        note.value = ''
        selectedToppingIds.value = []
    }
    imageLoaded.value = false
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

/** Thêm nhanh món chính (theo tùy chỉnh hiện tại) + món gợi ý đi kèm. */
function handleAddCombo(suggestion: MenuSuggestion) {
    const menuId = props.menuItem?.id ?? 0
    if (!menuId) return
    addingComboId.value = suggestion.id
    emit('add-combo', {
        main: {
            menu_item_id: menuId,
            quantity: quantity.value,
            spicy_level: spicyLevel.value,
            note: note.value.trim(),
            topping_ids: [...selectedToppingIds.value],
        },
        suggested_item_id: suggestion.suggested_item.id,
    })
}

watch(() => props.visible, (val) => {
    if (val) {
        resetForm()
        void loadToppings()
    } else {
        addingComboId.value = null
    }
})
useRealtimeRefresh(async () => { toppings.value = await fetchToppings() }, () => props.visible && !props.loading && !loadingToppings.value)
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div 
                v-if="visible" 
                class="modal-overlay" 
                @click.self="close"
                role="dialog"
                :aria-label="title"
                aria-modal="true"
            >
                <div class="modal-panel">
                    <!-- Close Button (Desktop) -->
                    <button 
                        type="button" 
                        class="close-btn close-btn-desktop" 
                        @click="close" 
                        aria-label="Đóng"
                    >
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>

                    <div class="modal-content">
                        <!-- ===== LEFT: Food Image ===== -->
                        <div class="image-section">
                            <div class="image-container">
                                <!-- Skeleton -->
                                <div v-if="!imageLoaded" class="image-skeleton">
                                    <div class="skeleton-shimmer"></div>
                                </div>
                                
                                <!-- Image -->
                                <img 
                                    :src="imageUrl(menuItem?.image_url || '')"
                                    :alt="menuItem?.name || 'Món ăn'"
                                    class="food-image"
                                    :class="{ loaded: imageLoaded }"
                                    @load="imageLoaded = true"
                                    @error="imageLoaded = true"
                                />
                                
                                <!-- Spicy Badge (only when selected > 0) -->
                                <div
                                    v-if="spicyLevel > 0"
                                    class="spicy-badge"
                                    :style="{ backgroundColor: selectedSpicyInfo.color }"
                                >
                                    <span>{{ selectedSpicyInfo.label }}</span>
                                </div>
                            </div>
                            
                            <!-- Selected Toppings Summary (Mobile) -->
                            <div v-if="selectedToppings.length > 0" class="toppings-summary-mobile">
                                <div class="summary-label">Topping đã chọn</div>
                                <div class="summary-items">
                                    <span v-for="t in selectedToppings" :key="t.id" class="summary-chip">
                                        {{ t.name }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- ===== RIGHT: Customization ===== -->
                        <div class="customize-section">
                            <!-- Close Button (Mobile) -->
                            <button 
                                type="button" 
                                class="close-btn close-btn-mobile" 
                                @click="close" 
                                aria-label="Đóng"
                            >
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </button>

                            <!-- Header -->
                            <div class="section-header">
                                <span class="header-badge">{{ isEditMode ? 'Cập nhật' : 'Thêm món' }}</span>
                                <h2 class="header-title">{{ menuItem?.name }}</h2>
                                <p v-if="menuItem?.description" class="header-desc">{{ menuItem.description }}</p>
                                
                                <div class="header-price">
                                    <span class="price-value">{{ menuItem?.price_formatted }}</span>
                                    <span class="price-unit">/ phần</span>
                                </div>
                            </div>

                            <!-- Scrollable Content -->
                            <div class="section-content">
                                <!-- ===== SPICY LEVEL SELECTOR ===== -->
                                <section class="customize-group">
                                    <div class="group-label-row">
                                        <h3 class="group-label">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="label-icon">
                                                <path d="M12 2c1.5 2 3 4 3 6 0 3-2 4-3 4s-3-1-3-4c0-2 1.5-4 3-6z"/>
                                                <path d="M12 12v10" stroke="currentColor" stroke-width="2" fill="none"/>
                                            </svg>
                                            Cấp độ cay
                                        </h3>
                                        <span 
                                            class="selected-level"
                                            :style="{ color: selectedSpicyInfo.color }"
                                        >
                                            {{ selectedSpicyInfo.label }}
                                        </span>
                                    </div>
                                    
                                    <!-- Segmented Control -->
                                    <div class="spicy-segmented" role="radiogroup" aria-label="Chọn cấp độ cay">
                                        <button
                                            v-for="level in SPICY_LEVELS"
                                            :key="level.value"
                                            type="button"
                                            class="segment-btn"
                                            :class="{ active: spicyLevel === level.value }"
                                            :style="spicyLevel === level.value ? {
                                                backgroundColor: level.color,
                                                color: '#fff',
                                                boxShadow: `0 4px 12px ${level.color}40`
                                            } : {}"
                                            @click="spicyLevel = level.value"
                                            :aria-label="level.label"
                                            :aria-checked="spicyLevel === level.value"
                                            role="radio"
                                        >
                                            <span v-if="level.value === 0">0</span>
                                            <span v-else>{{ level.value }}</span>
                                        </button>
                                    </div>

                                    <!-- Group Labels -->
                                    <div class="spicy-groups">
                                        <div
                                            v-for="(info, groupId) in SPICY_GROUPS"
                                            :key="groupId"
                                            class="spicy-group"
                                            :class="{ active: selectedSpicyInfo.group === Number(groupId) }"
                                            :style="selectedSpicyInfo.group === Number(groupId) ? {
                                                borderColor: info.color,
                                                backgroundColor: `${info.color}10`
                                            } : {}"
                                        >
                                            <span class="group-name">{{ info.name }}</span>
                                        </div>
                                    </div>
                                </section>

                                <!-- ===== TOPPINGS ===== -->
                                <section class="customize-group">
                                    <div class="group-label-row">
                                        <h3 class="group-label">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="label-icon">
                                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                            </svg>
                                            Topping thêm
                                        </h3>
                                        <span v-if="selectedToppingPrice > 0" class="topping-total">
                                            +{{ toppingTotalFormatted }}
                                        </span>
                                    </div>
                                    
                                    <!-- Loading -->
                                    <div v-if="loadingToppings" class="group-loading">
                                        <svg class="spinner" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                        </svg>
                                        Đang tải...
                                    </div>
                                    
                                    <!-- Empty -->
                                    <div v-else-if="toppings.length === 0" class="group-empty">
                                        Hiện không có topping khả dụng
                                    </div>
                                    
                                    <!-- Topping List -->
                                    <div v-else class="topping-grid">
                                        <button
                                            v-for="topping in toppings"
                                            :key="topping.id"
                                            type="button"
                                            class="topping-card"
                                            :class="{ selected: selectedToppingIds.includes(topping.id) }"
                                            :style="selectedToppingIds.includes(topping.id) ? {
                                                borderColor: 'var(--color-chili-red)',
                                                backgroundColor: 'rgba(215, 25, 32, 0.04)'
                                            } : {}"
                                            @click="toggleTopping(topping.id)"
                                        >
                                            <div 
                                                class="topping-check"
                                                :class="{ checked: selectedToppingIds.includes(topping.id) }"
                                            >
                                                <svg v-if="selectedToppingIds.includes(topping.id)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
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

                                <!-- ===== GỢI Ý COMBO NƯỚC UỐNG ===== -->
                                <section v-if="availableSuggestions.length" class="customize-group">
                                    <div class="group-label-row">
                                        <h3 class="group-label">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="label-icon">
                                                <path d="M8 2h8l-1 6H9L8 2z"></path>
                                                <path d="M12 8v13"></path>
                                                <path d="M7 21h10"></path>
                                            </svg>
                                            Gợi ý thêm
                                        </h3>
                                        <span class="topping-total">Mua theo combo tiết kiệm hơn</span>
                                    </div>

                                    <div class="suggestion-list">
                                        <article
                                            v-for="suggestion in availableSuggestions"
                                            :key="suggestion.id"
                                            class="suggestion-card"
                                        >
                                            <div class="suggestion-media">
                                                <img
                                                    :src="imageUrl(suggestion.suggested_item.image_url || '')"
                                                    :alt="suggestion.suggested_item.name"
                                                    loading="lazy"
                                                />
                                            </div>
                                            <div class="suggestion-body">
                                                <strong class="suggestion-name">{{ suggestion.suggested_item.name }}</strong>
                                                <p v-if="suggestion.message" class="suggestion-msg">{{ suggestion.message }}</p>
                                                <div class="suggestion-prices">
                                                    <span class="suggestion-original">
                                                        {{ suggestion.original_price_formatted }}
                                                    </span>
                                                    <span v-if="suggestion.combo_price !== null" class="suggestion-combo">
                                                        Combo {{ suggestion.combo_price_formatted }}
                                                    </span>
                                                    <span v-if="suggestion.saving > 0" class="suggestion-saving">
                                                        Tiết kiệm {{ suggestion.saving_formatted }}
                                                    </span>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                class="suggestion-btn"
                                                :disabled="loading || addingComboId !== null"
                                                @click="handleAddCombo(suggestion)"
                                            >
                                                <span v-if="addingComboId === suggestion.id">Đang thêm…</span>
                                                <span v-else>Thêm combo</span>
                                            </button>
                                        </article>
                                    </div>
                                </section>

                                <!-- ===== NOTES ===== -->
                                <section class="customize-group">
                                    <div class="group-label-row">
                                        <h3 class="group-label">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="label-icon">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                            </svg>
                                            Ghi chú cho bếp
                                        </h3>
                                    </div>
                                    <div class="note-wrap">
                                        <textarea
                                            v-model="note"
                                            class="note-input"
                                            placeholder="Ví dụ: ít hành, thêm nhiều rau, không gia vị..."
                                            rows="3"
                                            maxlength="200"
                                            aria-label="Ghi chú cho bếp"
                                        ></textarea>
                                        <div class="note-counter">{{ note.length }}/200</div>
                                    </div>
                                </section>
                            </div>

                            <!-- ===== STICKY BOTTOM: Quantity + CTA ===== -->
                            <div class="sticky-footer">
                                <!-- Quantity -->
                                <div class="qty-control">
                                    <button
                                        type="button"
                                        class="qty-btn"
                                        :disabled="quantity <= 1"
                                        @click="decreaseQty"
                                        aria-label="Giảm số lượng"
                                    >
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                        </svg>
                                    </button>
                                    <span class="qty-value" aria-live="polite">{{ quantity }}</span>
                                    <button
                                        type="button"
                                        class="qty-btn"
                                        :disabled="quantity >= 99"
                                        @click="increaseQty"
                                        aria-label="Tăng số lượng"
                                    >
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                            <line x1="12" y1="5" x2="12" y2="19"></line>
                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                        </svg>
                                    </button>
                                </div>
                                
                                <!-- Add to Cart -->
                                <button
                                    type="button"
                                    class="add-btn"
                                    :disabled="!canSubmit || loading"
                                    @click="handleSubmit"
                                >
                                    <svg v-if="loading" class="btn-spinner" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                    </svg>
                                    <span v-if="loading">Đang xử lý...</span>
                                    <template v-else>
                                        <span class="btn-text">{{ isEditMode ? 'Lưu thay đổi' : 'Thêm vào giỏ' }}</span>
                                        <span class="btn-price">{{ totalPriceFormatted }}</span>
                                    </template>
                                </button>
                            </div>
                        </div>
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
    background: rgba(17, 17, 17, 0.7);
    backdrop-filter: blur(6px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

/* ===== PANEL ===== */
.modal-panel {
    background: var(--color-white);
    width: 100%;
    max-width: 1000px;
    max-height: 92vh;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
    position: relative;
    display: flex;
    flex-direction: column;
}

/* ===== CONTENT: Split Layout ===== */
.modal-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    height: 100%;
    max-height: 92vh;
}

/* ===== LEFT: Image Section ===== */
.image-section {
    position: relative;
    background: var(--color-cream);
    overflow: hidden;
}

.image-container {
    position: relative;
    width: 100%;
    height: 100%;
    min-height: 400px;
}

.image-skeleton {
    position: absolute;
    inset: 0;
    background: var(--color-gray-200);
    overflow: hidden;
}

.skeleton-shimmer {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(255, 255, 255, 0.5) 50%,
        transparent 100%
    );
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.food-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.4s ease, transform 0.5s ease;
}

.food-image.loaded {
    opacity: 1;
}

.image-section:hover .food-image.loaded {
    transform: scale(1.03);
}

/* Spicy Badge on Image */
.spicy-badge {
    position: absolute;
    top: 16px;
    left: 16px;
    padding: 8px 14px;
    border-radius: var(--radius-full);
    color: white;
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(8px);
}

/* Toppings Summary Mobile */
.toppings-summary-mobile {
    display: none;
}

/* ===== RIGHT: Customize Section ===== */
.customize-section {
    display: flex;
    flex-direction: column;
    max-height: 92vh;
    overflow: hidden;
}

/* Close Button */
.close-btn {
    position: absolute;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-full);
    background: var(--color-white);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    color: var(--color-gray-600);
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.close-btn:hover {
    background: var(--color-charcoal-black);
    color: var(--color-white);
}

.close-btn:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

.close-btn-desktop {
    top: 16px;
    right: 16px;
}

.close-btn-mobile {
    display: none;
}

/* Header */
.section-header {
    padding: 24px 24px 16px;
    flex-shrink: 0;
    border-bottom: 1px solid var(--color-gray-100);
}

.header-badge {
    display: inline-block;
    padding: 4px 10px;
    background: var(--color-chili-red);
    color: var(--color-white);
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
}

.header-title {
    font-size: 24px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin: 0 0 8px;
    letter-spacing: -0.5px;
    line-height: 1.2;
}

.header-desc {
    font-size: 14px;
    color: var(--color-gray-500);
    margin: 0 0 12px;
    line-height: 1.5;
}

.header-price {
    display: flex;
    align-items: baseline;
    gap: 4px;
}

.price-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--color-chili-red);
}

.price-unit {
    font-size: 13px;
    color: var(--color-gray-400);
}

/* Scrollable Content */
.section-content {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* ===== CUSTOMIZE GROUPS ===== */
.customize-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.group-label-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.group-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--color-gray-600);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.label-icon {
    color: var(--color-chili-red);
}

.selected-level {
    font-size: 14px;
    font-weight: 700;
    transition: color 0.2s ease;
}

.topping-total {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-chili-red);
}

.group-loading,
.group-empty {
    color: var(--color-gray-500);
    font-size: 14px;
    padding: 16px 0;
    text-align: center;
}

.group-loading {
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

/* ===== SPICY SEGMENTED CONTROL ===== */
.spicy-segmented {
    display: flex;
    gap: 6px;
    padding: 4px;
    background: var(--color-gray-100);
    border-radius: var(--radius-xl);
}

.segment-btn {
    flex: 1;
    height: 44px;
    border: none;
    border-radius: var(--radius-lg);
    background: transparent;
    color: var(--color-gray-500);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.segment-btn:hover:not(.active) {
    background: var(--color-white);
    color: var(--color-charcoal-black);
}

.segment-btn.active {
    transform: scale(1.02);
}

.segment-btn:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

/* Spicy Groups */
.spicy-groups {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
}

.spicy-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 10px 8px;
    background: var(--color-gray-50);
    border-radius: var(--radius-md);
    border: 2px solid transparent;
    transition: all var(--dur-base) var(--ease-out);
    opacity: 0.5;
}

.spicy-group.active {
    opacity: 1;
    transform: translateY(-2px);
}

.group-name {
    font-size: 11px;
    font-weight: 700;
    color: var(--color-gray-600);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    text-align: center;
}

/* ===== TOPPINGS ===== */
.topping-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.topping-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--color-gray-50);
    border: 2px solid transparent;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: left;
}

.topping-card:hover {
    background: var(--color-gray-100);
}

.topping-card:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

.topping-check {
    width: 22px;
    height: 22px;
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
    font-size: 14px;
    font-weight: 600;
    color: var(--color-charcoal-black);
}

.topping-price {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-500);
    white-space: nowrap;
}

/* ===== GỢI Ý COMBO ===== */
.suggestion-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.suggestion-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    transition: border-color var(--dur-base) var(--ease-out);
}

.suggestion-card:hover {
    border-color: var(--color-chili-red);
}

.suggestion-media {
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--color-gray-100);
}

.suggestion-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.suggestion-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.suggestion-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-charcoal-black);
}

.suggestion-msg {
    margin: 0;
    font-size: 12px;
    color: var(--color-gray-500);
    line-height: 1.4;
}

.suggestion-prices {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
}

.suggestion-original {
    color: var(--color-gray-400);
    text-decoration: line-through;
}

.suggestion-combo {
    font-weight: 700;
    color: var(--color-chili-red);
}

.suggestion-saving {
    font-weight: 700;
    color: #15803d;
}

.suggestion-btn {
    flex-shrink: 0;
    height: 38px;
    padding: 0 14px;
    border: none;
    border-radius: var(--radius-lg);
    background: var(--color-chili-red);
    color: var(--color-white);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background var(--dur-base) var(--ease-out);
}

.suggestion-btn:hover:not(:disabled) {
    background: var(--color-dark-red);
}

.suggestion-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.suggestion-btn:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

/* ===== NOTES ===== */
.note-wrap {
    position: relative;
}

.note-input {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font-size: 14px;
    font-family: inherit;
    resize: none;
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

.note-counter {
    font-size: 12px;
    color: var(--color-gray-400);
    text-align: right;
    margin-top: 6px;
}

/* ===== STICKY FOOTER ===== */
.sticky-footer {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 24px;
    background: var(--color-cream);
    border-top: 1px solid var(--color-gray-200);
    flex-shrink: 0;
}

/* Quantity Control */
.qty-control {
    display: flex;
    align-items: center;
    background: var(--color-white);
    border-radius: var(--radius-xl);
    padding: 4px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--color-gray-200);
}

.qty-btn {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-lg);
    background: transparent;
    border: none;
    color: var(--color-charcoal-black);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.qty-btn:hover:not(:disabled) {
    color: var(--color-chili-red);
    background: var(--color-gray-50);
}

.qty-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.qty-btn:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

.qty-value {
    width: 48px;
    text-align: center;
    font-size: 18px;
    font-weight: 800;
    color: var(--color-charcoal-black);
}

/* Add to Cart Button */
.add-btn {
    flex: 1;
    height: 52px;
    border: none;
    border-radius: var(--radius-xl);
    background: var(--color-chili-red);
    color: var(--color-white);
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    box-shadow: 0 4px 16px rgba(215, 25, 32, 0.35);
}

.add-btn:hover:not(:disabled) {
    background: var(--color-dark-red);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(215, 25, 32, 0.4);
}

.add-btn:active:not(:disabled) {
    transform: scale(0.98);
}

.add-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    box-shadow: none;
}

.add-btn:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
}

.btn-text {
    font-weight: 700;
}

.btn-price {
    font-size: 16px;
    font-weight: 800;
    padding-left: 12px;
    border-left: 1px solid rgba(255, 255, 255, 0.3);
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
    transition: transform 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-from .modal-panel {
    transform: translateY(30px) scale(0.96);
}

.modal-leave-to .modal-panel {
    transform: translateY(30px) scale(0.96);
}

/* ===== RESPONSIVE: Mobile ===== */
@media (max-width: 768px) {
    .modal-overlay {
        align-items: flex-end;
        padding: 0;
    }
    
    .modal-panel {
        max-width: 100%;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        max-height: 95vh;
    }
    
    .modal-content {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr;
        max-height: 95vh;
    }
    
    .close-btn-desktop {
        display: none;
    }
    
    .close-btn-mobile {
        display: flex;
        top: 12px;
        right: 12px;
        position: absolute;
    }
    
    /* Image Section Mobile */
    .image-section {
        max-height: 220px;
        overflow: hidden;
    }
    
    .image-container {
        min-height: 200px;
    }
    
    .food-image.loaded {
        transform: none;
    }
    
    .image-section:hover .food-image.loaded {
        transform: none;
    }
    
    .spicy-badge {
        bottom: 16px;
        top: auto;
    }
    
    .toppings-summary-mobile {
        display: block;
        padding: 12px 16px;
        background: var(--color-gray-50);
        border-bottom: 1px solid var(--color-gray-200);
    }
    
    .summary-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--color-gray-500);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }
    
    .summary-items {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .summary-chip {
        font-size: 12px;
        font-weight: 600;
        padding: 4px 10px;
        background: var(--color-white);
        border-radius: var(--radius-full);
        color: var(--color-charcoal-black);
    }
    
    /* Header Mobile */
    .section-header {
        padding: 16px 16px 12px;
        position: relative;
    }
    
    .header-title {
        font-size: 20px;
    }
    
    .header-price {
        margin-top: 8px;
    }
    
    /* Content Mobile */
    .section-content {
        padding: 16px;
        gap: 20px;
    }
    
    /* Spicy Selector Mobile */
    .spicy-segmented {
        gap: 4px;
        padding: 3px;
    }
    
    .segment-btn {
        height: 40px;
        font-size: 13px;
    }
    
    .spicy-groups {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .spicy-group {
        padding: 10px 6px;
    }
    
    /* Toppings Mobile */
    .topping-grid {
        grid-template-columns: 1fr;
    }
    
    .topping-card {
        padding: 14px;
    }
    
    /* Sticky Footer Mobile */
    .sticky-footer {
        padding: 12px 16px;
        gap: 12px;
    }
    
    .qty-btn {
        width: 40px;
        height: 40px;
    }
    
    .qty-value {
        font-size: 16px;
        width: 40px;
    }
    
    .add-btn {
        height: 48px;
        font-size: 14px;
    }
    
    .btn-price {
        font-size: 14px;
    }
}

@media (max-width: 400px) {
    .spicy-segmented {
        gap: 2px;
    }
    
    .segment-btn {
        font-size: 12px;
    }
}

/* ===== REDUCED MOTION ===== */
@media (prefers-reduced-motion: reduce) {
    .segment-btn,
    .qty-btn,
    .topping-card,
    .add-btn,
    .close-btn,
    .spicy-group,
    .food-image {
        transition: none;
    }
    
    .segment-btn:hover:not(.active),
    .qty-btn:hover:not(:disabled),
    .add-btn:hover:not(:disabled) {
        transform: none;
    }
    
    .spicy-badge {
        animation: none;
    }
    
    .spinner,
    .btn-spinner {
        animation: none;
    }
    
    .skeleton-shimmer {
        animation: none;
    }
    
    .modal-enter-active,
    .modal-leave-active {
        transition: none;
    }
}
</style>
