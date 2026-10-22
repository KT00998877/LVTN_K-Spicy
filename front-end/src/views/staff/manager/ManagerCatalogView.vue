<template>
    <div class="catalog">
        <header class="manager-heading"><div><span class="eyebrow">QUẢN LÝ THỰC ĐƠN</span><h2>{{ categoriesMode ? 'Danh mục món ăn' : 'Món ăn' }}</h2><p>{{ categoriesMode ? 'Phân nhóm thực đơn, thiết lập thứ tự và trạng thái danh mục.' : 'Quản lý thông tin món, giá bán, ảnh minh họa và trạng thái phục vụ.' }}</p></div><button class="manager-button primary" :disabled="loading" @click="edit()">{{ categoriesMode ? 'Thêm danh mục' : 'Thêm món ăn' }}</button></header>
        <div class="summary"><span>{{ rows.length }} {{ categoriesMode ? 'danh mục' : 'món ăn' }}</span><span>{{ rows.filter(r => r.is_active).length }} đang hiển thị</span><RouterLink :to="categoriesMode ? '/staff/manager/menu' : '/staff/manager/categories'">{{ categoriesMode ? 'Quản lý món ăn' : 'Quản lý danh mục' }}</RouterLink></div>
        <div class="filters"><input v-model="search" aria-label="Tìm kiếm" placeholder="Tìm theo tên…" /><select v-if="!categoriesMode" v-model="categoryFilter" aria-label="Danh mục"><option value="">Tất cả danh mục</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select><select v-model="status" aria-label="Trạng thái"><option value="all">Tất cả trạng thái</option><option value="active">Đang hiển thị</option><option value="hidden">Đã ẩn</option><option v-if="!categoriesMode" value="unavailable">Ngừng bán</option></select><button class="manager-button" :disabled="loading" @click="load()">Làm mới</button></div>
        <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p><p v-if="loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>
        <template v-else-if="!error"><p class="result-count">{{ filtered.length }} kết quả</p><p v-if="!filtered.length" class="manager-notice">Chưa có kết quả. Hãy thêm mới hoặc thay đổi bộ lọc.</p>
            <div v-else class="catalog-table"><table><thead><tr><th>{{ categoriesMode ? 'Danh mục' : 'Món ăn' }}</th><th>{{ categoriesMode ? 'Số món' : 'Danh mục' }}</th><th>{{ categoriesMode ? 'Thứ tự' : 'Giá bán' }}</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody><tr v-for="row in paged" :key="row.id"><td><div class="item-name"><img v-if="!categoriesMode && row.image_url" :src="row.image_url" :alt="row.name" loading="lazy" /><div><strong>{{ row.name }}</strong><small>{{ row.description || 'Chưa có mô tả' }}</small></div></div></td><td>{{ categoriesMode ? row.menu_items_count : row.category }}</td><td>{{ categoriesMode ? row.sort_order : money(row.price || 0) }}</td><td><span class="status" :class="{ muted: !row.is_active || (!categoriesMode && !row.is_available) }">{{ !row.is_active ? 'Đã ẩn' : !categoriesMode && !row.is_available ? 'Ngừng bán' : 'Đang hiển thị' }}</span></td><td><button class="manager-button" @click="edit(row)">Chỉnh sửa</button></td></tr></tbody></table></div>
            <div v-if="filtered.length > pageSize" class="pagination"><button class="manager-button" :disabled="page === 1" @click="page--">Trước</button><span>Trang {{ page }} / {{ Math.ceil(filtered.length / pageSize) }}</span><button class="manager-button" :disabled="page * pageSize >= filtered.length" @click="page++">Sau</button></div>
        </template>
        <dialog ref="dialog" @cancel="cancelDialog" @click="backdrop">
            <form @submit.prevent="save">
                <header><h3>{{ editingId ? 'Chỉnh sửa' : 'Thêm mới' }} {{ categoriesMode ? 'danh mục' : 'món ăn' }}</h3><button type="button" :disabled="saving" @click="close">Đóng</button></header>
                <div class="form-body"><p v-if="formError" class="manager-notice error" role="alert">{{ formError }}</p>
                    <label>Tên {{ categoriesMode ? 'danh mục' : 'món ăn' }}<input v-model="form.name" required :maxlength="categoriesMode ? 100 : 150" :disabled="saving" /></label>
                    <label>Mô tả<textarea v-model="form.description" :maxlength="categoriesMode ? 255 : 5000" rows="3" :disabled="saving" /></label>
                    <template v-if="!categoriesMode">
                        <label>Danh mục<select v-model.number="form.category_id" required :disabled="saving"><option :value="0" disabled>Chọn danh mục</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}{{ c.is_active ? '' : ' (đã ẩn)' }}</option></select></label>
                        <p v-if="!categories.length" class="help">Hãy tạo danh mục trước khi thêm món.</p>
                        <div class="form-grid"><label>Giá bán (đ)<input v-model.number="form.price" type="number" min="0" max="9999999999.99" step="0.01" required :disabled="saving" /></label><label>Giá vốn (đ)<input v-model.number="form.cost_price" type="number" min="0" max="9999999999.99" step="0.01" required :disabled="saving" /></label><label>Thời gian chế biến (phút)<input v-model.number="form.preparation_minutes" type="number" min="0" max="1440" required :disabled="saving" /></label><label>Độ cay mặc định<select v-model.number="form.spicy_level" :disabled="saving"><option v-for="n in 6" :key="n" :value="n - 1">{{ n === 1 ? 'Không cay' : 'Cấp ' + (n - 1) }}</option></select></label><label>Kích cỡ<select v-model="form.size" :disabled="saving"><option :value="null">Không phân loại</option><option value="SMALL">Nhỏ</option><option value="LARGE">Lớn</option></select></label></div>
                        <label>Ảnh món ăn<input type="file" accept="image/jpeg,image/png,image/webp" :disabled="saving" @change="chooseImage" /></label><p class="help">JPG, PNG hoặc WebP, tối đa 5 MB.</p><img v-if="previewImage || form.image_url" class="preview" :src="previewImage || form.image_url || ''" alt="Ảnh món ăn xem trước" /><button v-if="previewImage || form.image_url" type="button" :disabled="saving" @click="removeImage">Bỏ ảnh</button>
                        <label class="check"><input v-model="form.is_available" type="checkbox" :disabled="saving" /> Cho phép bán món</label>
                    </template>
                    <label v-else>Thứ tự hiển thị<input v-model.number="form.sort_order" type="number" min="0" max="65535" required :disabled="saving" /></label>
                    <label class="check"><input v-model="form.is_active" type="checkbox" :disabled="saving" /> Hiển thị {{ categoriesMode ? 'danh mục' : 'món ăn' }}</label><p class="help">{{ categoriesMode ? 'Ẩn danh mục sẽ ẩn các món thuộc danh mục trên thực đơn khách hàng.' : 'Ẩn hoặc ngừng bán để ngưng sử dụng món; lịch sử đơn hàng vẫn được giữ lại.' }}</p>
                </div>
                <footer><button type="button" class="manager-button" :disabled="saving" @click="close">Hủy</button><button class="manager-button primary" :disabled="saving || (!categoriesMode && !form.category_id)">{{ saving ? 'Đang lưu…' : 'Lưu thay đổi' }}</button></footer>
            </form>
        </dialog>
    </div>
</template>
<script setup lang="ts">
import { beginRead } from '@/utils/realtime'
const liveReads = {}
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import apiClient from '@/api/client'
interface Row { id: number; name: string; description: string | null; is_active: boolean; category_id?: number; category?: string; price?: number; cost_price?: number; is_available?: boolean; image_url?: string | null; preparation_minutes?: number; spicy_level?: number; size?: 'SMALL' | 'LARGE' | null; sort_order?: number; menu_items_count?: number }
const route = useRoute(), categoriesMode = computed(() => route.path.endsWith('/categories'))
const rows = ref<Row[]>([]), categories = ref<Row[]>([]), loading = ref(false), error = ref(''), formError = ref(''), saving = ref(false)
const search = ref(''), categoryFilter = ref<number | ''>(''), status = ref('all'), page = ref(1), pageSize = 15
const dialog = ref<HTMLDialogElement>(), editingId = ref<number | null>(null), file = ref<File | null>(null), previewImage = ref('')
const defaults = () => ({ name: '', description: '', category_id: 0, price: 0, cost_price: 0, is_active: true, is_available: true, image_url: null as string | null, preparation_minutes: 15, spicy_level: 0, size: null as 'SMALL' | 'LARGE' | null, sort_order: 0 })
const form = reactive(defaults())
const filtered = computed(() => rows.value.filter(r => r.name.toLocaleLowerCase('vi').includes(search.value.trim().toLocaleLowerCase('vi')) && (!categoryFilter.value || categoriesMode.value || r.category_id === categoryFilter.value) && (status.value === 'all' || (status.value === 'active' ? r.is_active : status.value === 'hidden' ? !r.is_active : !r.is_available))))
const paged = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize))
const money = (n: number) => n.toLocaleString('vi-VN') + ' đ'
let requestId = 0
async function load(quiet = false) {
    const id = ++requestId; if (!quiet) loading.value = true; if (!quiet) error.value = ''
    const fresh = beginRead(liveReads, 'list', quiet); try {
        const [c, m] = await Promise.all([apiClient.get<{ data: Row[] }>('/staff/dashboard/categories'), categoriesMode.value ? Promise.resolve(null) : apiClient.get<{ data: Row[] }>('/staff/dashboard/menu-items')])
        if (!fresh() || id !== requestId) return
        error.value = ""
        categories.value = c.data.data; rows.value = m ? m.data.data : c.data.data
    } catch (refreshError) { if (quiet) throw refreshError; if (id === requestId) error.value = 'Không tải được dữ liệu. Vui lòng bấm Làm mới.' } finally { if (id === requestId) if (!quiet) loading.value = false }
}
function clearFile() { if (previewImage.value) URL.revokeObjectURL(previewImage.value); previewImage.value = ''; file.value = null }
function edit(row?: Row) { editingId.value = row?.id ?? null; Object.assign(form, defaults(), row || {}, { description: row?.description || '' }); clearFile(); formError.value = ''; dialog.value?.showModal() }
function close() { if (!saving.value) { dialog.value?.close(); clearFile() } }
function cancelDialog(e: Event) { if (saving.value) e.preventDefault(); else clearFile() }
function backdrop(e: MouseEvent) { if (e.target === dialog.value) close() }
function removeImage() { clearFile(); form.image_url = null }
function chooseImage(e: Event) { const input = e.target as HTMLInputElement; const selected = input.files?.[0]; input.value = ''; if (!selected) return; if (selected.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(selected.type)) { formError.value = 'Chọn ảnh JPG, PNG hoặc WebP không quá 5 MB.'; return }; clearFile(); file.value = selected; previewImage.value = URL.createObjectURL(selected); formError.value = '' }
async function save() {
    if (saving.value) return; saving.value = true; formError.value = ''
    try {
        if (file.value) { const body = new FormData(); body.append('image', file.value); form.image_url = (await apiClient.post<{ data: { url: string } }>('/staff/dashboard/menu-images', body, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data.url; clearFile() }
        const payload = categoriesMode.value ? { name: form.name.trim(), description: form.description, sort_order: form.sort_order, is_active: form.is_active } : { ...form, name: form.name.trim() }
        const url = '/staff/dashboard/' + (categoriesMode.value ? 'categories' : 'menu-items')
        if (editingId.value) await apiClient.patch(url + '/' + editingId.value, payload); else await apiClient.post(url, payload)
        dialog.value?.close(); clearFile(); await load()
    } catch (e: any) { formError.value = Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || 'Không lưu được. Vui lòng thử lại.' } finally { saving.value = false }
}
watch([search, status, categoryFilter], () => page.value = 1)
watch(() => route.path, () => { close(); page.value = 1; search.value = ''; status.value = 'all'; void load() })
onMounted(load); onUnmounted(() => { requestId++; clearFile() })
useRealtimeRefresh(() => load(true), () => !loading.value && !saving.value)
</script>
<style scoped>
.catalog { display: flex; flex-direction: column; gap: 18px; }.summary,.filters,.pagination { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }.summary { padding: 18px; background: #eaf2ed; border-radius: 10px; font-size: 14px; }.summary a { margin-left: auto; color: #245440; }.filters input { flex: 1; }.catalog-table { overflow-x: auto; background: white; border: 1px solid var(--color-border); border-radius: 12px; }table { width: 100%; min-width: 760px; border-collapse: collapse; font-size: 13px; }th,td { text-align: left; padding: 16px; border-bottom: 1px solid #edf1ee; }th { background: var(--color-brand-soft); color: #63736b; font-size: 12px; }td:nth-child(3) { white-space: nowrap; font-variant-numeric: tabular-nums; }.item-name { display: flex; gap: 12px; align-items: center; max-width: 360px; }.item-name img { width: 64px; height: 56px; object-fit: cover; border-radius: 8px; }.item-name small { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; color: #64748b; margin-top: 5px; }.status { white-space: nowrap; padding: 5px 8px; border-radius: 5px; background: #eaf5ee; color: #166534; font-size: 12px; }.status.muted { background: #f1f5f9; color: #64748b; }.pagination { justify-content: flex-end; font-size: 13px; }input,select,textarea { padding: 11px; border: 1px solid var(--color-border-strong); border-radius: 7px; font: inherit; box-sizing: border-box; min-width: 0; }.primary { background: var(--color-brand-action); color: white; }dialog { width: min(640px, calc(100% - 32px)); max-height: 90vh; padding: 0; border: 0; border-radius: 14px; color: var(--color-text); font: 14px var(--font-ui); }dialog::backdrop { background: #10261dc0; }dialog header,dialog footer { padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; gap: 14px; border-bottom: 1px solid #e2e8f0; }dialog header h3 { margin: 0; }dialog footer { justify-content: flex-end; border-top: 1px solid #e2e8f0; }dialog button { cursor: pointer; }dialog header button { border: 0; background: none; color: #64748b; }.form-body { padding: 24px; display: flex; flex-direction: column; gap: 16px; }.form-body label { display: flex; flex-direction: column; gap: 8px; }.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }.form-body .check { flex-direction: row; align-items: center; }.check input { accent-color: #245440; }.help { color: #64748b; font-size: 12px; margin: 0; line-height: 1.6; }.preview { width: 160px; height: 110px; object-fit: cover; border-radius: 8px; }@media(max-width: 550px) { .form-grid { grid-template-columns: 1fr; }.filters input { width: 100%; }.summary a { margin-left: 0; } }
</style>
