<script setup lang="ts">
/**
 * CustomerPromoBell — ô chuông ưu đãi cho khách hàng (Phase 1).
 *
 * Khác `staff/StaffNotificationBell.vue` ở chỗ: nội dung là ưu đãi kèm
 * mã voucher, và dùng màu thương hiệu mì cay (spicy red) thay vì palette staff.
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useCustomerNotificationsStore, type PromoNotification } from '@/stores/customerNotifications'

const store = useCustomerNotificationsStore()
const router = useRouter()
const panel = ref<HTMLDialogElement>()
const open = ref(false)
const copyState = ref<Record<string, 'idle' | 'copied' | 'failed'>>({})
const toast = ref<PromoNotification | null>(null)

const connectionLabel = computed(() =>
    store.connected ? 'Cập nhật trực tiếp' : 'Tự động cập nhật mỗi vài giây',
)

let timer: ReturnType<typeof setTimeout> | undefined
watch(() => store.latest, value => {
    if (!value) return
    toast.value = value
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => { toast.value = null }, 8000)
})
onUnmounted(() => { if (timer) clearTimeout(timer) })

function togglePanel() {
    open.value = !open.value
}

async function copyCode(code: string, id: string) {
    try {
        await navigator.clipboard.writeText(code)
        copyState.value = { ...copyState.value, [id]: 'copied' }
    } catch {
        copyState.value = { ...copyState.value, [id]: 'failed' }
    }
    setTimeout(() => {
        copyState.value = { ...copyState.value, [id]: 'idle' }
    }, 2200)
}

/** Bấm ưu đãi → đánh dấu đã đọc + đi tới trang dùng mã (checkout kèm mã). */
async function usePromo(item: PromoNotification) {
    await store.markRead(item)
    toast.value = null
    await router.push({ path: '/checkout', query: { voucher: item.data.voucher_code } })
}
</script>

<template>
    <div class="promo-bell">
        <button
            class="bell-button"
            type="button"
            :aria-expanded="open"
            aria-haspopup="true"
            :aria-label="`Ưu đãi, ${store.unreadCount} chưa đọc`"
            @click="togglePanel"
        >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            </svg>
            <span v-if="store.unreadCount" class="count">{{ store.unreadCount > 99 ? '99+' : store.unreadCount }}</span>
        </button>

        <div v-if="open" class="promo-panel" role="dialog" aria-label="Ưu đãi của bạn">
            <header>
                <strong>Ưu đãi dành cho bạn</strong>
                <button class="close-button" type="button" aria-label="Đóng" @click="open = false">✕</button>
            </header>

            <p class="connection" role="status">{{ connectionLabel }}</p>
            <p v-if="store.error" class="error" role="alert">{{ store.error }}</p>

            <p v-if="store.loading && !store.items.length" role="status">Đang tải ưu đãi…</p>
            <p v-else-if="!store.items.length" class="empty">
                Chưa có ưu đãi nào. Hãy quay lại vào khung giờ vàng nhé!
            </p>

            <ul v-else>
                <li v-for="item in store.items" :key="item.id" :class="{ unread: !item.read_at }">
                    <p class="promo-message">{{ item.data.message }}</p>
                    <div class="promo-code-row">
                        <code class="promo-code">{{ item.data.voucher_code }}</code>
                        <button
                            class="copy-button"
                            type="button"
                            :aria-label="`Sao chép mã ${item.data.voucher_code}`"
                            @click="copyCode(item.data.voucher_code, item.id)"
                        >
                            {{ copyState[item.id] === 'copied' ? 'Đã sao chép' : copyState[item.id] === 'failed' ? 'Thử lại' : 'Sao chép' }}
                        </button>
                    </div>
                    <button class="use-button" type="button" @click="usePromo(item)">
                        {{ item.data.cta_text || 'Nhận ưu đãi' }}
                    </button>
                    <time :datetime="item.created_at">{{ new Date(item.created_at).toLocaleString('vi-VN') }}</time>
                </li>
            </ul>

            <button
                v-if="store.unreadCount"
                class="read-all"
                type="button"
                @click="store.markAllRead()"
            >
                Đánh dấu đã đọc tất cả
            </button>
        </div>

        <aside v-if="toast" class="promo-toast" aria-live="polite">
            <button class="toast-body" type="button" @click="usePromo(toast)">
                <strong>{{ toast.data.name }}</strong>
                <span>{{ toast.data.message }}</span>
                <code>{{ toast.data.voucher_code }}</code>
            </button>
            <button class="close-button" type="button" aria-label="Ẩn thông báo" @click="toast = null">✕</button>
        </aside>
    </div>
</template>

<style scoped>
.promo-bell { position: relative; }

.bell-button {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    min-height: 44px;
    border: 0;
    border-radius: 12px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}
.bell-button:hover { background: rgba(232, 58, 20, 0.08); }

.count {
    position: absolute;
    top: 2px;
    right: 0;
    min-width: 20px;
    padding: 1px 5px;
    border-radius: 10px;
    background: var(--chili-red, #e4263d);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
    text-align: center;
}

.promo-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 60;
    width: min(360px, calc(100vw - 32px));
    max-height: min(480px, calc(100dvh - 120px));
    overflow-y: auto;
    box-sizing: border-box;
    padding: 16px;
    border-radius: 16px;
    background: var(--color-surface, #fff);
    box-shadow: 0 12px 40px rgba(21, 21, 21, 0.18);
}

header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
header strong { font-size: 16px; }

.close-button {
    min-width: 36px;
    min-height: 36px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font-size: 15px;
    cursor: pointer;
}
.close-button:hover { background: rgba(0, 0, 0, 0.05); }

.connection { margin: 6px 0 0; color: #6b6b6b; font-size: 12px; }
.error { margin: 8px 0 0; color: #991b1b; font-size: 13px; }
.empty { margin: 20px 0; color: #6b6b6b; font-size: 14px; text-align: center; }

ul { list-style: none; margin: 12px 0 0; padding: 0; }

li {
    padding: 12px;
    border-radius: 12px;
    background: var(--cream, #f8f6f3);
}
li + li { margin-top: 8px; }
li.unread { background: #fff0e0; }

.promo-message { margin: 0 0 10px; font-size: 14px; line-height: 1.45; }

.promo-code-row { display: flex; align-items: stretch; gap: 8px; }

.promo-code {
    flex: 1;
    min-width: 0;
    padding: 8px 10px;
    border: 1.5px dashed var(--chili-red, #e4263d);
    border-radius: 8px;
    background: #fff;
    color: var(--chili-red, #e4263d);
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-align: center;
    overflow-wrap: anywhere;
}

.copy-button {
    flex: 0 0 auto;
    min-height: 40px;
    padding: 8px 12px;
    border: 0;
    border-radius: 8px;
    background: var(--chili-red, #e4263d);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.copy-button:hover { filter: brightness(0.94); }

.use-button {
    width: 100%;
    min-height: 40px;
    margin-top: 10px;
    border: 1.5px solid var(--chili-red, #e4263d);
    border-radius: 8px;
    background: transparent;
    color: var(--chili-red, #e4263d);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.use-button:hover { background: rgba(232, 58, 20, 0.07); }

time { display: block; margin-top: 8px; color: #7a7a7a; font-size: 12px; }

.read-all {
    width: 100%;
    min-height: 40px;
    margin-top: 12px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #595959;
    font-size: 13px;
    cursor: pointer;
}
.read-all:hover { background: rgba(0, 0, 0, 0.04); }

.promo-toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 1100;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    width: min(380px, calc(100vw - 32px));
    box-sizing: border-box;
    padding: 14px;
    border-left: 4px solid var(--chili-red, #e4263d);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 8px 32px rgba(21, 21, 21, 0.2);
}
.toast-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 4px;
    text-align: left;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.toast-body strong { font-size: 14px; }
.toast-body span { font-size: 13px; }
.toast-body code {
    align-self: flex-start;
    margin-top: 4px;
    padding: 4px 8px;
    border: 1.5px dashed var(--chili-red, #e4263d);
    border-radius: 6px;
    color: var(--chili-red, #e4263d);
    font-weight: 700;
    letter-spacing: 0.06em;
}

button:focus-visible { outline: 3px solid var(--chili-red, #e4263d); outline-offset: 2px; }
</style>
