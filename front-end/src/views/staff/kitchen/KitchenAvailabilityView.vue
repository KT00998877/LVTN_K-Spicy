<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/api/client'
import IngredientStockChips from '@/components/manager/IngredientStockChips.vue'

type Item = { id: number; name: string; category: string | null; is_available: boolean; sold_out_reason?: string | null; effective_availability: { is_available: boolean; reason: string | null } }
const items = ref<Item[]>([])
const loading = ref(false)
const saving = ref<number | null>(null)
const error = ref('')
const search = ref('')
const visible = computed(() => items.value.filter(item => item.name.toLocaleLowerCase('vi').includes(search.value.trim().toLocaleLowerCase('vi'))))

async function load() {
    loading.value = true
    error.value = ''
    try {
        const response = await api.get<{ data: Item[] }>('/staff/menu-items/availability')
        items.value = response.data.data
    } catch {
        error.value = 'Không tải được danh sách món. Vui lòng thử lại.'
    } finally { loading.value = false }
}

async function toggle(item: Item) {
    saving.value = item.id
    error.value = ''
    try {
        await api.patch(`/staff/menu-items/${item.id}/availability`, { is_available: !item.is_available })
        await load()
    } catch {
        error.value = `Không đổi được trạng thái của ${item.name}. Vui lòng thử lại.`
    } finally { saving.value = null }
}

onMounted(load)
</script>

<template>
    <section class="availability-page">
        <header><div><h2>Báo hết món</h2><p>Món hết vẫn hiện với khách nhưng không thể đặt. Bấm “Mở bán” khi bếp đã sẵn sàng.</p></div><button type="button" @click="load" :disabled="loading">Làm mới</button></header>
        <label for="availability-search">Tìm món hoặc topping</label>
        <input id="availability-search" v-model="search" type="search" autocomplete="off" />
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="loading" role="status">Đang tải…</p>
        <p v-else-if="visible.length === 0" role="status">Không có món phù hợp.</p>
        <ul v-else>
            <li v-for="item in visible" :key="item.id">
                <div>
                    <strong>{{ item.name }}</strong>
                    <small>{{ item.category }} · {{ !item.is_available ? 'Bếp báo hết' : item.effective_availability.reason === 'ingredient' ? 'Hết nguyên liệu' : item.effective_availability.reason === 'insufficient_data' ? 'Chưa đủ dữ liệu kho, bếp quyết định thủ công' : 'Đang bán' }}</small>
                    <IngredientStockChips v-if="!item.is_available || item.effective_availability.reason === 'ingredient' || item.effective_availability.reason === 'insufficient_data'" :menu-item-id="item.id" />
                </div>
                <button type="button" :disabled="saving !== null" @click="toggle(item)">{{ saving === item.id ? 'Đang lưu…' : item.is_available ? 'Báo hết' : 'Mở bán' }}</button>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.availability-page { display: grid; gap: 14px; max-width: 900px; }
header, li { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
header p { color: #52525b; }
input { width: 100%; max-width: 400px; padding: 10px; border: 1px solid #a1a1aa; border-radius: 8px; }
ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
li { background: white; border: 1px solid #e4e4e7; border-radius: 10px; padding: 14px; }
small { display: block; margin-top: 4px; color: #52525b; }
button { padding: 9px 14px; border: 1px solid #991b1b; border-radius: 8px; color: #991b1b; background: white; cursor: pointer; white-space: nowrap; }
button:disabled { opacity: .6; cursor: wait; }
button:focus-visible, input:focus-visible { outline: 3px solid #b91c1c; outline-offset: 2px; }
@media (max-width: 560px) { header, li { align-items: flex-start; flex-direction: column; } }
</style>
