<script setup lang="ts">
import { computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useMenuStore } from '@/stores/menu'
import type { MenuSize } from '@/types/menu'

const store = useMenuStore()
const { categories, filters } = storeToRefs(store)

const totalItems = computed(() => store.totalCount)

const showExtraFilters = ref(false)
function toggleExtraFilters() { showExtraFilters.value = !showExtraFilters.value }

function selectCategory(slug: string | null) {
    void store.setFilter('category', slug)
}

function selectSize(size: MenuSize | null) {
    void store.setFilter('size', size)
}

function setMinPrice(e: Event) {
    const v = (e.target as HTMLInputElement).value
    const n = v === '' ? null : Number(v)
    void store.setFilter('min_price', isNaN(n as number) ? null : n)
}

function setMaxPrice(e: Event) {
    const v = (e.target as HTMLInputElement).value
    const n = v === '' ? null : Number(v)
    void store.setFilter('max_price', isNaN(n as number) ? null : n)
}

function reset() {
    void store.resetFilters()
    showExtraFilters.value = false
}

// Categories use simple text labels — no emoji, restaurant context lives in the menu data
function getCategoryIcon(slug: string): string {
    return slug.replace(/-/g, ' ')
}
</script>

<template>
    <!-- ===== MOBILE STICKY CATEGORY NAV ===== -->
    <div class="mobile-sticky-header">
        <div class="mobile-sticky-inner">
            <div class="category-scroll" role="tablist" aria-label="Danh mục món ăn">
                <button
                    type="button"
                    class="category-tab"
                    :class="{ active: !filters.category }"
                    role="tab"
                    :aria-selected="!filters.category"
                    @click="selectCategory(null)"
                >
                    Tất cả
                    <span class="tab-count">{{ totalItems }}</span>
                </button>
                <button
                    v-for="cat in categories"
                    :key="cat.id"
                    type="button"
                    class="category-tab"
                    :class="{ active: filters.category === cat.slug }"
                    role="tab"
                    :aria-selected="filters.category === cat.slug"
                    @click="selectCategory(cat.slug)"
                >
                    {{ cat.name }}
                    <span class="tab-count">{{ cat.items_count }}</span>
                </button>
            </div>

            <button
                type="button"
                class="filter-toggle-btn"
                :class="{ active: store.activeFilterCount > 0 }"
                @click="toggleExtraFilters"
                aria-label="Bộ lọc khác"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                <span v-if="store.activeFilterCount > 0" class="filter-count">{{ store.activeFilterCount }}</span>
            </button>
        </div>

        <Transition name="slide-down">
            <div v-if="showExtraFilters" class="extra-filters-panel">
                <div class="filter-section">
                    <h4 class="filter-section-title">Kích thước</h4>
                    <div class="size-chips" role="radiogroup">
                        <button type="button" class="size-chip" :class="{ active: !filters.size }" @click="selectSize(null)">Tất cả</button>
                        <button type="button" class="size-chip" :class="{ active: filters.size === 'SMALL' }" @click="selectSize('SMALL')">Nhỏ</button>
                        <button type="button" class="size-chip" :class="{ active: filters.size === 'LARGE' }" @click="selectSize('LARGE')">Lớn</button>
                    </div>
                </div>

                <div class="filter-section">
                    <h4 class="filter-section-title">Khoảng giá</h4>
                    <div class="price-inputs">
                        <input
                            type="number"
                            min="0"
                            step="1000"
                            placeholder="Từ (đ)"
                            :value="filters.min_price ?? ''"
                            @change="setMinPrice"
                            class="price-input"
                            aria-label="Giá tối thiểu"
                        />
                        <span class="price-dash">—</span>
                        <input
                            type="number"
                            min="0"
                            step="1000"
                            placeholder="Đến (đ)"
                            :value="filters.max_price ?? ''"
                            @change="setMaxPrice"
                            class="price-input"
                            aria-label="Giá tối đa"
                        />
                    </div>
                </div>

                <button v-if="store.activeFilterCount > 0" type="button" class="reset-filters-btn" @click="reset">
                    Xóa {{ store.activeFilterCount }} bộ lọc
                </button>
            </div>
        </Transition>
    </div>

    <!-- ===== DESKTOP SIDEBAR ===== -->
    <aside class="menu-sidebar">
        <div class="sidebar-header">
            <h3 class="sidebar-title">Danh mục</h3>
            <button v-if="store.activeFilterCount > 0" type="button" class="reset-link" @click="reset">
                Xóa lọc
            </button>
        </div>

        <nav class="category-nav" aria-label="Danh mục">
            <button
                type="button"
                class="category-item all-item"
                :class="{ active: !filters.category }"
                @click="selectCategory(null)"
            >
                <span class="cat-name">Tất cả món</span>
                <span class="cat-count">{{ totalItems }}</span>
            </button>
            <button
                v-for="cat in categories"
                :key="cat.id"
                type="button"
                class="category-item"
                :class="{ active: filters.category === cat.slug }"
                @click="selectCategory(cat.slug)"
            >
                <span class="cat-name">{{ cat.name }}</span>
                <span class="cat-count">{{ cat.items_count }}</span>
            </button>
        </nav>

        <div class="sidebar-extra">
            <div class="filter-group">
                <h4 class="filter-group-title">Kích thước</h4>
                <div class="size-chips" role="radiogroup">
                    <button type="button" class="size-chip" :class="{ active: !filters.size }" @click="selectSize(null)">Tất cả</button>
                    <button type="button" class="size-chip" :class="{ active: filters.size === 'SMALL' }" @click="selectSize('SMALL')">Nhỏ</button>
                    <button type="button" class="size-chip" :class="{ active: filters.size === 'LARGE' }" @click="selectSize('LARGE')">Lớn</button>
                </div>
            </div>

            <div class="filter-group">
                <h4 class="filter-group-title">Khoảng giá</h4>
                <div class="price-inputs">
                    <input
                        type="number"
                        min="0"
                        step="1000"
                        placeholder="Từ"
                        :value="filters.min_price ?? ''"
                        @change="setMinPrice"
                        class="price-input"
                        aria-label="Giá tối thiểu"
                    />
                    <span class="price-dash">—</span>
                    <input
                        type="number"
                        min="0"
                        step="1000"
                        placeholder="Đến"
                        :value="filters.max_price ?? ''"
                        @change="setMaxPrice"
                        class="price-input"
                        aria-label="Giá tối đa"
                    />
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped>
/* ===== MOBILE STICKY HEADER ===== */
.mobile-sticky-header {
    display: none;
    background: var(--color-white);
    border-bottom: 1px solid var(--color-gray-200);
    position: sticky;
    top: var(--navbar-height-mobile);
    z-index: var(--z-sticky);
}

.mobile-sticky-inner {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 10px var(--space-4);
}

.category-scroll {
    flex: 1;
    display: flex;
    gap: 6px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 0;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    scroll-snap-type: x proximity;
    mask-image: linear-gradient(to right, transparent 0, black 8px, black calc(100% - 24px), transparent 100%);
    -webkit-mask-image: linear-gradient(to right, transparent 0, black 8px, black calc(100% - 24px), transparent 100%);
}

.category-scroll::-webkit-scrollbar {
    display: none;
}

.category-tab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: transparent;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-full);
    color: var(--color-charcoal-black);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    scroll-snap-align: start;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out),
        border-color var(--dur-fast) var(--ease-out);
}

.category-tab:hover {
    border-color: var(--color-chili-red);
    color: var(--color-chili-red);
}

.category-tab.active {
    background: var(--color-chili-red);
    border-color: var(--color-chili-red);
    color: var(--color-white);
}

.tab-count {
    font-size: 11px;
    background: var(--color-gray-100);
    color: var(--color-gray-600);
    padding: 1px 6px;
    border-radius: 8px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.category-tab.active .tab-count {
    background: rgba(255, 255, 255, 0.18);
    color: var(--color-white);
}

.filter-toggle-btn {
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--color-gray-100);
    border: none;
    border-radius: var(--radius-md);
    color: var(--color-gray-600);
    cursor: pointer;
    position: relative;
    transition: background-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
}

.filter-toggle-btn:hover {
    background: var(--color-gray-200);
    color: var(--color-charcoal-black);
}

.filter-toggle-btn.active {
    background: var(--color-chili-red);
    color: var(--color-white);
}

.filter-count {
    position: absolute;
    top: -3px;
    right: -3px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    background: var(--color-chili-red);
    color: var(--color-white);
    font-size: 10px;
    font-weight: 800;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Extra Filters Panel */
.extra-filters-panel {
    padding: var(--space-4);
    background: var(--color-cream);
    border-top: 1px solid var(--color-gray-200);
}

.filter-section {
    margin-bottom: var(--space-4);
}

.filter-section:last-child {
    margin-bottom: 0;
}

.filter-section-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 0 0 var(--space-3);
}

.size-chips {
    display: flex;
    gap: 6px;
}

.size-chip {
    flex: 1;
    padding: 9px 12px;
    background: var(--color-white);
    border: 1.5px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-charcoal-black);
    cursor: pointer;
    transition: border-color var(--dur-fast) var(--ease-out), background-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
}

.size-chip:hover {
    border-color: var(--color-chili-red);
}

.size-chip.active {
    background: var(--color-chili-red);
    border-color: var(--color-chili-red);
    color: var(--color-white);
}

.price-inputs {
    display: flex;
    align-items: center;
    gap: 8px;
}

.price-input-wrap {
    flex: 1;
}

.price-input {
    width: 100%;
    height: 40px;
    padding: 0 12px;
    border: 1.5px solid var(--color-gray-200);
    border-radius: var(--radius-md);
    font-size: 13px;
    font-family: inherit;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    transition: border-color var(--dur-fast) var(--ease-out);
}

.price-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
}

.price-input::placeholder {
    color: var(--color-gray-400);
}

.price-dash {
    color: var(--color-gray-400);
    font-weight: 600;
}

.reset-filters-btn {
    width: 100%;
    margin-top: var(--space-3);
    padding: 10px;
    background: transparent;
    border: 1.5px dashed var(--color-gray-300);
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-gray-500);
    cursor: pointer;
    transition: border-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out), background-color var(--dur-fast) var(--ease-out);
}

.reset-filters-btn:hover {
    border-color: var(--color-chili-red);
    color: var(--color-chili-red);
    background: rgba(215, 25, 32, 0.04);
}

.slide-down-enter-active,
.slide-down-leave-active {
    transition: opacity var(--dur-base) var(--ease-out), transform var(--dur-base) var(--ease-out), max-height var(--dur-base) var(--ease-out);
    overflow: hidden;
}

.slide-down-enter-from,
.slide-down-leave-to {
    opacity: 0;
    max-height: 0;
    transform: translateY(-4px);
}

.slide-down-enter-to,
.slide-down-leave-from {
    opacity: 1;
    max-height: 400px;
    transform: translateY(0);
}

/* ===== DESKTOP SIDEBAR ===== */
.menu-sidebar {
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    position: sticky;
    top: 96px;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
}

.sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-5);
    border-bottom: 1px solid var(--color-gray-100);
}

.sidebar-title {
    font-size: 14px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0;
}

.reset-link {
    min-height: 36px;
    background: var(--color-cream, #fff8f4);
    border: 1px solid rgba(215, 25, 32, 0.35);
    color: var(--color-chili-red);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 999px;
    transition: background-color var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out);
}

.reset-link:hover {
    background: rgba(215, 25, 32, 0.08);
    transform: translateY(-1px);
}

.reset-link:active {
    transform: translateY(0);
}

.reset-link:focus-visible {
    outline: 3px solid #d71920;
    outline-offset: 2px;
}

.category-nav {
    padding: var(--space-3);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.category-item {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: transparent;
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    text-align: left;
    font-size: 14px;
    color: var(--color-charcoal-black);
    transition: background-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
    font-weight: 500;
}

.category-item:hover {
    background: var(--color-cream);
    color: var(--color-chili-red);
}

.category-item.active {
    background: var(--color-chili-red);
    color: var(--color-white);
    font-weight: 600;
}

.all-item {
    margin-bottom: 4px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--color-gray-100);
    border-radius: var(--radius-md);
}

.cat-count {
    font-size: 12px;
    background: var(--color-gray-100);
    color: var(--color-gray-600);
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.category-item.active .cat-count {
    background: rgba(255, 255, 255, 0.2);
    color: var(--color-white);
}

/* Sidebar Extra */
.sidebar-extra {
    padding: 0 var(--space-5) var(--space-5);
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
    border-top: 1px solid var(--color-gray-100);
    padding-top: var(--space-5);
    margin-top: var(--space-3);
}

.filter-group-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 0 0 var(--space-3);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .mobile-sticky-header {
        display: block;
    }
    .menu-sidebar {
        display: none;
    }
}
</style>
