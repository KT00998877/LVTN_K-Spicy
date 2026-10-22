<script setup lang="ts">
/**
 * ChatBotWidget - Phase C3
 * 
 * Chat popup widget gắn ở góc phải màn hình.
 * Features:
 * - Session management
 * - Message history
 * - Loading states
 * - Confirmation cards
 * - Suggested questions
 */
import { ref, nextTick, onMounted, computed, watch } from 'vue'
import { useChatbotStore } from '@/stores/chatbot'
import { storeToRefs } from 'pinia'
import ConfirmationCard from './ConfirmationCard.vue'
import ChatMessageText from './ChatMessageText.vue'
import { RouterLink } from 'vue-router'
import { imageUrl } from '@/utils/image'

const store = useChatbotStore()
const { 
    isOpen, 
    messages, 
    sending, 
    loading, 
    error, 
    sessionStatus,
    pendingActionsList,
    hasPendingActions,
} = storeToRefs(store)

const draft = ref('')
const messagesEl = ref<HTMLElement | null>(null)
const confirmLoading = ref<Map<number, boolean>>(new Map())
const animatedMessageIds = ref(new Set<number>())
const completedMessageIds = ref(new Set<number>())

watch(() => messages.value.length, () => {
    const last = messages.value.at(-1)
    if (sending.value && last?.role === 'assistant') {
        animatedMessageIds.value = new Set([...animatedMessageIds.value, last.id])
    }
}, { flush: 'sync' })

// Suggested questions
const suggestedQuestions = [
    'Menu có món gì ngon?',
    'Mì cay giá bao nhiêu?',
    'Đặt bàn như thế nào?',
    'Có khuyến mãi gì không?',
]

async function handleSend(): Promise<void> {
    const text = draft.value
    if (!text.trim() || sending.value) return
    const sent = await store.sendMessage(text)
    if (sent) draft.value = ''
    await scrollToBottom()
}

async function handleSuggested(question: string): Promise<void> {
    draft.value = question
    await handleSend()
}

async function scrollToBottom(): Promise<void> {
    await nextTick()
    if (messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight
    }
}

function messageReady(id: number): boolean {
    return !animatedMessageIds.value.has(id) || completedMessageIds.value.has(id)
}

async function followMessage(id: number): Promise<void> {
    await nextTick()
    if (!completedMessageIds.value.has(id) && messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight
    }
}

async function finishMessage(id: number): Promise<void> {
    if (!animatedMessageIds.value.has(id)) return
    completedMessageIds.value = new Set([...completedMessageIds.value, id])
    await nextTick()
    const panel = messagesEl.value
    const firstLink = panel?.querySelector<HTMLElement>(`[data-message-id="${id}"] .chatbot-msg__links a`)
    if (panel && firstLink) {
        const overflow = firstLink.getBoundingClientRect().bottom - panel.getBoundingClientRect().bottom + 8
        if (overflow > 0) panel.scrollTop += overflow
    }
}

async function handleOpen(): Promise<void> {
    if (!isOpen.value) {
        store.open()
        if (store.sessionId && !sending.value) await store.loadSession()
        else await store.ensureSession()
        await scrollToBottom()
    } else {
        store.close()
        animatedMessageIds.value = new Set()
        completedMessageIds.value = new Set()
    }
}

async function handleStartNew(): Promise<void> {
    await store.startNewChat()
    await scrollToBottom()
}

function handleConfirm(actionId: number) {
    confirmLoading.value.set(actionId, true)
    store.confirmAction(actionId).finally(() => {
        confirmLoading.value.delete(actionId)
    })
}

function handleCancel(actionId: number) {
    confirmLoading.value.set(actionId, true)
    store.cancelAction(actionId).finally(() => {
        confirmLoading.value.delete(actionId)
    })
}

function isConfirmLoading(actionId: number): boolean {
    return confirmLoading.value.get(actionId) || false
}

onMounted(async () => {
    // Session is loaded lazily when widget opens
})
</script>

<template>
    <!-- Toggle button -->
    <button
        class="chatbot-toggle"
        :class="{ 'is-open': isOpen }"
        :aria-label="isOpen ? 'Đóng chat' : 'Mở chat'"
        @click="handleOpen"
    >
        <span v-if="!isOpen" class="chatbot-toggle__icon" aria-hidden="true">🌶️</span>
        <span v-else class="chatbot-toggle__icon" aria-hidden="true">✕</span>
    </button>

    <!-- Chat panel -->
    <Transition name="chatbot-panel">
        <div v-if="isOpen" class="chatbot-panel" role="dialog" aria-label="Chat với trợ lý ảo">
            <!-- Header -->
            <header class="chatbot-panel__header">
                <div class="chatbot-panel__title">
                    <span class="chatbot-panel__avatar" aria-hidden="true">🌶️</span>
                    <div>
                        <h3 class="chatbot-panel__heading">Trợ lý ảo K-Spicy</h3>
                        <p class="chatbot-panel__subtitle">
                            <span v-if="loading">Đang tải...</span>
                            <span v-else-if="sessionStatus === 'closed'">Phiên đã đóng</span>
                            <span v-else-if="sending">Đang trả lời...</span>
                            <span v-else>Sẵn sàng hỗ trợ</span>
                        </p>
                    </div>
                </div>
                <button
                    class="chatbot-panel__new"
                    :disabled="sending || loading"
                    @click="handleStartNew"
                    aria-label="Bắt đầu hội thoại mới"
                >
                    ↻ Mới
                </button>
            </header>

            <!-- Error -->
            <div v-if="error" class="chatbot-panel__error" role="alert">
                <span>{{ error }}</span>
                <button class="chatbot-panel__error-close" @click="store.clearError()" aria-label="Đóng lỗi">✕</button>
            </div>

            <!-- Messages -->
            <div ref="messagesEl" class="chatbot-panel__messages" aria-live="polite">
                <!-- Loading state -->
                <div v-if="loading && messages.length === 0" class="chatbot-panel__empty">
                    Đang tải cuộc hội thoại...
                </div>

                <!-- Empty state with suggestions -->
                <div v-else-if="messages.length === 0" class="chatbot-panel__empty">
                    <p>Xin chào! Mình có thể giúp gì cho bạn?</p>
                    <div class="chatbot-panel__suggestions">
                        <button 
                            v-for="q in suggestedQuestions" 
                            :key="q"
                            class="chatbot-panel__suggestion"
                            @click="handleSuggested(q)"
                        >
                            {{ q }}
                        </button>
                    </div>
                </div>

                <!-- Messages -->
                <template v-else>
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        :data-message-id="msg.id"
                        class="chatbot-msg"
                        :class="`chatbot-msg--${msg.role}`"
                    >
                        <div class="chatbot-msg__content">
                            <ChatMessageText
                                v-if="msg.role === 'assistant'"
                                :text="msg.content"
                                :animate="animatedMessageIds.has(msg.id)"
                                @progress="followMessage(msg.id)"
                                @complete="finishMessage(msg.id)"
                            />
                            <template v-else>{{ msg.content }}</template>
                            <ul v-if="msg.role === 'assistant' && messageReady(msg.id) && msg.metadata?.menu_suggestions?.length" class="chatbot-msg__links" aria-label="Các món trong thực đơn">
                                <li v-for="item in msg.metadata.menu_suggestions" :key="item.slug">
                                    <RouterLink :to="{ path: '/thuc-don', query: { search: item.name } }" @click="store.close()">
                                        <span class="chatbot-msg__image">
                                            <img v-if="item.image_url" :src="imageUrl(item.image_url)" alt="" loading="lazy" />
                                            <span v-else aria-hidden="true">🍜</span>
                                        </span>
                                        <span class="chatbot-msg__item"><strong>{{ item.name }}</strong><small v-if="item.description">{{ item.description }}</small></span>
                                        <span aria-hidden="true">→</span>
                                    </RouterLink>
                                </li>
                            </ul>
                            <RouterLink
                                v-if="msg.role === 'assistant' && messageReady(msg.id) && msg.metadata?.quick_action?.type === 'reservation_form'"
                                class="chatbot-msg__booking"
                                to="/dat-ban"
                                @click="store.close()"
                            >Đặt bàn</RouterLink>
                        </div>
                    </div>
                </template>

                <!-- Typing indicator -->
                <div v-if="sending" class="chatbot-msg chatbot-msg--assistant chatbot-msg--typing">
                    <div class="chatbot-msg__content">
                        <span class="chatbot-typing"><span></span><span></span><span></span></span>
                    </div>
                </div>

                <!-- Pending confirmation cards -->
                <template v-if="hasPendingActions && messages.length > 0">
                    <div 
                        v-for="pa in pendingActionsList" 
                        :key="pa.id"
                        class="chatbot-msg chatbot-msg--assistant"
                    >
                        <ConfirmationCard
                            :action-id="pa.id"
                            :summary="pa.summary"
                            :status="pa.status"
                            :loading="isConfirmLoading(pa.id)"
                            @confirm="handleConfirm"
                            @cancel="handleCancel"
                        />
                    </div>
                </template>
            </div>

            <!-- Input -->
            <form class="chatbot-panel__input" @submit.prevent="handleSend">
                <input
                    v-model="draft"
                    type="text"
                    placeholder="Nhập câu hỏi..."
                    :disabled="sending || loading || sessionStatus === 'closed'"
                    maxlength="2000"
                    aria-label="Tin nhắn"
                />
                <button
                    type="submit"
                    :disabled="!draft.trim() || sending || loading || sessionStatus === 'closed'"
                    aria-label="Gửi"
                >
                    Gửi
                </button>
            </form>
        </div>
    </Transition>
</template>

<style scoped>
/* ===== Toggle button ===== */
.chatbot-toggle {
    position: fixed;
    right: 24px;
    bottom: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: none;
    background: #d32f2f;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    z-index: 9990;
    transition: transform 0.2s ease, background 0.2s ease;
}

.chatbot-toggle:hover {
    transform: scale(1.06);
    background: #b71c1c;
}

.chatbot-toggle.is-open {
    background: #444;
}

/* ===== Panel ===== */
.chatbot-panel {
    position: fixed;
    right: 24px;
    bottom: 92px;
    width: 380px;
    max-width: calc(100vw - 32px);
    height: 560px;
    max-height: calc(100vh - 120px);
    background: #fffaf2;
    border-radius: 16px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22);
    display: flex;
    flex-direction: column;
    z-index: 9991;
    overflow: hidden;
    font-family: inherit;
}

.chatbot-panel-enter-active,
.chatbot-panel-leave-active {
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.chatbot-panel-enter-from,
.chatbot-panel-leave-to {
    opacity: 0;
    transform: translateY(12px);
}

.chatbot-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: linear-gradient(135deg, #d32f2f 0%, #b71c1c 100%);
    color: #fff;
}

.chatbot-panel__title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chatbot-panel__avatar {
    font-size: 28px;
}

.chatbot-panel__heading {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
}

.chatbot-panel__subtitle {
    margin: 2px 0 0;
    font-size: 12px;
    opacity: 0.85;
}

.chatbot-panel__new {
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    cursor: pointer;
    transition: background 0.15s ease;
}
.chatbot-panel__new:hover {
    background: rgba(255, 255, 255, 0.28);
}
.chatbot-panel__new:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.chatbot-panel__error {
    padding: 8px 16px;
    background: #ffebee;
    color: #b71c1c;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #ffcdd2;
}
.chatbot-panel__error-close {
    background: none;
    border: none;
    color: #b71c1c;
    cursor: pointer;
    font-size: 14px;
    padding: 0 4px;
}

.chatbot-panel__messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.chatbot-panel__empty {
    text-align: center;
    color: #8d6e63;
    font-size: 14px;
    padding: 24px 12px;
    line-height: 1.5;
}

.chatbot-panel__suggestions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 16px;
}

.chatbot-panel__suggestion {
    background: #fff;
    border: 1px solid #e8d4c4;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
    color: #5d4037;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
    text-align: left;
}

.chatbot-panel__suggestion:hover {
    background: #fff5e6;
    border-color: #d32f2f;
}

.chatbot-msg {
    display: flex;
    max-width: 88%;
}
.chatbot-msg--user {
    align-self: flex-end;
}
.chatbot-msg--assistant {
    align-self: flex-start;
}

.chatbot-msg__content {
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 14px;
    line-height: 1.45;
    word-break: break-word;
    white-space: pre-wrap;
}
.chatbot-msg--user .chatbot-msg__content {
    background: #d32f2f;
    color: #fff;
    border-bottom-right-radius: 4px;
}
.chatbot-msg--assistant .chatbot-msg__content {
    background: #fff;
    color: #2e2e2e;
    border: 1px solid #f0e0c0;
    border-bottom-left-radius: 4px;
}

.chatbot-msg--typing .chatbot-msg__content {
    background: #fff;
    border: 1px solid #f0e0c0;
}

.chatbot-msg__links {
    margin: 12px 0 0;
    padding: 0;
    list-style: none;
}
.chatbot-msg__links li + li { margin-top: 8px; }
.chatbot-msg__links a {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 64px;
    padding: 5px;
    color: #a8122a;
    text-decoration: none;
    background: #fffaf2;
    border: 1px solid #f0e0c0;
    border-radius: 10px;
}
.chatbot-msg__image {
    width: 54px;
    height: 54px;
    flex: none;
    display: grid;
    place-items: center;
    overflow: hidden;
    border-radius: 7px;
    background: #f7e6d3;
}
.chatbot-msg__image img { width: 100%; height: 100%; object-fit: cover; }
.chatbot-msg__item { flex: 1; min-width: 0; white-space: normal; }
.chatbot-msg__item strong { display: block; line-height: 1.25; }
.chatbot-msg__item small {
    display: -webkit-box;
    overflow: hidden;
    color: #665b55;
    line-height: 1.3;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.chatbot-msg__links a:focus-visible,
.chatbot-msg__booking:focus-visible { outline: 2px solid #a8122a; outline-offset: 3px; }
.chatbot-msg__booking {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    margin-top: 12px;
    padding: 0 16px;
    border-radius: 10px;
    background: #a8122a;
    color: #fff;
    font-weight: 600;
    text-decoration: none;
}

.chatbot-typing {
    display: inline-flex;
    gap: 4px;
    align-items: center;
}
.chatbot-typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #b71c1c;
    animation: chatbot-typing 1s infinite ease-in-out;
}
.chatbot-typing span:nth-child(2) { animation-delay: 0.15s; }
.chatbot-typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes chatbot-typing {
    0%, 60%, 100% { opacity: 0.3; transform: scale(0.8); }
    30% { opacity: 1; transform: scale(1); }
}

.chatbot-panel__input {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    background: #fff;
    border-top: 1px solid #f0e0c0;
}
.chatbot-panel__input input {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid #e0d0b0;
    border-radius: 12px;
    font-size: 14px;
    background: #fffaf2;
    color: #2e2e2e;
    outline: none;
    transition: border 0.15s ease;
}
.chatbot-panel__input input:focus {
    border-color: #d32f2f;
}
.chatbot-panel__input input:disabled {
    background: #f5f5f5;
    cursor: not-allowed;
}
.chatbot-panel__input button {
    padding: 0 16px;
    background: #d32f2f;
    color: #fff;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}
.chatbot-panel__input button:hover:not(:disabled) {
    background: #b71c1c;
}
.chatbot-panel__input button:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* ===== Mobile ===== */
@media (max-width: 480px) {
    .chatbot-panel {
        right: 8px;
        bottom: 76px;
        width: calc(100vw - 16px);
        height: calc(100vh - 100px);
    }
    .chatbot-toggle {
        right: 12px;
        bottom: 12px;
    }
}
</style>
