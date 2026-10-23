<script setup lang="ts">
/**
 * LiveChatWidget — widget tư vấn trực tiếp với nhân viên.
 *
 * Chạy SONG SONG với ChatBotWidget: khách có thể vừa hỏi trợ lý ảo vừa nói
 * chuyện với nhân viên. Hai widget dùng chung một nút tròn góc phải nhưng
 * mở hai panel khác nhau.
 *
 * Tư vấn trực tiếp chỉ dành cho khách đã đăng nhập. Khách vãng lai thấy lời
 * mời đăng nhập thay vì form chat.
 */
import { ref, nextTick, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import { useLiveChatStore } from '@/stores/liveChat'
import { useAuthStore } from '@/stores/auth'

const liveChat = useLiveChatStore()
const auth = useAuthStore()
const {
    isOpen,
    messages,
    status,
    staffName,
    loading,
    sending,
    error,
    connected,
    requiresLogin,
    hasCustomerMessage,
} = storeToRefs(liveChat)

const draft = ref('')
const messagesEl = ref<HTMLElement | null>(null)

watch(() => messages.value.length, () => {
    void scrollToBottom()
})

async function handleOpen(): Promise<void> {
    if (!isOpen.value) {
        liveChat.open()
        await scrollToBottom()
    } else {
        liveChat.close()
    }
}

async function handleSend(): Promise<void> {
    const text = draft.value
    if (!text.trim() || sending.value) return
    const sent = await liveChat.sendMessage(text)
    if (sent) draft.value = ''
    await scrollToBottom()
}

/** Bắt đầu cuộc tư vấn mới: đóng phiên cũ rồi mở phiên trống. */
async function handleStartNew(): Promise<void> {
    await liveChat.startNewChat()
    await scrollToBottom()
}

async function scrollToBottom(): Promise<void> {
    await nextTick()
    if (messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight
    }
}

/** Nhân viên đã nhận phiên khi trạng thái chuyển sang accepted. */
function staffJoined(): boolean {
    return status.value === 'accepted' && !!staffName.value
}

onMounted(() => {
    // Phiên đang mở được nhớ trong sessionStorage; khôi phục ngay để khách
    // không mất cuộc trò chuyện sau khi F5.
    const stored = sessionStorage.getItem('live_chat_session_id')
    if (stored) liveChat.ensureSession()
})
</script>

<template>
    <!-- Nút mở widget -->
    <button
        class="livechat-toggle"
        :class="{ 'is-open': isOpen }"
        :aria-label="isOpen ? 'Đóng tư vấn trực tiếp' : 'Tư vấn trực tiếp với nhân viên'"
        @click="handleOpen"
    >
        <span class="livechat-toggle__icon" aria-hidden="true">💬</span>
        <span v-if="staffJoined() && !isOpen" class="livechat-toggle__dot" aria-hidden="true"></span>
    </button>

    <Transition name="livechat-panel">
        <div v-if="isOpen" class="livechat-panel" role="dialog" aria-label="Tư vấn trực tiếp với nhân viên">
            <!-- Header -->
            <header class="livechat-panel__header">
                <div class="livechat-panel__title">
                    <span class="livechat-panel__avatar" aria-hidden="true">🍜</span>
                    <div>
                        <h3 class="livechat-panel__heading">Tư vấn trực tiếp</h3>
                        <p class="livechat-panel__subtitle">
                            <span v-if="loading">Đang tải...</span>
                            <span v-else-if="status === 'closed'">Phiên đã kết thúc</span>
                            <span v-else-if="staffJoined()">{{ staffName }} đang phục vụ</span>
                            <span v-else-if="status === 'accepted'">Nhân viên đang phục vụ</span>
                            <span v-else-if="status === 'waiting'">Đang chờ nhân viên nhận</span>
                            <span v-else>Sẵn sàng hỗ trợ</span>
                        </p>
                    </div>
                </div>
                <div class="livechat-panel__actions">
                    <span
                        class="livechat-panel__status"
                        :class="{ 'is-online': connected }"
                        :title="connected ? 'Kết nối trực tiếp' : 'Đang kết nối...'"
                        aria-hidden="true"
                    ></span>
                    <button
                        class="livechat-panel__new"
                        :disabled="sending || loading"
                        @click="handleStartNew"
                        aria-label="Bắt đầu cuộc tư vấn mới"
                    >
                        ↻ Mới
                    </button>
                </div>
            </header>

            <!-- Khách chưa đăng nhập -->
            <div v-if="requiresLogin" class="livechat-panel__login">
                <p class="livechat-panel__login-title">Tư vấn trực tiếp cần đăng nhập</p>
                <p class="livechat-panel__login-text">
                    Đăng nhập để nhân viên biết mình đang hỗ trợ bạn và lưu lại lịch sử cuộc trò chuyện.
                </p>
                <RouterLink class="livechat-panel__login-btn" to="/dang-nhap" @click="liveChat.close()">
                    Đăng nhập để tư vấn
                </RouterLink>
            </div>

            <template v-else>
                <!-- Error -->
                <div v-if="error" class="livechat-panel__error" role="alert">
                    <span>{{ error }}</span>
                    <button class="livechat-panel__error-close" @click="liveChat.clearError()" aria-label="Đóng lỗi">✕</button>
                </div>

                <!-- Messages -->
                <div ref="messagesEl" class="livechat-panel__messages" aria-live="polite">
                    <div v-if="loading && messages.length === 0" class="livechat-panel__empty">
                        Đang tải cuộc tư vấn...
                    </div>

                    <div v-else-if="messages.length === 0" class="livechat-panel__empty">
                        <p>Xin chào! Bạn cần tư vấn gì hôm nay?</p>
                        <p class="livechat-panel__empty-hint">
                            Nhân viên sẽ phản hồi ngay khi nhận được yêu cầu.
                        </p>
                    </div>

                    <template v-else>
                        <div
                            v-for="msg in messages"
                            :key="msg.id"
                            class="livechat-msg"
                            :class="`livechat-msg--${msg.sender_type}`"
                        >
                            <div class="livechat-msg__content">{{ msg.content }}</div>
                        </div>
                    </template>

                    <!-- Trạng thái chờ: chỉ hiện khi khách đã nói -->
                    <div v-if="status === 'waiting' && hasCustomerMessage" class="livechat-panel__waiting">
                        <span class="livechat-typing"><span></span><span></span><span></span></span>
                        <span>Đang chờ nhân viên tư vấn trả lời</span>
                    </div>
                </div>

                <!-- Input -->
                <form class="livechat-panel__input" @submit.prevent="handleSend">
                    <input
                        v-model="draft"
                        type="text"
                        placeholder="Nhập tin nhắn..."
                        :disabled="sending || loading || status === 'closed'"
                        maxlength="2000"
                        aria-label="Tin nhắn tư vấn"
                    />
                    <button
                        type="submit"
                        :disabled="!draft.trim() || sending || loading || status === 'closed'"
                        aria-label="Gửi tin nhắn"
                    >
                        Gửi
                    </button>
                </form>
            </template>
        </div>
    </Transition>
</template>

<style scoped>
/* ===== Nút mở ===== */
.livechat-toggle {
    position: fixed;
    right: 24px;
    bottom: 92px;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: 2px solid #fffaf2;
    background: var(--color-brand-dark, #2b2320);
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    z-index: 9989;
    transition: transform 0.2s ease, background 0.2s ease;
}

.livechat-toggle:hover {
    transform: scale(1.06);
    background: var(--color-chili-red, #d32f2f);
}

.livechat-toggle.is-open {
    background: var(--color-chili-red, #d32f2f);
}

.livechat-toggle__dot {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: #22c55e;
    border: 2px solid #fff;
}

/* ===== Panel ===== */
.livechat-panel {
    position: fixed;
    right: 24px;
    bottom: 156px;
    width: 380px;
    max-width: calc(100vw - 32px);
    height: 520px;
    max-height: calc(100vh - 180px);
    background: #fffaf2;
    border-radius: 16px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.22);
    display: flex;
    flex-direction: column;
    z-index: 9990;
    overflow: hidden;
}

.livechat-panel-enter-active,
.livechat-panel-leave-active {
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.livechat-panel-enter-from,
.livechat-panel-leave-to {
    opacity: 0;
    transform: translateY(12px);
}

/* ===== Header ===== */
.livechat-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: linear-gradient(135deg, #2b2320 0%, #17110f 100%);
    color: #fff;
}

.livechat-panel__title {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.livechat-panel__actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.livechat-panel__avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    display: grid;
    place-items: center;
    font-size: 18px;
    flex-shrink: 0;
}

.livechat-panel__heading {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
}

.livechat-panel__subtitle {
    margin: 2px 0 0;
    font-size: 12px;
    opacity: 0.78;
    line-height: 1.3;
}

.livechat-panel__status {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #9ca3af;
    flex-shrink: 0;
}

.livechat-panel__status.is-online {
    background: #22c55e;
}

/* Nút mở cuộc tư vấn mới */
.livechat-panel__new {
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.15s ease;
}

.livechat-panel__new:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.28);
}

.livechat-panel__new:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== Login prompt ===== */
.livechat-panel__login {
    padding: 28px 22px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
}

.livechat-panel__login-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #2b2320;
}

.livechat-panel__login-text {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: #6b5f59;
}

.livechat-panel__login-btn {
    display: inline-block;
    margin-top: 4px;
    padding: 11px 20px;
    border-radius: 12px;
    background: var(--color-chili-red, #d32f2f);
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    transition: background 0.15s ease;
}

.livechat-panel__login-btn:hover {
    background: #b71c1c;
}

/* ===== Error ===== */
.livechat-panel__error {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 14px;
    background: #fdecea;
    color: #b3261e;
    font-size: 13px;
}

.livechat-panel__error-close {
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 14px;
    padding: 2px 4px;
}

/* ===== Messages ===== */
.livechat-panel__messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.livechat-panel__empty {
    margin: auto;
    text-align: center;
    color: #6b5f59;
    font-size: 14px;
    line-height: 1.6;
    padding: 0 20px;
}

.livechat-panel__empty p {
    margin: 0 0 6px;
}

.livechat-panel__empty-hint {
    font-size: 12.5px;
    opacity: 0.8;
}

.livechat-msg {
    display: flex;
}

.livechat-msg--customer {
    justify-content: flex-end;
}

.livechat-msg__content {
    max-width: 82%;
    padding: 9px 13px;
    border-radius: 14px;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

.livechat-msg--customer .livechat-msg__content {
    background: var(--color-chili-red, #d32f2f);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.livechat-msg--staff .livechat-msg__content {
    background: #fff;
    color: #2b2320;
    border: 1px solid #ece2d8;
    border-bottom-left-radius: 4px;
}

.livechat-msg--system .livechat-msg__content {
    background: #f2ece4;
    color: #6b5f59;
    font-size: 12.5px;
    text-align: center;
    margin: 0 auto;
}

/* ===== Chờ ===== */
.livechat-panel__waiting {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 13px;
    background: #f2ece4;
    border-radius: 14px;
    font-size: 12.5px;
    color: #6b5f59;
    align-self: flex-start;
}

.livechat-typing {
    display: inline-flex;
    gap: 3px;
}

.livechat-typing span {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
    animation: livechat-bounce 1.2s infinite ease-in-out;
}

.livechat-typing span:nth-child(2) { animation-delay: 0.15s; }
.livechat-typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes livechat-bounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
    30% { transform: translateY(-4px); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
    .livechat-typing span { animation: none; }
    .livechat-panel-enter-active,
    .livechat-panel-leave-active { transition: none; }
}

/* ===== Input ===== */
.livechat-panel__input {
    display: flex;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid #ece2d8;
    background: #fff;
}

.livechat-panel__input input {
    flex: 1;
    padding: 10px 13px;
    border: 1px solid #e0d5c9;
    border-radius: 12px;
    font-size: 14px;
    font-family: inherit;
    background: #fffaf2;
    color: #2b2320;
}

.livechat-panel__input input:focus {
    outline: 2px solid var(--color-chili-red, #d32f2f);
    outline-offset: 1px;
}

.livechat-panel__input button {
    padding: 10px 18px;
    border: none;
    border-radius: 12px;
    background: var(--color-chili-red, #d32f2f);
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.15s ease;
}

.livechat-panel__input button:hover:not(:disabled) {
    background: #b71c1c;
}

.livechat-panel__input button:disabled,
.livechat-panel__input input:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

@media (max-width: 480px) {
    .livechat-toggle {
        right: 16px;
        bottom: 84px;
        width: 48px;
        height: 48px;
    }
    .livechat-panel {
        right: 12px;
        left: 12px;
        bottom: 140px;
        width: auto;
        height: min(520px, calc(100vh - 160px));
    }
}
</style>
