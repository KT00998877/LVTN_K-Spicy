<template>
    <section v-if="items.length || error" class="service-alerts" aria-label="Món chờ bưng">
        <details :open="expanded" @toggle="expanded = ($event.target as HTMLDetailsElement).open">
            <summary>Món đã xong · {{ items.length }} món chờ bưng</summary>
            <p v-if="error" role="alert">{{ error }}</p>
            <div class="alerts-list">
                <RouterLink v-for="item in items" :key="item.id" :to="'/staff/waiter/orders/' + item.order_id" :class="{ overdue: isOverdue(item) }">
                    <strong>{{ item.table_code ? 'Bàn ' + item.table_code : item.order_type === 'TAKEAWAY' ? 'Mang đi' : 'Đặt trực tuyến' }} · {{ item.quantity }} × {{ item.name }}</strong>
                    <span>{{ item.order_code }} · {{ waitMinutes(item) }} phút chờ{{ item.reminded_at ? ' · Bếp đã nhắc' : '' }} · Xem đơn / Bưng món</span>
                </RouterLink>
            </div>
        </details>
    </section>
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useStaffUtils'
interface ReadyItem { id: number; order_id: number; order_code: string; order_type: string; table_code: string | null; name: string; quantity: number; ready_at: string | null; reminded_at: string | null }
const items = ref<ReadyItem[]>([])
const expanded = ref(true)
const error = ref('')
const clock = ref(Date.now())
const toast = useToast()
let stopped = false
let inFlight = false
const seen = new Map<number, string>()
function waitMinutes(item: ReadyItem) { return Math.max(0, Math.floor((clock.value - Date.parse(item.ready_at ?? '')) / 60000)) || 0 }
function isOverdue(item: ReadyItem) { return waitMinutes(item) >= 5 }
async function poll() {
    if (stopped || inFlight) return
    inFlight = true
    try {
        const response = await apiClient.get<{ data: ReadyItem[] }>('/staff/service-notifications')
        if (stopped) return
        clock.value = Date.now()
        items.value = response.data.data
        error.value = ''
        const changed = items.value.filter(item => seen.get(item.id) !== (item.reminded_at ?? item.ready_at ?? 'ready'))
        if (changed.length) {
            expanded.value = true
            const first = changed[0]!
            const label = first.table_code ? 'Bàn ' + first.table_code : first.order_code
            toast.warning((first.reminded_at ? 'Bếp nhắc bưng món: ' : 'Món đã xong: ') + label + ' · ' + first.name + (changed.length > 1 ? ' và ' + (changed.length - 1) + ' món khác' : ''), 7000)
        }
        for (const item of items.value) seen.set(item.id, item.reminded_at ?? item.ready_at ?? 'ready')
        for (const id of seen.keys()) if (!items.value.some(item => item.id === id)) seen.delete(id)
    } catch {
        if (!stopped) error.value = 'Chưa cập nhật được thông báo bếp. Đang kết nối lại…'
    } finally {
        inFlight = false
    }
}
onMounted(() => { void poll() })
onUnmounted(() => { stopped = true })
useRealtimeRefresh(poll)
</script>

<style scoped>
.service-alerts { margin: 16px 24px 0; padding: 14px 16px; background: #ecfdf5; border: 1px solid #86efac; border-radius: 10px; }
summary { cursor: pointer; color: #166534; font-weight: 600; }
.alerts-list { display: grid; gap: 8px; max-height: 220px; overflow-y: auto; margin-top: 10px; }
.alerts-list a { display: flex; flex-direction: column; gap: 5px; background: white; border: 1px solid #d1fae5; padding: 10px; border-radius: 6px; color: #166534; text-decoration: none; font-size: 13px; }
.alerts-list span { color: #64748b; font-size: 12px; }
.alerts-list a.overdue { border-color: #fb923c; background: #fff7ed; color: #9a3412; }
</style>
