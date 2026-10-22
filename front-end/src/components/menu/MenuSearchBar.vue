<script setup lang="ts">
import { ref, watch } from 'vue'
import { useMenuStore } from '@/stores/menu'
import { storeToRefs } from 'pinia'
import type { MenuSort } from '@/types/menu'

const store = useMenuStore()
const { filters } = storeToRefs(store)

const localSearch = ref(filters.value.search)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

// Sync local ↔ store khi URL thay đổi (back/forward).
watch(() => filters.value.search, (v) => {
    if (v !== localSearch.value) localSearch.value = v
})

function onSearchInput(e: Event) {
    const v = (e.target as HTMLInputElement).value
    localSearch.value = v
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
        void store.setFilter('search', v)
    }, 350)
}

function clearSearch() {
    localSearch.value = ''
    if (debounceTimer) clearTimeout(debounceTimer)
    void store.setFilter('search', '')
}

const sortOptions: Array<{ value: MenuSort; label: string }> = [
    { value: 'default',    label: 'Mặc định' },
    { value: 'price_asc',  label: 'Giá tăng dần' },
    { value: 'price_desc', label: 'Giá giảm dần' },
    { value: 'name_asc',   label: 'Tên A → Z' },
    { value: 'name_desc',  label: 'Tên Z → A' },
    { value: 'newest',     label: 'Mới nhất' },
]

function onSortChange(e: Event) {
    const v = (e.target as HTMLSelectElement).value as MenuSort
    void store.setFilter('sort', v)
}
</script>

<template>
    <div class="search-section">
        <div class="search-bar-container">
            <div class="search-input-group">
                <label for="menu-search" class="sr-only">Tìm kiếm món</label>
                <div class="search-input-wrapper">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input
                        id="menu-search"
                        type="search"
                        :value="localSearch"
                        @input="onSearchInput"
                        placeholder="Tìm món yêu thích..."
                        class="search-input"
                        autocomplete="off"
                    />
                    <button
                        v-if="localSearch"
                        type="button"
                        class="search-clear"
                        @click="clearSearch"
                        aria-label="Xóa tìm kiếm"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="sort-group">
                <label for="sort-select" class="sort-label">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" y1="6" x2="20" y2="6"></line>
                        <line x1="4" y1="12" x2="14" y2="12"></line>
                        <line x1="4" y1="18" x2="8" y2="18"></line>
                    </svg>
                    Sắp xếp
                </label>
                <select
                    id="sort-select"
                    :value="filters.sort"
                    @change="onSortChange"
                    class="sort-select"
                >
                    <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ===== SEARCH SECTION ===== */
.search-section {
    position: relative;
    z-index: 10;
    background: var(--color-cream);
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    padding: 20px 0;
}

.search-bar-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}

/* ===== SEARCH INPUT ===== */
.search-input-group {
    flex: 1;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 18px;
    color: var(--color-gray-400);
    pointer-events: none;
    display: flex;
    align-items: center;
    transition: color 0.2s ease;
}

.search-input {
    width: 100%;
    height: 52px;
    padding: 0 52px;
    border: 2px solid transparent;
    border-radius: var(--radius-xl);
    font-size: 16px;
    background: var(--color-white);
    transition: all 0.25s ease;
    font-family: inherit;
    color: var(--color-charcoal-black);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.search-input:focus {
    outline: none;
    border-color: var(--color-chili-red);
    background: var(--color-white);
    box-shadow: 0 4px 16px rgba(215, 25, 32, 0.12);
}

.search-input:focus + .search-icon,
.search-input-wrapper:focus-within .search-icon {
    color: var(--color-chili-red);
}

.search-input::placeholder {
    color: var(--color-gray-400);
}

/* Remove default search input x button */
.search-input::-webkit-search-cancel-button {
    display: none;
}

.search-clear {
    position: absolute;
    right: 14px;
    width: 28px;
    height: 28px;
    border: none;
    background: var(--color-gray-100);
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-gray-600);
    transition: all 0.2s ease;
}

.search-clear:hover {
    background: var(--color-chili-red);
    color: var(--color-white);
}

/* ===== SORT ===== */
.sort-group {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
}

.sort-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--color-gray-500);
    font-weight: 600;
}

.sort-select {
    height: 52px;
    padding: 0 44px 0 16px;
    border: 2px solid transparent;
    border-radius: var(--radius-xl);
    font-size: 15px;
    font-weight: 500;
    color: var(--color-charcoal-black);
    background: var(--color-white);
    cursor: pointer;
    transition: all 0.25s ease;
    font-family: inherit;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
}

.sort-select:hover {
    border-color: var(--color-gray-200);
}

.sort-select:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 4px 16px rgba(215, 25, 32, 0.12);
}

/* Screen reader only */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .search-section {
        padding: 16px 0;
    }
    
    .search-bar-container {
        flex-direction: column;
        gap: 12px;
        padding: 0 16px;
    }
    
    .search-input-group {
        width: 100%;
    }
    
    .search-input {
        height: 48px;
        font-size: 15px;
    }
    
    .sort-group {
        width: 100%;
        justify-content: space-between;
        padding: 0 4px;
    }
    
    .sort-select {
        flex: 1;
        max-width: 200px;
        height: 48px;
    }
}

@media (max-width: 480px) {
    .sort-label {
        font-size: 0;
    }
    .sort-label svg {
        font-size: 18px;
    }
    .sort-select {
        flex: 1;
    }
}
</style>
