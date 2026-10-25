<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { createVNPay, paymentStatus, type PaymentStatus } from '@/api/payments'
import { createPaymentPoller } from '@/utils/paymentPolling'
import NavbarHome from '@/components/home/NavbarHome.vue'
import UiButton from '@/components/ui/UiButton.vue'

const route = useRoute()
const auth = useAuthStore()
const result = ref<PaymentStatus | null>(null)
const error = ref('')
const busy = ref(false)
const redirecting = ref(false)
let alive = true
const labels = { paid: 'Thanh toán thành công', pending: 'Thanh toán đang được xác nhận…', failed: 'Thanh toán không thành công', cancelled: 'Bạn đã hủy thanh toán.', expired: 'Đang xác minh giao dịch đã hết hạn', refunded: 'Giao dịch đã hoàn tiền' }
// Khi payment PAID nhưng order CANCELLED → tiền đã trừ nhưng nhà hàng chưa xử lý xong.
// Hiển thị cảnh báo rõ ràng để khách không hoang mang.
const orderDeferred = computed(() =>
    result.value?.status === 'paid' && result.value?.order_status === 'CANCELLED'
)
const title = computed(() => {
    if (orderDeferred.value) return 'Đã thanh toán — đơn hàng đang được nhà hàng xử lý'
    return result.value ? labels[result.value.status] : error.value ? 'Chưa xác nhận thanh toán' : 'Đang kiểm tra thanh toán…'
})
const canPoll = computed(() => !!result.value && ['pending', 'expired'].includes(result.value.status))
const poller = createPaymentPoller({
    read: paymentStatus,
    currentSession: () => {
        const revision = auth.sessionRevision
        const token = auth.token
        return () => alive && auth.isCurrentSession(revision, token)
    },
    result: value => { result.value = value },
    error: value => { error.value = value },
    busy: value => { busy.value = value },
})
function start() {
    poller.stop()
    result.value = null
    error.value = ''
    const invalid = route.query.error
    if (typeof invalid === 'string') {
        if (['invalid_signature', 'invalid_payment', 'invalid_amount'].includes(invalid)) {
            error.value = 'Không xác minh được thông tin VNPAY trả về. Vui lòng xem trạng thái đơn hàng hoặc liên hệ nhà hàng nếu đã bị trừ tiền.'
            return
        }
        if (invalid === 'requires_reconciliation') {
            // The backend has not confirmed the payment yet. This is not a verdict:
            // keep polling the status endpoint so the page switches to
            // "Thanh toán thành công" by itself once the payment is settled
            // (e.g. by a later reconciliation run or the VNPAY IPN callback).
            const pending = route.query.payment_code
            if (typeof pending === 'string' && /^VNP[a-zA-Z0-9]+$/.test(pending)) {
                error.value = 'Đang chờ nhà hàng xác nhận giao dịch từ VNPAY. Hệ thống sẽ tự cập nhật ngay khi xác nhận xong.'
                poller.start(pending)
                return
            }
            error.value = 'Chưa xác nhận được thanh toán. Vui lòng mở lại đơn hàng hoặc liên hệ nhà hàng nếu bạn đã bị trừ tiền.'
            return
        }
    }
    const code = route.query.payment_code
    if (typeof code !== 'string' || !/^VNP[a-zA-Z0-9]+$/.test(code)) { error.value = 'Thiếu hoặc sai mã giao dịch VNPAY. Vui lòng mở lại đơn hàng.'; return }
    poller.start(code)
}
async function retry() {
    if (!result.value?.can_retry || busy.value || redirecting.value) return
    const revision = auth.sessionRevision
    const token = auth.token
    const code = route.query.payment_code
    redirecting.value = true
    try {
        const payment = await createVNPay(result.value.order_id)
        if (alive && code === route.query.payment_code && auth.isCurrentSession(revision, token)) window.location.assign(payment.payment_url)
    } catch { if (alive) error.value = 'Chưa tạo được giao dịch mới. Hãy xem đơn hàng và kiểm tra lại.' }
    finally { redirecting.value = false }
}
function resume() { if (canPoll.value || !result.value) void poller.refresh() }
watch(() => [route.query.payment_code, route.query.error], start)
watch(() => auth.sessionRevision, () => { poller.stop(); result.value = null; error.value = 'Phiên đăng nhập đã thay đổi. Vui lòng mở lại đơn hàng.' })
onMounted(() => { start(); window.addEventListener('online', resume); window.addEventListener('pageshow', resume) })
onBeforeUnmount(() => { alive = false; poller.stop(); window.removeEventListener('online', resume); window.removeEventListener('pageshow', resume) })
</script>

<template>
    <a class="skip-link" href="#payment-result-main">Bỏ qua điều hướng</a>
    <NavbarHome />
    <main
        id="payment-result-main"
        class="payment-result"
        :aria-busy="busy || redirecting"
    >
        <section
            class="payment-card"
            :class="[
                result ? `status-${result.status}` : error ? 'status-error' : 'status-loading',
                { 'status-deferred': orderDeferred },
            ]"
            aria-labelledby="payment-result-title"
        >
            <header class="payment-card__header">
                <span class="payment-card__brand">K-SPICY</span>
                <span class="payment-card__provider">Thanh toán qua VNPAY</span>
            </header>

            <div class="payment-card__content">
                <div class="status-mark" aria-hidden="true">
                    <svg v-if="result?.status === 'paid'" viewBox="0 0 48 48">
                        <path d="M14 24.5 21 31l13-14" />
                    </svg>
                    <svg v-else-if="result?.status === 'refunded'" viewBox="0 0 48 48">
                        <path d="M15 18h12a9 9 0 1 1-8.1 12.9M15 18l5-5m-5 5 5 5" />
                    </svg>
                    <svg v-else-if="result?.status === 'failed' || result?.status === 'cancelled' || error" viewBox="0 0 48 48">
                        <path d="m17 17 14 14M31 17 17 31" />
                    </svg>
                    <svg v-else viewBox="0 0 48 48">
                        <circle cx="24" cy="24" r="13" />
                        <path d="M24 16v9l6 3" />
                    </svg>
                </div>

                <div class="payment-summary">
                    <p class="payment-label">Kết quả thanh toán</p>
                    <h1 id="payment-result-title" role="status">{{ title }}</h1>

                    <div class="payment-messages">
                        <p v-if="error" class="payment-message payment-message--error" role="alert">{{ error }}</p>
                        <p v-if="redirecting" class="payment-message" role="status">Đang chuyển sang VNPAY…</p>
                        <template v-if="result">
                            <p v-if="result.status === 'paid' && !orderDeferred" class="payment-message">Đơn hàng #{{ result.order_code ?? result.order_id }} đã được thanh toán thành công. Nhà hàng đã nhận được đơn hàng của bạn.</p>
                            <p v-if="result.status === 'paid' && result.order_status === 'CONFIRMED'" class="payment-message payment-message--compact">Đơn hàng đang được xử lý.</p>
                            <p v-if="orderDeferred" class="payment-message payment-message--warning" role="status">Thanh toán thành công nhưng đơn hàng #{{ result.order_code ?? result.order_id }} đang được nhà hàng xử lý (refund hoặc mở lại đơn). Nhà hàng sẽ liên hệ bạn nếu cần hoàn tiền.</p>
                            <p v-if="result.status === 'failed'" class="payment-message payment-message--error">Thanh toán không thành công. Vui lòng thử lại.</p>
                            <p v-if="canPoll" class="payment-message payment-message--warning">Chưa ghi nhận thanh toán thành công. Nếu đã bị trừ tiền, bạn không cần đặt đơn mới; hãy giữ mã giao dịch để đối soát.</p>
                        </template>
                    </div>
                </div>
            </div>

            <div v-if="result" class="payment-details">
                <div class="payment-details__heading">
                    <h2>Chi tiết giao dịch</h2>
                    <span>Đã bảo mật</span>
                </div>
                <dl>
                    <div><dt>Đơn hàng</dt><dd>#{{ result.order_code ?? result.order_id }}</dd></div>
                    <div><dt>Tổng tiền</dt><dd class="payment-amount">{{ result.amount.toLocaleString('vi-VN') }} VNĐ</dd></div>
                    <div v-if="result.transaction_id"><dt>Mã giao dịch</dt><dd>{{ result.transaction_id }}</dd></div>
                    <div v-if="result.paid_at"><dt>Thời gian</dt><dd>{{ new Date(result.paid_at).toLocaleString('vi-VN') }}</dd></div>
                </dl>
            </div>

            <footer class="payment-actions">
                <UiButton v-if="result?.can_retry" size="lg" aria-label="Thanh toán lại qua VNPAY" :loading="redirecting" :disabled="busy" @click="retry">Thanh toán lại</UiButton>
                <UiButton v-if="canPoll || (!result && !route.query.error && route.query.payment_code)" size="lg" variant="outline" :aria-label="busy ? 'Đang kiểm tra thanh toán' : 'Kiểm tra lại kết quả'" :loading="busy" :disabled="redirecting" @click="poller.refresh">Kiểm tra lại kết quả</UiButton>
                <UiButton
                    v-if="result"
                    as="router-link"
                    size="lg"
                    :variant="result.can_retry || canPoll ? 'outline' : 'primary'"
                    :to="{ name: 'order-detail', params: { id: result.order_id } }"
                >Xem đơn hàng</UiButton>
                <UiButton
                    v-else
                    as="router-link"
                    size="lg"
                    variant="primary"
                    :to="{ name: 'account', query: { tab: 'orders' } }"
                >Xem các đơn hàng</UiButton>
                <UiButton as="router-link" to="/" size="lg" variant="ghost">Về trang chủ</UiButton>
            </footer>
        </section>
    </main>
</template>

<style scoped>
.skip-link {
    position: fixed;
    inset-block-start: var(--space-2);
    inset-inline-start: var(--space-2);
    z-index: var(--z-tooltip);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-button);
    background: var(--color-surface);
    color: var(--color-text);
    font-weight: var(--weight-semibold);
    transform: translateY(-150%);
    transition: transform var(--dur-fast) var(--ease-out);
}

.skip-link:focus-visible { transform: translateY(0); }

.payment-result {
    display: grid;
    place-items: center;
    min-height: 100svh;
    padding: calc(var(--navbar-height) + var(--space-8)) var(--space-6) var(--space-10);
    overflow: hidden;
    background: var(--color-background);
    color: var(--color-text);
    font-size: var(--font-size-body);
}

.payment-card {
    width: min(100%, 880px);
    overflow: hidden;
    border-radius: var(--radius-modal);
    background: var(--color-surface);
    box-shadow: var(--shadow-xl);
    animation: payment-card-in var(--dur-slow) var(--ease-out) both;
}

.payment-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    min-height: 56px;
    padding: var(--space-3) var(--space-7);
    background: var(--color-brand-dark);
    color: var(--color-text-inverse);
}

.payment-card__brand {
    font-family: var(--font-display);
    font-weight: var(--weight-black);
    letter-spacing: var(--tracking-heading);
}

.payment-card__provider {
    color: var(--color-brand-muted-inverse);
    font-size: var(--font-size-ui);
    font-weight: var(--weight-medium);
}

.payment-card__content {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--space-6);
    align-items: start;
    padding: var(--space-7) var(--space-8);
}

.status-mark {
    display: grid;
    place-items: center;
    width: 88px;
    aspect-ratio: 1;
    border-radius: var(--radius-pill);
    background: var(--status-warning-bg);
    color: var(--status-warning-text);
}

.status-mark svg {
    width: 48px;
    height: 48px;
    fill: none;
    stroke: currentColor;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.status-paid .status-mark {
    background: var(--status-success-bg);
    color: var(--status-success-text);
}

.status-failed .status-mark,
.status-cancelled .status-mark,
.status-error .status-mark {
    background: var(--status-error-bg);
    color: var(--status-error-text);
}

.status-refunded .status-mark {
    background: var(--status-info-bg);
    color: var(--color-brand-dark);
}

.status-loading .status-mark svg { animation: payment-status-pulse 1.4s var(--ease-in-out) infinite; }

.payment-summary { min-width: 0; }

.payment-label {
    margin: 0 0 var(--space-3);
    color: var(--color-brand-action);
    font-size: var(--font-size-small);
    font-weight: var(--weight-bold);
    letter-spacing: var(--tracking-eyebrow);
    text-transform: uppercase;
}

h1 {
    max-width: 16ch;
    margin: 0;
    font-family: var(--font-display);
    font-size: var(--font-size-h3);
    font-weight: var(--weight-extrabold);
    line-height: var(--leading-snug);
    letter-spacing: var(--tracking-heading);
    text-wrap: balance;
}

.status-paid h1 { color: var(--status-success-text); }
.status-failed h1,
.status-cancelled h1,
.status-error h1 { color: var(--status-error-text); }

.payment-messages {
    display: grid;
    gap: var(--space-3);
    max-width: 62ch;
    margin-block-start: var(--space-5);
}

.payment-message {
    margin: 0;
    color: var(--color-text-muted);
    line-height: var(--leading-body);
}

.payment-message--compact { font-weight: var(--weight-semibold); }

.payment-message--warning,
.payment-message--error {
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-card);
    background: var(--status-warning-bg);
    color: var(--status-warning-text);
}

.payment-message--error {
    background: var(--status-error-bg);
    color: var(--status-error-text);
}

.payment-details {
    margin-inline: var(--space-8);
    padding: var(--space-5) var(--space-6);
    border-top: var(--border-width) dashed var(--color-border-strong);
    border-bottom: var(--border-width) dashed var(--color-border-strong);
    background: var(--color-background-alt);
}

.payment-details__heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-4);
    margin-block-end: var(--space-3);
}

.payment-details__heading h2 {
    margin: 0;
    font-family: var(--font-display);
    font-size: var(--font-size-product);
    line-height: var(--leading-snug);
}

.payment-details__heading span {
    color: var(--status-success-text);
    font-size: var(--font-size-ui);
    font-weight: var(--weight-semibold);
}

dl { margin: 0; }

dl div {
    display: grid;
    grid-template-columns: minmax(120px, 0.65fr) minmax(0, 1fr);
    gap: var(--space-4);
    padding-block: var(--space-2);
}

dt { color: var(--color-text-muted); }

dd {
    min-width: 0;
    margin: 0;
    font-weight: var(--weight-semibold);
    text-align: end;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}

.payment-amount {
    color: var(--color-brand-action);
    font-family: var(--font-display);
    font-size: var(--font-size-product);
    font-weight: var(--weight-extrabold);
}

.payment-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-6) var(--space-8);
}

.payment-actions :deep(.ui-btn:focus-visible),
.skip-link:focus-visible {
    outline: var(--border-width-bold) solid var(--color-brand-action);
    outline-offset: 3px;
}

@keyframes payment-card-in {
    from { opacity: 0; transform: translateY(var(--space-3)); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes payment-status-pulse {
    50% { opacity: 0.55; transform: scale(0.92); }
}

@media (max-width: 768px) {
    .payment-result {
        place-items: start center;
        min-height: 100svh;
        padding: calc(var(--navbar-height-mobile) + var(--space-5)) var(--space-4) var(--space-8);
        background: var(--color-background);
    }

    .payment-card__header,
    .payment-card__content,
    .payment-actions { padding-inline: var(--space-5); }

    .payment-card__content {
        grid-template-columns: 1fr;
        gap: var(--space-5);
        padding-block: var(--space-7);
    }

    .status-mark { width: 72px; }
    .status-mark svg { width: 40px; height: 40px; }
    h1 { max-width: none; font-size: var(--font-size-h3); }

    .payment-details {
        margin-inline: var(--space-5);
        padding: var(--space-5) 0;
    }

    .payment-actions {
        display: grid;
        grid-template-columns: 1fr;
        padding-block: var(--space-6);
    }

    .payment-actions :deep(.ui-btn) { width: 100%; }
}

@media (max-width: 520px) {
    .payment-card__header { align-items: flex-start; flex-direction: column; gap: var(--space-1); }
    .payment-details__heading { align-items: flex-start; flex-direction: column; gap: var(--space-1); }
    dl div { grid-template-columns: 1fr; gap: var(--space-1); }
    dd { text-align: start; }
}

@media (prefers-reduced-motion: reduce) {
    .payment-card,
    .status-loading .status-mark svg { animation: none; }
    .skip-link { transition: none; }
}

@media (forced-colors: active) {
    .status-mark,
    .payment-card { border: var(--border-width) solid CanvasText; }
}
</style>
