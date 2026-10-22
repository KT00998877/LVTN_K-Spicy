<script setup lang="ts">
import { computed } from 'vue'
import type { MenuItemCard } from '@/types/home'
import { imageUrl } from '@/utils/image'
import RatingBadge from '@/components/review/RatingBadge.vue'

const props = defineProps<{
    item: MenuItemCard
}>()

const emit = defineEmits<{
    (e: 'addToCart', item: MenuItemCard): void
}>()

// Spicy info
const spicyInfo = computed(() => {
    if (props.item.spicy_level === 0) {
        return { label: 'Không cay', color: 'var(--color-spicy-0)', bars: 0 }
    }
    const group = Math.min(Math.ceil(props.item.spicy_level / 2), 4)
    return {
        label: `Cấp ${props.item.spicy_level}`,
        color: `var(--color-spicy-${group})`,
        bars: Math.min(props.item.spicy_level, 7),
    }
})

const hasSpicy = computed(() => props.item.spicy_level > 0)
const hasSize = computed(() => props.item.size !== null && props.item.size !== undefined)

const prepTime = computed(() => {
    if (!props.item.preparation_minutes) return null
    return `${props.item.preparation_minutes} phút`
})

// Trạng thái hết món — động theo lý do từ backend (xem MenuAvailabilityService::status).
const soldOutState = computed(() => {
    if (props.item.is_available !== false) return null
    const reason = props.item.availability_reason ?? 'manual'
    if (reason === 'ingredient') {
        return {
            tone: 'warn' as const,
            label: 'Tạm hết nguyên liệu',
            detail: props.item.sold_out_reason ?? 'Bếp đang chờ nhập hàng.',
        }
    }
    if (reason === 'insufficient_data') {
        return {
            tone: 'muted' as const,
            label: 'Tạm hết món',
            detail: props.item.sold_out_reason ?? 'Bếp đang kiểm tra lại định lượng.',
        }
    }
    return {
        tone: 'manual' as const,
        label: 'Hết món',
        detail: props.item.sold_out_reason ?? 'Bếp trưởng vừa báo hết.',
    }
})

function handleAdd(e?: Event) {
    e?.stopPropagation()
    if (props.item.is_available === false) return
    emit('addToCart', props.item)
}
</script>

<template>
    <article
        class="food-card"
        :class="{ 'food-card--sold-out': item.is_available === false }"
        :tabindex="item.is_available === false ? -1 : 0"
        :aria-disabled="item.is_available === false ? 'true' : undefined"
        :title="soldOutState?.detail"
        @click="handleAdd"
        @keydown.enter="handleAdd"
        @keydown.space.prevent="handleAdd"
    >
        <!-- Image -->
        <div class="card-image-wrap">
            <span
                v-if="soldOutState"
                class="sold-out-badge"
                :class="`sold-out-badge--${soldOutState.tone}`"
            >
                {{ soldOutState.label }}
            </span>
            <img
                :src="imageUrl(item.image_url)"
                :alt="item.name"
                class="card-image"
                loading="lazy"
                @error="(e) => (e.target as HTMLImageElement).src = 'https://placehold.co/400x300/FFF8EF/D71920?text=K-SPICY'"
            />

            <!-- Single contextual indicator: spicy OR size, not both -->
            <span v-if="hasSpicy" class="card-spicy" :style="{ '--c': spicyInfo.color }">
                <span class="spicy-bars" aria-hidden="true">
                    <span
                        v-for="i in 7"
                        :key="i"
                        class="spicy-bar"
                        :class="{ active: i <= spicyInfo.bars }"
                    ></span>
                </span>
                <span class="spicy-label">{{ spicyInfo.label }}</span>
            </span>
            <span v-else-if="hasSize" class="card-size">
                {{ item.size === 'SMALL' ? 'Nhỏ' : 'Lớn' }}
            </span>

            <!-- Rating badge góc dưới trái -->
            <RatingBadge :menu-item-id="item.id" :rating-summary="item.rating_summary" variant="badge" class="card-rating" />
        </div>

        <!-- Body -->
        <div class="card-body">
            <span class="card-category">{{ item.category_name }}</span>
            <h3 class="card-title">{{ item.name }}</h3>
            <p v-if="item.description" class="card-desc">{{ item.description }}</p>

            <div class="card-footer">
                <div class="price-block">
                    <span class="card-price">{{ item.price_formatted }}</span>
                    <span v-if="prepTime" class="card-prep">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        {{ prepTime }}
                    </span>
                    <!-- Microcopy lý do hết (chỉ hiện khi hết món, gọn 1 dòng) -->
                    <span v-if="soldOutState" class="sold-out-reason" :title="soldOutState.detail">
                        {{ soldOutState.detail }}
                    </span>
                </div>

                <button
                    type="button"
                    class="add-btn"
                    :aria-label="item.is_available === false ? `${item.name} đã ${soldOutState?.label.toLowerCase() ?? 'hết món'}` : `Thêm ${item.name} vào giỏ`"
                    :disabled="item.is_available === false"
                    @click.stop="handleAdd"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </div>
        </div>
    </article>
</template>

<style scoped>
.food-card {
    display: flex;
    flex-direction: column;
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    cursor: pointer;
    height: 100%;
    min-width: 0;
    transition:
        transform var(--dur-base) var(--ease-out),
        box-shadow var(--dur-base) var(--ease-out),
        border-color var(--dur-base) var(--ease-out);
}

.food-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
    border-color: var(--color-chili-red);
}

.food-card:active {
    transform: translateY(-1px);
}

.food-card:focus-visible {
    outline: 2px solid var(--color-chili-red);
    outline-offset: 2px;
    border-color: var(--color-chili-red);
}

.food-card--sold-out { cursor: default; }
.food-card--sold-out:hover { transform: none; border-color: var(--color-gray-200); box-shadow: none; }

/* Sold-out badge — 3 tông theo lý do */
.sold-out-badge {
    position: absolute;
    z-index: 1;
    top: 12px;
    right: 12px;
    padding: 6px 10px;
    border-radius: var(--radius-full);
    color: white;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
}
.sold-out-badge--manual { background: #8b1e24; }                       /* bếp báo hết thủ công */
.sold-out-badge--warn   { background: #c2410c; }                       /* thiếu nguyên liệu (Phase D) */
.sold-out-badge--muted  { background: #4b5563; }                       /* chưa đủ dữ liệu tồn/công thức */

/* Microcopy lý do hết — dưới giá, gọn 1 dòng */
.sold-out-reason {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    font-weight: 500;
    color: var(--color-gray-500);
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.add-btn:disabled { background: var(--color-gray-500); box-shadow: none; cursor: not-allowed; }

/* ===== Image ===== */
.card-image-wrap {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    background: var(--color-cream-deep);
}

.card-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--dur-slow) var(--ease-out);
}

.food-card:hover .card-image {
    transform: scale(1.04);
}

/* ===== Spicy indicator (one cohesive pill) ===== */
.card-spicy {
    position: absolute;
    top: 12px;
    left: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px 6px 8px;
    background: var(--c, var(--color-chili-red));
    color: var(--color-white);
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.spicy-bars {
    display: inline-flex;
    gap: 2px;
    align-items: flex-end;
    height: 10px;
}

.spicy-bar {
    width: 2px;
    background: rgba(255, 255, 255, 0.35);
    border-radius: 1px;
    height: 4px;
    transition: background-color var(--dur-fast) var(--ease-out);
}

.spicy-bar.active {
    background: var(--color-white);
}

.spicy-bar.active:nth-child(1) { height: 5px; }
.spicy-bar.active:nth-child(2) { height: 6px; }
.spicy-bar.active:nth-child(3) { height: 7px; }
.spicy-bar.active:nth-child(4) { height: 8px; }
.spicy-bar.active:nth-child(5) { height: 9px; }
.spicy-bar.active:nth-child(6) { height: 10px; }
.spicy-bar.active:nth-child(7) { height: 11px; }

.card-size {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 5px 10px;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}

/* Rating badge (góc dưới trái ảnh) */
.card-rating {
    position: absolute;
    bottom: 10px;
    left: 10px;
}

/* ===== Body ===== */
.card-body {
    padding: var(--space-4) var(--space-5) var(--space-5);
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}

.card-category {
    font-size: 11px;
    font-weight: 700;
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 6px;
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--color-charcoal-black);
    line-height: 1.4;
    margin-bottom: 6px;
    overflow-wrap: break-word;
}

.card-desc {
    color: var(--color-gray-500);
    font-size: 13px;
    line-height: 1.55;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--space-4);
    padding-top: var(--space-3);
    border-top: 1px solid var(--color-gray-100);
}

.price-block {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.card-price {
    color: var(--color-chili-red);
    font-weight: 800;
    font-size: 17px;
    line-height: 1;
    letter-spacing: -0.3px;
}

.card-prep {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--color-gray-500);
    font-weight: 500;
}

/* ===== Add button ===== */
.add-btn {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--color-chili-red);
    color: var(--color-white);
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(215, 25, 32, 0.25);
    transition:
        background-color var(--dur-fast) var(--ease-out),
        transform var(--dur-fast) var(--ease-out);
}

.add-btn:hover {
    background: var(--color-dark-red);
    transform: scale(1.05);
}

.add-btn:active {
    transform: scale(0.95);
}

@media (max-width: 480px) {
    .card-body {
        padding: var(--space-3) var(--space-4) var(--space-4);
    }
    .card-title {
        font-size: 15px;
    }
    .card-price {
        font-size: 16px;
    }
    .add-btn {
        width: 36px;
        height: 36px;
    }
}
</style>
