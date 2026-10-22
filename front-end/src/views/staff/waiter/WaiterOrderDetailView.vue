<template>
    <div v-if="order" class="order-screen">
        <header class="order-header">
            <div class="heading">
                <RouterLink class="back" to="/staff/waiter/tables">Quay lại</RouterLink>
                <h2>{{ order.table_code ? 'Bàn ' + order.table_code : order.code }}</h2>
            </div>
            <span class="order-state">{{ closed ? (order.status === 'COMPLETED' ? 'Đã thanh toán' : 'Đã hủy') : 'Đang phục vụ' }}</span>
        </header>
        <div class="order-workspace">
            <section class="catalog" aria-label="Chọn món">
                <input v-model="search" class="search" placeholder="Tìm tên món..." aria-label="Tìm món" />
                <nav class="categories" aria-label="Danh mục món">
                    <button :class="{ active: category === '' }" @click="category = ''">Tất cả</button>
                    <button v-for="c in categories" :key="c.slug" :class="{ active: category === c.slug }" @click="category = c.slug">{{ c.name }}</button>
                </nav>
                <p v-if="menuLoading" class="empty">Đang tải thực đơn...</p>
                <div v-else-if="menuError" class="empty">{{ menuError }} <button @click="loadMenu()">Thử lại</button></div>
                <div v-else class="menu-grid">
                    <button v-for="m in filteredMenu" :key="m.id" class="menu-card" :disabled="closed || busy" @click="selectMenu(m)">
                        <img :src="imageUrl(m.image_url)" :alt="m.name" loading="lazy" @error="imageFailed" />
                        <span class="menu-name">{{ m.name }}</span>
                        <strong>{{ formatVND(m.price) }}</strong>
                    </button>
                    <p v-if="!filteredMenu.length" class="empty">Không tìm thấy món phù hợp.</p>
                </div>
            </section>
            <aside class="ticket" aria-label="Phiếu order">
                <h3>Phiếu Order <span>{{ itemCount }} món</span></h3>
                <div class="ticket-items">
                    <p v-if="!draft.length && !order.items.length" class="empty">Chưa có món nào được chọn.<br />Chọn món trong thực đơn bên trái.</p>
                    <article v-for="(line, index) in draft" :key="line.key" class="ticket-line draft">
                        <div class="line-heading"><b>{{ line.menu.name }}</b><span class="chip">Chưa gửi</span></div>
                        <small v-if="isNoodle(line.menu)">Độ cay: {{ line.spicy_level === 0 ? 'Không cay' : 'Cấp ' + line.spicy_level }}</small>
                        <small v-if="line.note">{{ line.note }}</small>
                        <div class="line-bottom">
                            <div class="quantity">
                                <button :disabled="busy || line.quantity <= 1" aria-label="Giảm số lượng" @click="line.quantity--">−</button>
                                <span>{{ line.quantity }}</span>
                                <button :disabled="busy || line.quantity >= 99" aria-label="Tăng số lượng" @click="line.quantity++">+</button>
                            </div>
                            <strong>{{ formatVND(line.quantity * line.menu.price) }}</strong>
                            <button class="remove" :disabled="busy" @click="draft.splice(index, 1)">Xóa</button>
                        </div>
                    </article>
                    <article v-for="item in order.items" :key="item.id" class="ticket-line" :class="{ cancelled: item.status === 'CANCELLED' }">
                        <div class="line-heading"><b>{{ item.menu_item_name }}</b><span class="chip" :class="item.status">{{ itemLabels[item.status] }}</span></div>
                        <small v-if="isNoodleName(item.menu_item_name) && item.spicy_level !== null">Độ cay: {{ item.spicy_level === 0 ? 'Không cay' : 'Cấp ' + item.spicy_level }}</small>
                        <small v-for="t in item.toppings" :key="t.id">+ {{ t.name }}</small>
                        <small v-if="item.note">{{ item.note }}</small>
                        <div class="line-bottom">
                            <span>{{ item.quantity }} ×</span><strong>{{ formatVND(item.subtotal) }}</strong>
                            <button v-if="item.status === 'READY' && !closed" class="serve" :disabled="busy" @click="serve(item.id)">Đã bưng món</button>
                        </div>
                    </article>
                </div>
                <footer class="ticket-footer">
                    <div class="total"><b>Tổng tiền bàn</b><strong>{{ formatVND(total) }}</strong></div>
                    <button class="send" :disabled="busy || closed || !draft.length" @click="sendItems">{{ busy ? 'Đang xử lý...' : 'Gửi xuống bếp' }}</button>
                    <button class="pay" :disabled="busy || closed || !!draft.length || !activeItems.length" @click="pay">Thanh toán bàn này</button>
                    <small v-if="draft.length">Gửi các món đã chọn trước khi thanh toán.</small>
                    <p v-if="notice" class="notice" role="status">{{ notice }}</p>
                    <p v-if="actionError" class="action-error" role="alert">{{ actionError }}</p>
                </footer>
            </aside>
        </div>
        <Teleport to="body">
            <div v-if="selected" class="options-backdrop" @click.self="selected = null">
                <form class="options" @submit.prevent="addDraft">
                    <div class="options-heading"><h3>{{ selected.name }}</h3><button type="button" @click="selected = null">Đóng</button></div>
                    <img :src="imageUrl(selected.image_url)" :alt="selected.name" @error="imageFailed" />
                    <fieldset v-if="isNoodle(selected)">
                        <legend>Chọn độ cay</legend>
                        <div class="spicy-levels">
                            <label v-for="level in 8" :key="level" :class="{ active: spicy === level - 1 }">
                                <input v-model.number="spicy" type="radio" name="spicy" :value="level - 1" />
                                {{ level === 1 ? 'Không cay' : 'Cấp ' + (level - 1) }}
                            </label>
                        </div>
                    </fieldset>
                    <label class="field">Số lượng<input v-model.number="quantity" type="number" min="1" max="99" required /></label>
                    <label class="field">Ghi chú<textarea v-model="note" maxlength="200" rows="2" placeholder="Ít hành, không rau..." /></label>
                    <button class="send" type="submit">Thêm vào phiếu · {{ formatVND(selected.price * quantity) }}</button>
                </form>
            </div>
        </Teleport>
    </div>
    <div v-else class="empty">{{ loading ? 'Đang tải đơn hàng...' : 'Không tải được đơn hàng.' }} <RouterLink to="/staff/waiter/tables">Về sơ đồ bàn</RouterLink></div>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import { staffOrders, type Order } from '@/api/staff'
import type { OrderItemStatus } from '@/types/staff'
import { fetchMenuItems } from '@/api/menu'
import type { MenuItemCard } from '@/types/home'
import { formatVND, useToast } from '@/composables/useStaffUtils'
import { useTablesStore } from '@/stores/staffTables'
import { imageUrl } from '@/utils/image'

const route = useRoute()
const router = useRouter()
const tablesStore = useTablesStore()
const toast = useToast()
const order = ref<Order | null>(null)
const loading = ref(true)
const menuLoading = ref(false)
const menuError = ref('')
const menu = ref<MenuItemCard[]>([])
const search = ref('')
const category = ref('')
const busy = ref(false)
const notice = ref('')
const actionError = ref('')
const selected = ref<MenuItemCard | null>(null)
const spicy = ref(0)
const quantity = ref(1)
const note = ref('')
interface DraftLine { key: number; menu: MenuItemCard; quantity: number; spicy_level: number; note: string }
const draft = ref<DraftLine[]>([])
let key = 0
const itemLabels: Record<OrderItemStatus, string> = { PENDING: 'Chờ bếp', PREPARING: 'Đang nấu', READY: 'Đã nấu xong', SERVED: 'Đã bưng', CANCELLED: 'Đã hủy' }
const closed = computed(() => ['COMPLETED', 'CANCELLED'].includes(order.value?.status ?? ''))
const activeItems = computed(() => order.value?.items.filter(i => i.status !== 'CANCELLED') ?? [])
const itemCount = computed(() => activeItems.value.reduce((n, i) => n + i.quantity, 0) + draft.value.reduce((n, i) => n + i.quantity, 0))
const total = computed(() => (order.value?.total_amount ?? 0) + draft.value.reduce((n, i) => n + i.menu.price * i.quantity, 0))
const categories = computed(() => Array.from(new Map(menu.value.map(m => [m.category_slug, { slug: m.category_slug, name: m.category_name }])).values()))
const filteredMenu = computed(() => menu.value.filter(m => (!category.value || m.category_slug === category.value) && m.name.toLocaleLowerCase('vi').includes(search.value.trim().toLocaleLowerCase('vi'))))
function isNoodleName(name: string) { return /(^|\s)mì(\s|$)/iu.test(name) }
function isNoodle(m: MenuItemCard) { return isNoodleName(m.name) || m.category_slug.startsWith('mi-') }
function imageFailed(event: Event) { const img = event.target as HTMLImageElement; img.onerror = null; img.style.visibility = 'hidden' }
function selectMenu(m: MenuItemCard) {
    selected.value = m
    spicy.value = Math.max(0, Math.min(7, m.spicy_level ?? 0))
    quantity.value = 1
    note.value = ''
}
function addDraft() {
    if (!selected.value || !Number.isInteger(quantity.value) || quantity.value < 1 || quantity.value > 99) return
    draft.value.push({ key: ++key, menu: selected.value, quantity: quantity.value, spicy_level: isNoodle(selected.value) ? spicy.value : 0, note: note.value.trim() })
    selected.value = null
    notice.value = ''
}
async function loadMenu(quiet = false) {
    if (!quiet) menuLoading.value = true
    if (!quiet) menuError.value = ''
    try { menu.value = (await fetchMenuItems({ search: '', category: null, sort: 'default', size: null, min_price: null, max_price: null })).data }
    catch (error) { if (quiet) throw error; menuError.value = 'Không tải được thực đơn.' }
    finally { if (!quiet) menuLoading.value = false }
}
async function loadOrder() {
    loading.value = true
    order.value = null
    try { order.value = await staffOrders.get(Number(route.params.id)) }
    catch { actionError.value = 'Không tải được đơn hàng.' }
    finally { loading.value = false }
}
async function refreshOrder() {
    if (busy.value || !order.value || closed.value) return
    const id = order.value.id
    try {
        const fresh = await staffOrders.get(id)
        if (!busy.value && order.value?.id === id) order.value = fresh
    } catch { /* Keep current ticket while the next refresh retries. */ }
}
async function runAction(action: () => Promise<void>) {
    if (busy.value) return
    busy.value = true
    actionError.value = ''
    notice.value = ''
    try { await action() }
    catch (err: any) { actionError.value = err?.response?.data?.message || err?.message || 'Thao tác thất bại. Vui lòng thử lại.' }
    finally { busy.value = false }
}
async function sendItems() {
    if (!order.value || !draft.value.length || closed.value) return
    await runAction(async () => {
        order.value = await staffOrders.submitItems(order.value!.id, draft.value.map(l => ({ menu_item_id: l.menu.id, quantity: l.quantity, spicy_level: l.spicy_level, note: l.note })))
        draft.value = []
        notice.value = 'Đã lưu món vào hàng chờ bếp.'
    })
}
async function serve(itemId: number) {
    if (!order.value || closed.value) return
    await runAction(async () => {
        order.value = await staffOrders.updateItemStatus(order.value!.id, itemId, 'SERVED')
        notice.value = 'Đã xác nhận bưng món.'
    })
}
async function pay() {
    if (busy.value || !order.value || closed.value || draft.value.length || !activeItems.value.length) return
    let paid = false
    await runAction(async () => {
        order.value = await staffOrders.demoPay(order.value!.id)
        paid = true
        await tablesStore.fetchAll()
    })
    // Điều hướng sau khi runAction đã bỏ cờ busy để guard cho phép rời trang.
    if (paid) {
        toast.success('Thanh toán thành công. Bàn đã trống.')
        await router.replace({ name: 'waiter-tables' })
    }
}
function canLeave() { return !busy.value && (!draft.value.length || window.confirm('Các món chưa gửi sẽ mất. Bạn muốn rời trang?')) }
onBeforeRouteLeave(canLeave)
onBeforeRouteUpdate(canLeave)
function beforeUnload(e: BeforeUnloadEvent) { if (draft.value.length || busy.value) { e.preventDefault(); e.returnValue = '' } }
watch(() => route.params.id, () => { draft.value = []; selected.value = null; notice.value = ''; actionError.value = ''; void loadOrder() })
onMounted(async () => {
    window.addEventListener('beforeunload', beforeUnload)
    await Promise.all([loadMenu(), loadOrder()])

})
onUnmounted(() => { window.removeEventListener('beforeunload', beforeUnload) })
useRealtimeRefresh(refreshOrder, () => !busy.value && !loading.value)
useRealtimeRefresh(() => loadMenu(true), () => !busy.value && !menuLoading.value, 10000)
</script>

<style scoped>
.order-screen { display: flex; flex-direction: column; gap: 16px; }
.order-header, .heading, .line-heading, .line-bottom, .total, .options-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.heading { justify-content: flex-start; }
.order-header h2 { margin: 0; color: var(--color-brand-action); font-size: 25px; font-weight: 600; }
.back, .options-heading button { text-decoration: none; color: #64748b; border: 1px solid #d1d5db; padding: 7px 10px; border-radius: 6px; background: #fff; font-size: 12px; }
.order-state { background: var(--color-brand-soft); color: #1d4ed8; padding: 6px 10px; border-radius: 6px; font-size: 12px; }
.order-workspace { display: grid; grid-template-columns: minmax(0, 1fr) 350px; gap: 20px; align-items: start; }
.catalog { min-width: 0; }
.search { width: 100%; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px; box-sizing: border-box; }
.categories { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 14px; }
.categories button { white-space: nowrap; border: 1px solid #e5e7eb; background: #fff; padding: 8px 14px; border-radius: 20px; color: #64748b; cursor: pointer; }
.categories button.active { background: var(--color-brand-action); color: #fff; border-color: var(--color-brand-action); }
.menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 12px; }
.menu-card { display: flex; flex-direction: column; text-align: left; padding: 0 0 10px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; cursor: pointer; font: inherit; }
.menu-card:hover { border-color: var(--color-brand-action); }
.menu-card img { width: 100%; height: 115px; object-fit: cover; background: #e5e7eb; }
.menu-name { padding: 9px 9px 5px; font-size: 12px; line-height: 1.4; }
.menu-card strong { padding: 0 9px; color: #ef4444; font-size: 13px; margin-top: auto; }
.ticket { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; position: sticky; top: 90px; overflow: hidden; }
.ticket h3 { margin: 0; padding: 15px; background: #f8fafc; font-size: 15px; display: flex; justify-content: space-between; }
.ticket h3 span { font-size: 12px; font-weight: 400; color: #64748b; }
.ticket-items { min-height: 260px; max-height: 48vh; overflow-y: auto; }
.ticket-line { padding: 14px; border-bottom: 1px solid #f1f5f9; }
.line-heading { align-items: flex-start; font-size: 13px; }
.ticket-line small { display: block; font-size: 11px; color: #64748b; margin-top: 5px; }
.line-bottom { margin-top: 10px; font-size: 12px; flex-wrap: wrap; }
.line-bottom strong { margin-left: auto; }
.chip { white-space: nowrap; font-size: 10px; color: #64748b; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; }
.chip.READY { color: #166534; background: #dcfce7; }
.chip.PREPARING { color: #9a3412; background: #ffedd5; }
.chip.SERVED { color: #1d4ed8; background: #dbeafe; }
.cancelled { opacity: .55; }
.quantity { display: flex; align-items: center; gap: 10px; }
.quantity button { width: 26px; height: 26px; border: 1px solid #e5e7eb; background: #fff; border-radius: 4px; cursor: pointer; }
.remove { background: none; border: 0; color: #dc2626; cursor: pointer; font-size: 11px; }
.serve { background: #dcfce7; border: 0; border-radius: 5px; padding: 6px; color: #166534; cursor: pointer; }
.ticket-footer { padding: 15px; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; }
.total { font-size: 14px; }
.total strong { color: #ef4444; }
.send, .pay { width: 100%; border: 0; border-radius: 6px; color: #fff; padding: 12px; font-weight: 600; cursor: pointer; }
.send { background: var(--color-brand-action); }
.pay { background: #16a34a; }
button:disabled { opacity: .5; cursor: not-allowed; }
button:focus-visible, a:focus-visible { outline: 2px solid var(--color-brand-action); outline-offset: 2px; }
.ticket-footer small { color: #64748b; font-size: 11px; }
.empty { padding: 40px 18px; text-align: center; color: #94a3b8; font-size: 13px; line-height: 1.8; }
.notice, .action-error { margin: 0; font-size: 12px; line-height: 1.5; }
.notice { color: #166534; }
.action-error { color: #b91c1c; }
.options-backdrop { position: fixed; inset: 0; background: #0007; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.options { width: 440px; max-width: 100%; max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 12px; padding: 20px; box-sizing: border-box; }
.options-heading h3 { font-size: 17px; margin: 0; }
.options > img { width: 100%; height: 180px; object-fit: cover; border-radius: 8px; margin: 16px 0; }
.options fieldset { border: 0; padding: 0; margin: 0 0 16px; }
.options legend { margin-bottom: 10px; font-weight: 600; font-size: 13px; }
.spicy-levels { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.spicy-levels label { padding: 8px 4px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 11px; cursor: pointer; text-align: center; }
.spicy-levels label.active { background: var(--color-brand-soft); border-color: var(--color-brand-action); }
.spicy-levels input { margin: 0 3px 0 0; accent-color: var(--color-brand-action); }
.field { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; font-size: 13px; }
.field input, .field textarea { padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font: inherit; }
@media (max-width: 1100px) { .order-workspace { grid-template-columns: minmax(0, 1fr) 310px; gap: 12px; } }
@media (max-width: 760px) { .order-workspace { grid-template-columns: 1fr; } .ticket { position: static; } .menu-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .order-header h2 { font-size: 20px; } }
</style>
