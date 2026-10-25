<template>
    <Teleport to="body">
        <div class="feedback-toasts" aria-label="Thông báo">
            <TransitionGroup name="feedback-toast">
                <article
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="feedback-toast"
                    :class="`feedback-toast--${toast.type}`"
                    :role="toast.type === 'error' ? 'alert' : 'status'"
                    :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
                >
                    <span class="feedback-toast__mark" aria-hidden="true">{{ toastIcon(toast.type) }}</span>
                    <p class="feedback-toast__message">{{ toast.message }}</p>
                    <button class="feedback-toast__dismiss" type="button" aria-label="Đóng thông báo" @click="dismiss(toast.id)">×</button>
                </article>
            </TransitionGroup>
        </div>

        <dialog
            ref="dialogElement"
            class="feedback-dialog"
            :class="current ? `feedback-dialog--${current.tone}` : ''"
            :aria-labelledby="current ? 'feedback-dialog-title' : undefined"
            :aria-describedby="current ? 'feedback-dialog-message' : undefined"
            :role="current?.kind === 'confirm' ? 'alertdialog' : 'dialog'"
            @cancel.prevent="cancelDialog"
            @submit.prevent="submitDialog"
        >
            <form v-if="current" class="feedback-dialog__panel">
                <span class="feedback-dialog__eyebrow">K-SPICY · NHÀ HÀNG</span>
                <h2 id="feedback-dialog-title" class="feedback-dialog__title">{{ current.title }}</h2>
                <p id="feedback-dialog-message" class="feedback-dialog__message">{{ current.message }}</p>

                <label v-if="current.kind === 'prompt'" class="feedback-dialog__field">
                    <span>{{ current.label }}</span>
                    <input v-model="current.value" :placeholder="current.placeholder" autofocus />
                </label>

                <div class="feedback-dialog__actions">
                    <button class="feedback-dialog__button feedback-dialog__button--quiet" type="button" @click="cancelDialog">
                        {{ current.cancelLabel }}
                    </button>
                    <button class="feedback-dialog__button feedback-dialog__button--primary" type="submit" autofocus>
                        {{ current.confirmLabel }}
                    </button>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useAppDialog } from '@/composables/useAppDialog'
import { useToast } from '@/composables/useStaffUtils'

const { current, resolveCurrent } = useAppDialog()
const { toasts, dismiss } = useToast()
const dialogElement = ref<HTMLDialogElement | null>(null)
const activeDialog = computed(() => current.value)

watch(activeDialog, async (request) => {
    if (!request) return
    await nextTick()
    if (!dialogElement.value?.open) dialogElement.value?.showModal()
    const focusTarget = dialogElement.value?.querySelector<HTMLElement>(request.kind === 'prompt' ? 'input' : '.feedback-dialog__button--quiet')
    focusTarget?.focus()
}, { flush: 'post' })

onMounted(() => {
    if (current.value && !dialogElement.value?.open) dialogElement.value?.showModal()
})

function finish(value: boolean | string | null): void {
    if (dialogElement.value?.open) dialogElement.value.close()
    resolveCurrent(value)
}

function cancelDialog(): void {
    finish(current.value?.kind === 'prompt' ? null : false)
}

function submitDialog(): void {
    const request = current.value
    if (!request) return
    finish(request.kind === 'prompt' ? request.value : true)
}

function toastIcon(type: 'success' | 'error' | 'warning' | 'info'): string {
    return type === 'success' ? '✓' : type === 'error' ? '!' : type === 'warning' ? '!' : 'i'
}
</script>

<style>
.feedback-toasts {
    position: fixed;
    z-index: 11000;
    top: 82px;
    right: 22px;
    width: min(390px, calc(100vw - 32px));
    display: grid;
    gap: 10px;
    pointer-events: none;
}

.feedback-toast {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) 28px;
    align-items: center;
    gap: 10px;
    min-height: 56px;
    padding: 10px 12px;
    border: 1px solid #eadbd4;
    border-left: 4px solid var(--feedback-accent, #b5121b);
    border-radius: 13px;
    background: #fffaf7;
    color: #332522;
    box-shadow: 0 12px 34px rgb(50 28 24 / 16%);
    pointer-events: auto;
}
.feedback-toast--success { --feedback-accent: #3f6b4a; }
.feedback-toast--error { --feedback-accent: #b5121b; }
.feedback-toast--warning { --feedback-accent: #b56b18; }
.feedback-toast--info { --feedback-accent: #70503f; }
.feedback-toast__mark {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--feedback-accent) 12%, white);
    color: var(--feedback-accent);
    font-size: 15px;
    font-weight: 800;
}
.feedback-toast__message { margin: 0; font-size: 13px; line-height: 1.45; font-weight: 600; }
.feedback-toast__dismiss {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #735f58;
    font: inherit;
    font-size: 20px;
    cursor: pointer;
}
.feedback-toast__dismiss:hover { background: #f2e8e2; }
.feedback-toast__dismiss:focus-visible,
.feedback-dialog__button:focus-visible,
.feedback-dialog__field input:focus-visible { outline: 3px solid #d9a23c; outline-offset: 2px; }

.feedback-dialog {
    width: min(460px, calc(100vw - 32px));
    max-width: none;
    padding: 0;
    overflow: visible;
    border: 1px solid #eadbd4;
    border-radius: 20px;
    background: #fffaf7;
    color: #332522;
    box-shadow: 0 24px 80px rgb(32 12 14 / 28%);
}
.feedback-dialog::backdrop { background: rgb(35 15 16 / 56%); backdrop-filter: blur(2px); }
.feedback-dialog__panel { padding: 26px 28px 22px; }
.feedback-dialog__eyebrow { color: #a71b22; font-size: 10px; font-weight: 800; letter-spacing: 1.7px; }
.feedback-dialog__title { margin: 8px 0 8px; color: #302321; font-size: 21px; line-height: 1.3; font-weight: 800; }
.feedback-dialog__message { margin: 0; color: #6f5b54; font-size: 14px; line-height: 1.6; white-space: pre-line; }
.feedback-dialog__field { display: grid; gap: 7px; margin-top: 18px; color: #45332f; font-size: 13px; font-weight: 700; }
.feedback-dialog__field input { box-sizing: border-box; width: 100%; min-height: 44px; border: 1px solid #dfccc2; border-radius: 10px; padding: 10px 12px; background: #fff; color: #302321; font: inherit; font-weight: 500; }
.feedback-dialog__actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
.feedback-dialog__button { min-height: 42px; border: 1px solid transparent; border-radius: 10px; padding: 0 16px; font: inherit; font-size: 13px; font-weight: 750; cursor: pointer; transition: background-color 140ms ease, transform 140ms ease; }
.feedback-dialog__button:active { transform: translateY(1px); }
.feedback-dialog__button--quiet { border-color: #eadbd4; background: #fff; color: #55433d; }
.feedback-dialog__button--quiet:hover { background: #f7eee9; }
.feedback-dialog__button--primary { background: #b5121b; color: white; }
.feedback-dialog__button--primary:hover { background: #951018; }
.feedback-dialog--warning .feedback-dialog__button--primary { background: #a56619; }
.feedback-toast-enter-active, .feedback-toast-leave-active, .feedback-toast-move { transition: opacity 180ms ease, transform 180ms ease; }
.feedback-toast-enter-from, .feedback-toast-leave-to { opacity: 0; transform: translateX(14px); }

@media (max-width: 600px) {
    .feedback-toasts { top: auto; right: 12px; bottom: max(16px, env(safe-area-inset-bottom)); width: calc(100vw - 24px); }
    .feedback-dialog__panel { padding: 22px 20px 18px; }
    .feedback-dialog__actions { display: grid; grid-template-columns: 1fr 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .feedback-toast-enter-active, .feedback-toast-leave-active, .feedback-toast-move, .feedback-dialog__button { transition: none; }
}
</style>
