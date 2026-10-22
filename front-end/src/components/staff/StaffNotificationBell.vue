<script setup lang="ts">
import { computed, onUnmounted, ref, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useStaffNotificationsStore, type StaffNotification } from '@/stores/staffNotifications'

const store = useStaffNotificationsStore()
const router = useRouter()
const panel = ref<HTMLDialogElement>()
const opening = ref(false)
const actionError = ref('')
const toast = ref<StaffNotification | null>(null)
const connectionLabel = computed(() => store.connected ? 'Đã kết nối real-time' : 'Đang kết nối lại · tự động cập nhật')
let timer: ReturnType<typeof setTimeout> | undefined
watch(() => store.latest, value => {
    toast.value = value
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => { toast.value = null }, 8000)
})
onUnmounted(() => { if (timer) clearTimeout(timer) })

async function openOrder(item: StaffNotification) {
    if (opening.value) return
    opening.value = true
    actionError.value = ''
    try {
        await store.markRead(item)
        panel.value?.close()
        await router.push(item.data.url)
        await nextTick()
        window.dispatchEvent(new Event('staff:focus-order'))
        toast.value = null
    } catch {
        actionError.value = 'Chưa mở được đơn hàng. Vui lòng thử lại.'
        if (!panel.value?.open) panel.value?.showModal()
    } finally { opening.value = false }
}
</script>

<template>
    <div class="notification-bell">
        <button class="bell-button" aria-haspopup="dialog" :aria-label="`Thông báo, ${store.unreadCount} chưa đọc`" @click="panel?.showModal(); store.prepareSound()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
            <span v-if="store.unreadCount" class="count">{{ store.unreadCount > 99 ? '99+' : store.unreadCount }}</span>
        </button>
        <dialog ref="panel" class="notification-panel" aria-labelledby="notifications-title" @click="($event.target === panel) && panel?.close()">
            <header><h2 id="notifications-title">Thông báo</h2><button class="plain-button" aria-label="Đóng thông báo" @click="panel?.close()">✕</button></header>
            <p class="connection" role="status">{{ connectionLabel }}</p>
            <button class="sound-button" :aria-pressed="store.soundEnabled" @click="store.toggleSound()">Âm báo: {{ store.soundEnabled ? 'Bật' : 'Tắt' }}</button>
            <p v-if="store.soundEnabled" class="hint">Âm báo được cho phép sau khi bạn tương tác với trang.</p>
            <p v-if="store.error || actionError" class="error" role="alert">{{ actionError || store.error }}</p>
            <p v-if="store.loading && !store.items.length" role="status">Đang tải thông báo…</p>
            <p v-else-if="!store.items.length" class="empty">Chưa có thông báo đơn hàng.</p>
            <ul v-else>
                <li v-for="item in store.items" :key="item.id">
                    <button class="notification" :class="{ unread: !item.read_at }" :disabled="opening" @click="openOrder(item)">
                        <span v-if="!item.read_at" class="unread-label">Chưa đọc</span>
                        <strong>{{ item.data.order_code }}</strong>
                        <span>{{ item.data.message }}</span>
                        <time :datetime="item.created_at">{{ new Date(item.created_at).toLocaleString('vi-VN') }}</time>
                    </button>
                </li>
            </ul>
            <button v-if="store.page < store.lastPage" class="sound-button" :disabled="store.loading" @click="store.fetchPage(store.page + 1)">{{ store.loading ? 'Đang tải…' : 'Xem thêm' }}</button>
        </dialog>
        <aside v-if="toast" class="notification-toast" aria-live="polite">
            <button class="toast-order" :disabled="opening" @click="openOrder(toast)"><strong>{{ toast.data.order_code }}</strong><span>{{ toast.data.message }}</span></button>
            <button class="plain-button" aria-label="Ẩn thông báo" @click="toast = null">✕</button>
        </aside>
    </div>
</template>

<style scoped>
.bell-button,.plain-button,.sound-button { min-height:44px; border:0; border-radius:8px; cursor:pointer; background:var(--cream,#f8f6f3); color:var(--charcoal,#151515); padding:10px 12px; }
.bell-button { position:relative; display:flex; align-items:center; justify-content:center; min-width:44px; }
.count { position:absolute; top:-4px; right:-5px; border-radius:12px; padding:2px 5px; background:var(--chili-red,#e4263d); color:white; font-size:12px; font-weight:700; }
.notification-panel { position:fixed; inset:16px 16px auto auto; margin:0; box-sizing:border-box; width:min(420px,calc(100vw - 32px)); max-height:calc(100dvh - 32px); overflow:auto; padding:20px; border:0; border-radius:12px; background:var(--color-surface,#fff); color:var(--charcoal,#151515); box-shadow:0 12px 48px #15151526; }
.notification-panel::backdrop { background:#15151540; }
header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
h2 { margin:0; font-size:20px; }
.connection,.hint,time { color:#595959; font-size:14px; }
.error { color:#991b1b; }
ul { list-style:none; padding:0; margin:16px 0 0; }
li+li { margin-top:8px; }
.notification { display:flex; flex-direction:column; gap:6px; text-align:left; width:100%; padding:14px; border:0; border-radius:8px; background:var(--cream,#f8f6f3); color:inherit; font:inherit; cursor:pointer; overflow-wrap:anywhere; }
.notification.unread { background:#fff0e0; }
.unread-label { font-size:13px; color:#92400e; font-weight:700; }
.notification-toast { position:fixed; right:16px; bottom:16px; z-index:1100; display:flex; align-items:start; gap:8px; width:min(420px,calc(100vw - 32px)); box-sizing:border-box; padding:12px; border-radius:12px; background:#fff0e0; color:#151515; box-shadow:0 8px 32px #15151526; }
.toast-order { display:flex; flex-direction:column; gap:6px; flex:1; text-align:left; background:none; color:inherit; border:0; font:inherit; cursor:pointer; min-height:44px; }
button:hover:not(:disabled) { filter:brightness(.96); }
button:focus-visible { outline:3px solid var(--chili-red,#e4263d); outline-offset:2px; }
button:disabled { opacity:.6; cursor:wait; }
</style>
