<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\{Money, Payment\VNPayService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};

class VNPayController extends Controller
{
    public function create(Request $request, VNPayService $service)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'platform' => ['nullable', 'string', 'in:web,mobile'],
        ]);
        $platform = $data['platform'] ?? 'web';
        $payment = $service->create($request->user(), $data['order_id'], $request->ip(), $platform);
        return response()->json(['success' => true, 'payment_url' => $payment->payment_url, 'payment_code' => $payment->payment_code]);
    }

    public function ipn(Request $request, VNPayService $service)
    {
        return response()->json($service->ipn($request->query()));
    }

    public function returnUrl(Request $request, VNPayService $service)
    {
        $data = $request->query();
        $verified = $service->verified($data);
        $reference = $data['vnp_TxnRef'] ?? null;
        $payment = is_string($reference)
            ? Payment::where('payment_code', $reference)->where('provider', 'vnpay')->first()
            : null;
        // Metadata may select the destination; only a verified result can settle.
        if (!$verified) return $this->invalidReturn('invalid_signature', null, $payment);
        if (!$payment) return $this->invalidReturn('invalid_payment');
        // Amount check phải so sánh với PAYMENT.amount, không chỉ invoice.
        if (($data['vnp_Amount'] ?? '') !== (string) Money::minor($payment->amount)) return $this->invalidReturn('invalid_amount', null, $payment);

        // Trace of what VNPay actually returned, so a stuck payment can be diagnosed
        // from the log alone. No secret material is logged.
        Log::info('VNPay return received', [
            'payment_id' => $payment->id,
            'txn_ref' => $data['vnp_TxnRef'] ?? null,
            'transaction_no' => $data['vnp_TransactionNo'] ?? null,
            'response_code' => $data['vnp_ResponseCode'] ?? null,
            'transaction_status' => $data['vnp_TransactionStatus'] ?? null,
            'bank_code' => $data['vnp_BankCode'] ?? null,
            'amount' => $data['vnp_Amount'] ?? null,
            'signature_valid' => true,
            'payment_status_before' => $payment->status,
        ]);

        // Persist the signed return payload. When querydr stays unavailable (local
        // dev, gateway outage, un-whitelisted IP) this lets the status poller and the
        // scheduled reconcile still settle the payment later without trusting the
        // browser: the stored payload is re-verified before it is ever applied.
        DB::transaction(function () use ($payment, $data) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['raw_response' => array_merge(
                $locked->raw_response ?? [],
                ['return' => array_filter($data, fn ($key) => str_starts_with($key, 'vnp_'), ARRAY_FILTER_USE_KEY)],
            )])->save();
        });
        $payment->refresh();

        // Nếu payment đã settled (SUCCESS/REFUNDED) thì KHÔNG query lại VNPay server.
        // Idempotent: refresh trang return nhiều lần vẫn cho cùng kết quả.
        if ($payment->status !== 'PENDING') {
            return $this->redirectToFrontend($payment);
        }

        // Query VNPAY server để xác nhận (không tin browser status).
        $service->reconcile($payment, $data, $request->ip());
        $payment->refresh();

        // Sau khi reconcile, kiểm tra lại trạng thái.
        // applyResult() giờ tách 2 tầng: tầng tài chính (payment + invoice) commit
        // độc lập, tầng vận hành (order confirmation) chạy transaction riêng.
        // Nên khi VNPay SUCCESS → payment.status = SUCCESS ngay cả khi order
        // confirmation bị defer. Khi VNPay FAILED → payment.status = FAILED.
        // Pending is not failure: the destination queries backend status again.
        if ($payment->status === 'PENDING') {
            Log::warning('VNPay payment still PENDING after reconciliation', [
                'payment_id' => $payment->id,
                'txn_ref' => $payment->payment_code,
                'response_code' => $data['vnp_ResponseCode'] ?? null,
                'transaction_status' => $data['vnp_TransactionStatus'] ?? null,
                'expired_at' => $payment->expired_at,
            ]);
            return $this->redirectToFrontend($payment);
        }

        Log::info('VNPay return resolved', [
            'payment_id' => $payment->id,
            'payment_status' => $payment->status,
            'response_code' => $payment->response_code,
            'transaction_status' => $payment->transaction_status,
        ]);

        return $this->redirectToFrontend($payment);
    }

    public function status(Request $request, string $code, VNPayService $service)
    {
        $payment = Payment::where('payment_code', $code)->where('provider', 'vnpay')->firstOrFail();
        $invoice = DB::table('invoices')->where('id', $payment->invoice_id)->where('customer_id', $request->user()->id)->first();
        abort_unless($invoice, 404);
        $service->reconcile($payment, null, $request->ip());
        $payment->refresh();
        $status = match ($payment->status) {
            'SUCCESS' => 'paid', 'REFUNDED' => 'refunded',
            'FAILED' => $payment->response_code === '24' ? 'cancelled' : 'failed',
            default => $payment->expired_at?->isPast() ? 'expired' : 'pending',
        };
        $order = DB::table('orders')->where('id', $invoice->order_id)->first();
        $message = match ($status) {
            'paid' => 'Thanh toán thành công',
            'cancelled' => 'Bạn đã hủy thanh toán.',
            'failed' => 'Thanh toán không thành công. Vui lòng thử lại.',
            'refunded' => 'Giao dịch đã hoàn tiền.',
            default => 'Thanh toán đang được xác nhận. Chưa ghi nhận thanh toán thành công.',
        };
        $result = ['status' => $status, 'payment_status' => $status, 'order_id' => $invoice->order_id,
            'order_code' => $order?->code, 'order_status' => $order?->status,
            'amount' => (float) $payment->amount, 'transaction_id' => $payment->provider_transaction_id,
            'paid_at' => $payment->paid_at,
            'can_retry' => $payment->status === 'FAILED' && $order?->status === 'PENDING'
                && !Payment::where('invoice_id', $payment->invoice_id)->whereIn('status', ['PENDING', 'SUCCESS', 'REFUNDED'])->exists()];
        // Keep flat fields for existing mobile clients while documenting the data envelope.
        return response()->json([...$result, 'success' => $status === 'paid', 'message' => $message, 'data' => $result])
            ->header('Cache-Control', 'no-store, private');
    }

    private function invalidReturn(string $reason, ?string $paymentCode = null, ?Payment $payment = null)
    {
        if ($payment && ($payment->raw_response['platform'] ?? 'web') === 'mobile') {
            return $this->redirectToFrontend($payment);
        }
        $query = 'error='.$reason;
        // Carry the payment code so the result page can keep polling and switch to
        // the success view by itself once the payment is actually confirmed.
        if ($paymentCode !== null && $paymentCode !== '') {
            $query .= '&payment_code='.urlencode($paymentCode);
        }
        return redirect(rtrim(config('vnpay.frontend_url'), '/').'/payment/vnpay/result?'.$query);
    }

    /**
     * Redirect sau khi backend đã xác minh kết quả thanh toán.
     * Hỗ trợ cả web (trang Vue) và mobile (deep link Flutter).
     */
    private function redirectToFrontend(Payment $payment)
    {
        $invoiceOrder = DB::table('invoices')->where('id', $payment->invoice_id)->value('order_id');

        // Mobile deep link: redirect về app Flutter thay vì trang web
        if (($payment->raw_response['platform'] ?? 'web') === 'mobile') {
            $scheme = (string) config('vnpay.mobile_scheme', 'micay');
            $url = sprintf(
                '%s://payment/return?code=%s&order_id=%d',
                $scheme,
                urlencode($payment->payment_code),
                (int) $invoiceOrder,
            );
            return redirect($url);
        }

        return redirect(rtrim(config('vnpay.frontend_url'), '/').'/payment/vnpay/result?payment_code='.urlencode($payment->payment_code));
    }
}
