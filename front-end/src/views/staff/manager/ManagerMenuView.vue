<template>
    <div class="manager-menu">
        <header class="manager-heading"><div><span class="eyebrow">QUẢN LÝ NHÀ HÀNG</span><h2>Thực đơn nhà hàng</h2><p>Tra cứu món, điều chỉnh giá và trạng thái mở bán.</p></div><button class="manager-button" :disabled="dashboardStore.loading" @click="dashboardStore.fetchAll()">Làm mới dữ liệu</button></header>
        <p v-if="dashboardStore.loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>
        <p v-else-if="dashboardStore.error" class="manager-notice error" role="alert">{{ dashboardStore.error }} <button @click="dashboardStore.fetchAll()">Thử lại</button></p>
        <div class="toolbar">
            <input v-model="searchQuery" class="search-input" type="text" placeholder="Tìm theo tên món…" aria-label="Tìm món" />
            <select v-model="filterCategory" class="filter-select" aria-label="Danh mục"><option :value="null">Tất cả danh mục</option><option v-for="category in categories" :key="category" :value="category">{{ category }}</option></select>
            <select aria-label="Trạng thái bán" v-model="filterAvailable" class="filter-select">
                <option value="all">Tất cả trạng thái</option>
                <option value="available">Đang bán</option>
                <option value="unavailable">Hết món</option>
            </select>
        </div>

        <p class="result-count">{{ filteredItems.length }} món phù hợp · {{ menuItems.length }} món trong thực đơn</p><p v-if="!dashboardStore.loading && !dashboardStore.error && !filteredItems.length" class="manager-notice">Không có món phù hợp. Hãy thay đổi từ khóa hoặc bộ lọc.</p>
        <div class="menu-grid">
            <div v-for="item in filteredItems" :key="item.id" class="menu-card" :class="{ unavailable: !item.is_available }">
                <div class="menu-image"><img v-if="item.image_url" :src="item.image_url" :alt="item.name" /></div>
                <div class="menu-card-body">
                    <div class="menu-card-top">
                        <div>
                            <div class="menu-name">{{ item.name }}</div>
                            <div class="menu-category">{{ item.category }}</div>
                        </div>
                        <span class="price">{{ formatVND(item.price) }}</span>
                    </div>
                    <div class="menu-actions">
                        <label class="toggle-switch">
                            <input
                                type="checkbox"
                                :checked="item.is_available"
                                :disabled="updating !== null"
                                @change="toggleAvailability(item)"
                            />
                            <span class="toggle-track"></span>
                            <span class="toggle-text">{{ item.is_available ? 'Đang bán' : 'Hết món' }}</span>
                        </label>
                        <button class="manager-button" title="Cập nhật giá" @click="openEditDialog(item)">Sửa giá</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit dialog -->
        <Teleport to="body">
            <div v-if="editingItem" class="modal-backdrop" @click.self="editingItem = null">
                <div class="modal-dialog" role="dialog" aria-modal="true" aria-label="Cập nhật giá món">
                    <div class="modal-header">
                        <h3>Sửa giá — {{ editingItem.name }}</h3>
                        <button class="modal-close" @click="editingItem = null">Đóng</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="form-label">Giá bán</label>
                            <input v-model.number="editForm.price" type="number" min="0" step="1000" class="form-input" aria-label="Giá bán" />
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" @click="editingItem = null">Hủy</button>
                        <button class="btn btn-primary" @click="saveEdit" :disabled="saving">Lưu thay đổi</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useDashboardStore } from '@/stores/staffDashboard'
import { useToast, formatVND } from '@/composables/useStaffUtils'
import type { MenuItemAdmin } from '@/api/staff'

const dashboardStore = useDashboardStore()
const { menuItems } = storeToRefs(dashboardStore)
const toast = useToast()

const filterCategory = ref<string | null>(null)
const searchQuery = ref('')
const filterAvailable = ref<'all' | 'available' | 'unavailable'>('all')
const editingItem = ref<MenuItemAdmin | null>(null)
const saving = ref(false)

const editForm = reactive({ price: 0 })
const categories = computed(() => [...new Set(menuItems.value.map(item => item.category))].filter((category): category is string => !!category))
const updating = ref<number | null>(null)

onMounted(() => dashboardStore.fetchAll())

const filteredItems = computed(() => {
    return menuItems.value
        .filter(m => !filterCategory.value || m.category === filterCategory.value)
        .filter(m => !searchQuery.value || m.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
        .filter(m => {
            if (filterAvailable.value === 'available') return m.is_available
            if (filterAvailable.value === 'unavailable') return !m.is_available
            return true
        })
})

async function toggleAvailability(item: MenuItemAdmin): Promise<void> {
    if (updating.value !== null) return
    updating.value = item.id
    try {
        await dashboardStore.toggleMenuItemAvailability(item.id)
        toast.success(`${item.is_available ? 'Báo hết món' : 'Mở bán'}: ${item.name}`)
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi cập nhật')
    } finally { updating.value = null }
}

function openEditDialog(item: MenuItemAdmin): void {
    editingItem.value = item
    editForm.price = item.price
}

async function saveEdit(): Promise<void> {
    if (!editingItem.value || saving.value) return
    if (!Number.isFinite(editForm.price) || editForm.price < 0) { toast.error("Giá bán phải là số không âm"); return }
    saving.value = true
    try {
        await dashboardStore.updateMenuItemPrice(editingItem.value.id, editForm.price)
        toast.success('Đã cập nhật giá')
        editingItem.value = null
    } catch (err) {
        toast.error(err instanceof Error ? err.message : 'Lỗi')
    } finally {
        saving.value = false
    }
}
useRealtimeRefresh(() => dashboardStore.fetchAll(true), () => !dashboardStore.loading)
</script>

<style scoped>
.menu-image img { width: 100%; height: 100%; object-fit: cover; }.menu-image { height: 160px !important; background: #f1f5f9 !important; }.modal-close { font-size: 13px !important; }.toggle-switch input { display: block !important; accent-color: #166534; }.toggle-track { display: none; }
.manager-menu { display: flex; flex-direction: column; gap: 16px; }

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-select,
.search-input {
    padding: 9px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    background: #fff;
    font-family: inherit;
}

.search-input { flex: 1; min-width: 200px; }

.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.menu-card {
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: transform 0.15s;
}

.menu-card:hover { transform: translateY(-2px); }

.menu-card.unavailable { opacity: 0.6; }

.menu-image {
    height: 100px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    font-size: 48px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
}

.menu-card-body { padding: 14px; }

.menu-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.menu-name { font-weight: 700; font-size: 14px; }
.menu-category { font-size: 11px; color: #6b7280; margin-top: 2px; }

.price {
    font-weight: 700;
    color: #f97316;
    font-size: 15px;
}

.menu-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 12px;
    padding: 6px 8px;
    background: #f9fafb;
    border-radius: 6px;
}

.profit { color: #16a34a; font-weight: 600; }

.menu-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.toggle-switch {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.toggle-switch input { display: none; }

.toggle-track {
    width: 36px;
    height: 20px;
    background: #d1d5db;
    border-radius: 10px;
    position: relative;
    transition: background 0.2s;
}

.toggle-track::after {
    content: '';
    width: 14px;
    height: 14px;
    background: #fff;
    border-radius: 50%;
    position: absolute;
    top: 3px;
    left: 3px;
    transition: left 0.2s;
}

.toggle-switch input:checked ~ .toggle-track { background: #16a34a; }
.toggle-switch input:checked ~ .toggle-track::after { left: 19px; }

.toggle-text { font-size: 12px; font-weight: 600; color: #6b7280; }

.btn-icon {
    background: #f3f4f6;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
}

.btn-icon:hover { background: #e5e7eb; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-dialog {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 480px;
}

.modal-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 { margin: 0; font-size: 17px; font-weight: 700; }
.modal-close { background: transparent; border: none; font-size: 22px; color: #6b7280; cursor: pointer; }

.modal-body { padding: 20px; }
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.form-group { margin-bottom: 12px; }
.form-label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
.form-input {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
}

.btn { padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 13px; }
.btn-secondary { background: #e5e7eb; color: #1f2937; }
.btn-primary { background: var(--color-brand-action); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--color-brand-action-hover); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
