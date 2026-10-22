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
    <NavbarHome />
    <main class="payment-result" :aria-busy="busy || redirecting">
        <p class="payment-label">K-SPICY · Kết quả thanh toán VNPAY</p>
        <h1 role="status" :class="{ 'is-paid': result?.status === 'paid' }">{{ title }}</h1>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="redirecting" role="status">Đang chuyển sang VNPAY…</p>
        <template v-if="result">
            <p v-if="result.status === 'paid' && !orderDeferred">Đơn hàng #{{ result.order_code ?? result.order_id }} đã được thanh toán thành công. Nhà hàng đã nhận được đơn hàng của bạn.</p>
            <p v-if="result.status === 'paid' && result.order_status === 'CONFIRMED'">Đơn hàng đang được xử lý.</p>
            <p v-if="orderDeferred" role="status">Thanh toán thành công nhưng đơn hàng #{{ result.order_code ?? result.order_id }} đang được nhà hàng xử lý (refund hoặc mở lại đơn). Nhà hàng sẽ liên hệ bạn nếu cần hoàn tiền.</p>
            <p v-if="result.status === 'failed'">Thanh toán không thành công. Vui lòng thử lại.</p>
            <p v-if="canPoll">Chưa ghi nhận thanh toán thành công. Nếu đã bị trừ tiền, bạn không cần đặt đơn mới; hãy giữ mã giao dịch để đối soát.</p>
            <dl>
                <div><dt>Đơn hàng</dt><dd>#{{ result.order_code ?? result.order_id }}</dd></div>
                <div><dt>Tổng tiền</dt><dd>{{ result.amount.toLocaleString('vi-VN') }} VNĐ</dd></div>
                <div v-if="result.transaction_id"><dt>Mã giao dịch</dt><dd>{{ result.transaction_id }}</dd></div>
                <div v-if="result.paid_at"><dt>Thời gian</dt><dd>{{ new Date(result.paid_at).toLocaleString('vi-VN') }}</dd></div>
            </dl>
        </template>
        <div class="payment-actions">
            <UiButton v-if="result?.can_retry" size="lg" aria-label="Thanh toán lại qua VNPAY" :loading="redirecting" :disabled="busy" @click="retry">Thanh toán lại</UiButton>
            <UiButton v-if="canPoll || (!result && !route.query.error && route.query.payment_code)" size="lg" variant="outline" :aria-label="busy ? 'Đang kiểm tra thanh toán' : 'Kiểm tra lại kết quả'" :loading="busy" :disabled="redirecting" @click="poller.refresh">Kiểm tra lại kết quả</UiButton>
            <RouterLink v-if="result" :to="{ name: 'order-detail', params: { id: result.order_id } }">Xem đơn hàng</RouterLink>
            <RouterLink v-else :to="{ name: 'account', query: { tab: 'orders' } }">Xem các đơn hàng</RouterLink>
            <RouterLink to="/">Về trang chủ</RouterLink>
        </div>
    </main>
</template>

<style scoped>
.payment-result { max-width: 720px; margin: 0 auto var(--space-10); padding: calc(var(--navbar-height) + var(--space-8)) var(--space-6) var(--space-6); font-size: var(--font-size-body); color: var(--color-text); }
h1 { font-size: var(--font-size-h3); margin-block: var(--space-4); }
p { margin-block: var(--space-4); line-height: var(--leading-body); }
.payment-label { color: var(--color-text-muted); }
.is-paid { color: var(--status-success-text); }
dl { padding: var(--space-6); background: var(--color-surface); border-radius: var(--radius-card); margin-block: var(--space-6); }
dl div { display: flex; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2); padding-block: var(--space-2); }
dt { color: var(--color-text-muted); }
dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.payment-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-4); }
a { display: inline-flex; align-items: center; min-height: 44px; padding: var(--space-2); text-decoration: underline; }
a:hover { color: var(--color-brand-primary); }
a:active { transform: translateY(1px); }
@media (max-width: 768px) { .payment-result { padding-top: calc(var(--navbar-height-mobile) + var(--space-6)); } }
</style>
