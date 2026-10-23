<script setup lang="ts">
/**
 * ManagerLiveChatView — màn hình quản lý tư vấn trực tiếp.
 *
 * Hai cột: danh sách phiên bên trái, khung chat bên phải.
 *
 * Realtime: nhân viên nghe đồng thời mọi phiên của mình, nên tin nhắn của
 * khách khác hiện ngay ở thẻ phiên tương ứng (chấm đỏ chưa đọc) mà không cần
 * chuyển tab. Tin nhắn của phiên đang mở hiện thẳng trong khung chat.
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useLiveChatManagerStore } from '@/stores/liveChatManager'
import type { LiveChatSession } from '@/api/liveChat'

const store = useLiveChatManagerStore()
const {
    waiting,
    mine,
    activeId,
    activeSession,
    canReply,
    messages,
    suggestions,
    suggestionSource,
    unreadSessionIds,
    loadingQueue,
    loadingSession,
    loadingSuggestions,
    sending,
    accepting,
    error,
    connected,
} = storeToRefs(store)

const draft = ref('')
const messagesEl = ref<HTMLElement | null>(null)

/** Phiên đang hiển thị trong khung chat (có thể thuộc hàng chờ hoặc của tôi). */
const currentSession = computed<LiveChatSession | null>(() => activeSession.value)

/** Danh sách gộp: phiên của tôi trước, hàng chờ sau. */
const allSessions = computed<LiveChatSession[]>(() => [...mine.value, ...waiting.value])

function isUnread(id: number): boolean {
    return unreadSessionIds.value.includes(id)
}

function formatTime(iso: string | null | undefined): string {
    if (!iso) return ''
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return ''
    return date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
}

async function handleOpen(session: LiveChatSession): Promise<void> {
    await store.openSession(session.id)
    await scrollToBottom()
}

async function handleAccept(session: LiveChatSession): Promise<void> {
    await store.acceptSession(session.id)
    await scrollToBottom()
}

async function handleSend(): Promise<void> {
    const text = draft.value
    if (!text.trim() || sending.value) return
    const sent = await store.sendMessage(text)
    if (sent) draft.value = ''
    await scrollToBottom()
}

async function handleSuggestion(text: string): Promise<void> {
    draft.value = text
    await handleSend()
}

async function scrollToBottom(): Promise<void> {
    await nextTick()
    if (messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight
    }
}

watch(() => messages.value.length, () => {
    void scrollToBottom()
})

onMounted(async () => {
    store.start()
    await store.fetchQueue()
})

onUnmounted(() => {
    store.stop()
})
</script>

<template>
    <div class="livechat-page">
        <!-- Cột danh sách phiên -->
        <aside class="livechat-list" aria-label="Danh sách phiên tư vấn">
            <header class="livechat-list__header">
                <h2 class="livechat-list__title">Tư vấn trực tiếp</h2>
                <p class="livechat-list__subtitle">
                    <span class="livechat-list__dot" :class="{ 'is-online': connected }" aria-hidden="true"></span>
                    {{ connected ? 'Đang kết nối' : 'Mất kết nối' }} ·
                    {{ waiting.length }} đang chờ
                </p>
            </header>

            <div v-if="loadingQueue" class="livechat-list__empty">Đang tải hàng chờ...</div>

            <div v-else-if="allSessions.length === 0" class="livechat-list__empty">
                Chưa có phiên tư vấn nào.
            </div>

            <ul v-else class="livechat-list__items">
                <li
                    v-for="session in allSessions"
                    :key="session.id"
                    class="livechat-list__item"
                    :class="{ 'is-active': session.id === activeId }"
                >
                    <button
                        class="livechat-card"
                        type="button"
                        @click="handleOpen(session)"
                    >
                        <span class="livechat-card__row">
                            <span class="livechat-card__name">{{ session.customer_name }}</span>
                            <span v-if="isUnread(session.id)" class="livechat-card__unread" aria-label="Có tin nhắn mới"></span>
                            <time class="livechat-card__time">{{ formatTime(session.last_message_at) }}</time>
                        </span>
                        <span class="livechat-card__meta">
                            <span class="livechat-card__status" :class="`is-${session.status}`">
                                {{ session.status === 'waiting' ? 'Chờ nhận' : session.status === 'accepted' ? 'Đang tư vấn' : 'Đã đóng' }}
                            </span>
                            <span v-if="session.assigned_staff_name" class="livechat-card__staff">
                                {{ session.assigned_staff_name }}
                            </span>
                        </span>
                    </button>

                    <button
                        v-if="session.status === 'waiting' && !session.assigned_staff_id"
                        class="livechat-card__accept"
                        type="button"
                        :disabled="accepting === session.id"
                        @click="handleAccept(session)"
                    >
                        {{ accepting === session.id ? 'Đang nhận...' : 'Nhận tư vấn' }}
                    </button>
                </li>
            </ul>
        </aside>

        <!-- Cột khung chat -->
        <section class="livechat-thread" aria-label="Cuộc trò chuyện">
            <template v-if="currentSession">
                <header class="livechat-thread__header">
                    <div>
                        <h3 class="livechat-thread__title">{{ currentSession.customer_name }}</h3>
                        <p class="livechat-thread__subtitle">
                            {{ currentSession.status === 'waiting' ? 'Đang chờ nhân viên nhận' : 'Đang tư vấn' }}
                            <span v-if="currentSession.customer_phone"> · {{ currentSession.customer_phone }}</span>
                        </p>
                    </div>
                    <button
                        v-if="currentSession.status !== 'closed'"
                        class="livechat-thread__close"
                        type="button"
                        @click="store.closeSession(currentSession.id)"
                    >
                        Kết thúc
                    </button>
                </header>

                <div v-if="error" class="livechat-thread__error" role="alert">{{ error }}</div>

                <div ref="messagesEl" class="livechat-thread__messages" aria-live="polite">
                    <div v-if="loadingSession" class="livechat-thread__empty">Đang tải cuộc trò chuyện...</div>

                    <div v-else-if="messages.length === 0" class="livechat-thread__empty">
                        Chưa có tin nhắn nào. Khách chưa nói gì.
                    </div>

                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="livechat-thread__msg"
                        :class="`is-${msg.sender_type}`"
                    >
                        <span class="livechat-thread__sender">
                            {{ msg.sender_type === 'customer' ? 'Khách' : msg.sender_type === 'staff' ? 'Nhân viên' : 'Hệ thống' }}
                        </span>
                        <div class="livechat-thread__bubble">{{ msg.content }}</div>
                    </div>
                </div>

                <!-- Gợi ý nhanh -->
                <div class="livechat-thread__suggestions">
                    <button
                        class="livechat-thread__suggest-toggle"
                        type="button"
                        :disabled="loadingSuggestions"
                        @click="store.loadSuggestions()"
                    >
                        {{ loadingSuggestions ? 'Đang tải gợi ý...' : '✨ Gợi ý trả lời nhanh' }}
                    </button>

                    <p v-if="suggestionSource" class="livechat-thread__suggest-source">
                        {{ suggestionSource === 'ai' ? 'AI gợi ý — nhân viên kiểm tra trước khi gửi' : 'Mẫu câu trả lời có sẵn' }}
                    </p>

                    <div v-if="suggestions.length" class="livechat-thread__suggest-list">
                        <button
                            v-for="(suggestion, index) in suggestions"
                            :key="index"
                            class="livechat-thread__suggest"
                            type="button"
                            :disabled="!canReply"
                            @click="handleSuggestion(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </div>
                </div>

                <form class="livechat-thread__input" @submit.prevent="handleSend">
                    <input
                        v-model="draft"
                        type="text"
                        placeholder="Trả lời khách hàng..."
                        :disabled="!canReply"
                        maxlength="2000"
                        aria-label="Nội dung trả lời"
                    />
                    <button type="submit" :disabled="!draft.trim() || !canReply" aria-label="Gửi tin nhắn">
                        Gửi
                    </button>
                </form>
            </template>

            <div v-else class="livechat-thread__placeholder">
                <p>Chọn một phiên để bắt đầu trả lời khách hàng.</p>
            </div>
        </section>
    </div>
</template>

<style scoped>
.livechat-page {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    height: calc(100vh - 140px);
    min-height: 520px;
}

/* ===== Danh sách ===== */
.livechat-list {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #ece2d8;
    border-radius: 16px;
    overflow: hidden;
}

.livechat-list__header {
    padding: 16px;
    border-bottom: 1px solid #ece2d8;
}

.livechat-list__title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #2b2320;
}

.livechat-list__subtitle {
    margin: 4px 0 0;
    font-size: 12.5px;
    color: #6b5f59;
    display: flex;
    align-items: center;
    gap: 6px;
}

.livechat-list__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #9ca3af;
}

.livechat-list__dot.is-online {
    background: #22c55e;
}

.livechat-list__empty {
    padding: 28px 16px;
    text-align: center;
    color: #8a7d76;
    font-size: 13.5px;
}

.livechat-list__items {
    list-style: none;
    margin: 0;
    padding: 8px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.livechat-list__item {
    border-radius: 12px;
    transition: background 0.15s ease;
}

.livechat-list__item.is-active {
    background: #fdf3ef;
}

.livechat-card {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 100%;
    padding: 11px 12px;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    border-radius: 12px;
    font-family: inherit;
}

.livechat-card:hover {
    background: rgba(211, 47, 47, 0.05);
}

.livechat-card__row {
    display: flex;
    align-items: center;
    gap: 7px;
}

.livechat-card__name {
    font-weight: 600;
    font-size: 14px;
    color: #2b2320;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.livechat-card__unread {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #d32f2f;
    flex-shrink: 0;
}

.livechat-card__time {
    font-size: 11.5px;
    color: #9a8d86;
    flex-shrink: 0;
}

.livechat-card__meta {
    display: flex;
    align-items: center;
    gap: 8px;
}

.livechat-card__status {
    font-size: 11.5px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
}

.livechat-card__status.is-waiting {
    background: #fef3c7;
    color: #92400e;
}

.livechat-card__status.is-accepted {
    background: #dcfce7;
    color: #166534;
}

.livechat-card__status.is-closed {
    background: #f1f0ef;
    color: #6b5f59;
}

.livechat-card__staff {
    font-size: 11.5px;
    color: #9a8d86;
}

.livechat-card__accept {
    margin: 0 12px 10px;
    padding: 7px 12px;
    border: 1px solid #d32f2f;
    border-radius: 10px;
    background: #fff;
    color: #d32f2f;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s ease;
}

.livechat-card__accept:hover:not(:disabled) {
    background: #d32f2f;
    color: #fff;
}

.livechat-card__accept:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

/* ===== Khung chat ===== */
.livechat-thread {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #ece2d8;
    border-radius: 16px;
    overflow: hidden;
}

.livechat-thread__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 18px;
    border-bottom: 1px solid #ece2d8;
    background: #fffaf2;
}

.livechat-thread__title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #2b2320;
}

.livechat-thread__subtitle {
    margin: 2px 0 0;
    font-size: 12.5px;
    color: #6b5f59;
}

.livechat-thread__close {
    padding: 8px 14px;
    border: 1px solid #e0d5c9;
    border-radius: 10px;
    background: #fff;
    color: #6b5f59;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
}

.livechat-thread__close:hover {
    border-color: #d32f2f;
    color: #d32f2f;
}

.livechat-thread__error {
    padding: 9px 18px;
    background: #fdecea;
    color: #b3261e;
    font-size: 13px;
}

.livechat-thread__messages {
    flex: 1;
    overflow-y: auto;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #fffaf2;
}

.livechat-thread__empty,
.livechat-thread__placeholder {
    margin: auto;
    text-align: center;
    color: #8a7d76;
    font-size: 14px;
    padding: 0 20px;
}

.livechat-thread__placeholder {
    margin: auto;
}

.livechat-thread__msg {
    display: flex;
    flex-direction: column;
    gap: 3px;
    max-width: 72%;
}

.livechat-thread__msg.is-staff {
    align-self: flex-end;
    align-items: flex-end;
}

.livechat-thread__sender {
    font-size: 11px;
    color: #9a8d86;
    padding: 0 4px;
}

.livechat-thread__bubble {
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

.is-customer .livechat-thread__bubble {
    background: #fff;
    border: 1px solid #ece2d8;
    color: #2b2320;
    border-bottom-left-radius: 4px;
}

.is-staff .livechat-thread__bubble {
    background: #d32f2f;
    color: #fff;
    border-bottom-right-radius: 4px;
}

.is-system .livechat-thread__bubble {
    background: #f2ece4;
    color: #6b5f59;
    font-size: 12.5px;
}

/* ===== Gợi ý ===== */
.livechat-thread__suggestions {
    padding: 11px 18px;
    border-top: 1px solid #ece2d8;
    background: #fff;
}

.livechat-thread__suggest-toggle {
    padding: 7px 13px;
    border: 1px solid #e0d5c9;
    border-radius: 10px;
    background: #fffaf2;
    color: #2b2320;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}

.livechat-thread__suggest-toggle:hover:not(:disabled) {
    border-color: #d32f2f;
    color: #d32f2f;
}

.livechat-thread__suggest-toggle:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.livechat-thread__suggest-source {
    margin: 8px 0 0;
    font-size: 11.5px;
    color: #9a8d86;
}

.livechat-thread__suggest-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 9px;
}

.livechat-thread__suggest {
    padding: 7px 12px;
    border: 1px solid #ece2d8;
    border-radius: 999px;
    background: #fffaf2;
    color: #4a3f3a;
    font-size: 12.5px;
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    max-width: 100%;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.livechat-thread__suggest:hover:not(:disabled) {
    border-color: #d32f2f;
    background: #fdf3ef;
}

.livechat-thread__suggest:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== Input ===== */
.livechat-thread__input {
    display: flex;
    gap: 9px;
    padding: 13px 18px;
    border-top: 1px solid #ece2d8;
    background: #fff;
}

.livechat-thread__input input {
    flex: 1;
    padding: 11px 14px;
    border: 1px solid #e0d5c9;
    border-radius: 12px;
    font-size: 14px;
    font-family: inherit;
    background: #fffaf2;
    color: #2b2320;
}

.livechat-thread__input input:focus {
    outline: 2px solid #d32f2f;
    outline-offset: 1px;
}

.livechat-thread__input button {
    padding: 11px 20px;
    border: none;
    border-radius: 12px;
    background: #d32f2f;
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    font-family: inherit;
}

.livechat-thread__input button:hover:not(:disabled) {
    background: #b71c1c;
}

.livechat-thread__input button:disabled,
.livechat-thread__input input:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

@media (max-width: 900px) {
    .livechat-page {
        grid-template-columns: 1fr;
        height: auto;
    }
    .livechat-list {
        max-height: 260px;
    }
    .livechat-thread {
        min-height: 460px;
    }
}
</style>
