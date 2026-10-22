<?php

namespace App\Services\Payment;

use App\Models\{Order, Payment, User};
use App\Services\{Money, OnlineOrderService};
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use Illuminate\Support\Str;

class VNPayService
{
    public function paymentUrl(array $parameters): string
    {
        unset($parameters['vnp_SecureHash'], $parameters['vnp_SecureHashType']);
        ksort($parameters);

        // VNPAY 2.1.0 uses HMAC-SHA512 without the legacy SecureHashType field.
        return config('vnpay.url').'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC1738)
            .'&vnp_SecureHash='.$this->signature($parameters);
    }

    public function signature(array $parameters): string
    {
        unset($parameters['vnp_SecureHash'], $parameters['vnp_SecureHashType']);
        $parameters = array_filter($parameters, fn ($key) => str_starts_with($key, 'vnp_'), ARRAY_FILTER_USE_KEY);
        ksort($parameters);
        return hash_hmac('sha512', http_build_query($parameters, '', '&', PHP_QUERY_RFC1738), (string) config('vnpay.hash_secret'));
    }

    public function verified(array $data): bool
    {
        foreach ($data as $value) if (!is_string($value)) return false;
        return (bool) config('vnpay.hash_secret') && isset($data['vnp_SecureHash'])
            && hash_equals($this->signature($data), $data['vnp_SecureHash'])
            && ($data['vnp_TmnCode'] ?? '') === config('vnpay.tmn_code');
    }

    public function create(User $user, int $orderId, string $ip, string $platform = 'web'): Payment
    {
        abort_unless(config('vnpay.hash_secret') && config('vnpay.tmn_code'),
            503, 'VNPay chưa được cấu hình. Vui lòng liên hệ nhà hàng.');
        // Validate sandbox credentials (tránh throw "Chữ ký không hợp lệ" khó hiểu về phía khách).
        $tmn = (string) config('vnpay.tmn_code');
        if (str_contains($tmn, 'TEST_') || str_contains($tmn, 'PLACEHOLDER')) {
            abort(503, 'VNPay đang trong quá trình cấu hình. Vui lòng liên hệ nhà hàng để hoàn tất.');
        }
        $returnUrl = (string) config('services.vnpay.return_url_'.$platform, $platform === 'web' ? config('vnpay.return_url') : null);
        abort_unless($returnUrl, 503, 'VNPay return URL is not configured for this platform.');
        return DB::transaction(function () use ($user, $orderId, $ip, $returnUrl, $platform) {
            $order = Order::where('customer_id', $user->id)->whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->lockForUpdate()->findOrFail($orderId);
            abort_unless($order->status === 'PENDING' && $order->payment_method === 'PREPAID', 409, 'Đơn không đủ điều kiện thanh toán VNPay.');
            $invoice = app(OnlineOrderService::class)->invoice($order);
            abort_unless($invoice->status === 'UNPAID' && Money::minor($invoice->paid_amount) === 0, 409, 'Hóa đơn đã thanh toán hoặc cần đối soát.');
            $amount = Money::minor($invoice->total_amount);
            abort_unless($amount > 0, 422, 'Số tiền thanh toán phải lớn hơn 0.');
            $pending = Payment::where('invoice_id', $invoice->id)->where('provider', 'vnpay')->where('status', 'PENDING')->latest('id')->first();
            if ($pending) {
                $pendingPlatform = $pending->raw_response['platform'] ?? 'web';
                abort_if($pendingPlatform !== $platform, 409, 'A payment is already pending on another platform. Please complete or verify that payment first.');
                // An expired browser session is not proof that no money was collected.
                abort_if($pending->expired_at->isPast(), 409, 'Giao dịch hết thời gian chờ nhưng chưa có kết quả từ VNPay. Vui lòng chờ xác minh hoặc liên hệ nhà hàng.');
                // Repair URLs generated before the 2.1.0 fix without changing
                // the signed data, transaction reference or payment expiry.
                $pending->payment_url = str_replace('&vnp_SecureHashType=SHA512', '', $pending->payment_url);
                if ($pending->isDirty('payment_url')) $pending->save();
                return $pending;
            }
            $payment = Payment::create([
                'invoice_id' => $invoice->id, 'provider' => 'vnpay', 'method' => 'ONLINE_GATEWAY',
                'payment_code' => 'VNP'.str_replace('-', '', (string) Str::uuid()),
                'amount' => $invoice->total_amount, 'status' => 'PENDING', 'is_simulated' => false,
                'expired_at' => now()->addMinutes(15), 'processed_at' => now(),
                'raw_response' => ['platform' => $platform],
            ]);
            $data = [
                'vnp_Version' => '2.1.0', 'vnp_Command' => 'pay', 'vnp_TmnCode' => config('vnpay.tmn_code'),
                'vnp_Amount' => (string) $amount, 'vnp_CurrCode' => 'VND', 'vnp_TxnRef' => $payment->payment_code,
                'vnp_OrderInfo' => 'Thanh toan don '.$order->code, 'vnp_OrderType' => 'other', 'vnp_Locale' => 'vn',
                'vnp_ReturnUrl' => $returnUrl, 'vnp_IpAddr' => $ip,
                'vnp_CreateDate' => now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
                'vnp_ExpireDate' => $payment->expired_at->timezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
            ];
            $payment->payment_url = $this->paymentUrl($data);
            $payment->transaction_ref = $payment->payment_code;
            $payment->save();
            Log::info('VNPay create transaction', ['txn_ref' => $payment->payment_code]);
            return $payment;
        }, 3);
    }

    public function ipn(array $data): array
    {
        if (!$this->verified($data)) {
            Log::warning('VNPay signature invalid');
            return $this->reply('97', 'Invalid signature');
        }
        Log::info('VNPay IPN received', ['txn_ref' => $data['vnp_TxnRef'] ?? null]);
        return $this->applyResult($data);
    }

    /**
     * Settle a PENDING payment.
     *
     * Preferred source of truth is VNPAY's own querydr API, because it is a
     * server-to-server confirmation that does not depend on the browser. It is not
     * always reachable: the gateway can answer 5xx, reject the merchant IP, or the
     * whole endpoint can be down, and on localhost VNPay cannot call IPN at all.
     *
     * In that case we fall back to the return payload, which is only accepted when
     * its HMAC-SHA512 signature verifies against the merchant secret and the txn
     * reference and amount both match the stored payment. That signature is
     * produced by VNPAY with the shared secret, so an attacker cannot forge it;
     * editing query parameters on the return URL does not help because the hash
     * is recomputed over the payload.
     */
    public function reconcile(Payment $payment, ?array $callback = null, ?string $clientIp = null): void
    {
        if ($payment->provider !== 'vnpay' || $payment->status !== 'PENDING'
            || !config('vnpay.hash_secret') || !config('vnpay.query_url')) return;
        parse_str(parse_url((string) $payment->payment_url, PHP_URL_QUERY) ?: '', $original);
        if (empty($original['vnp_CreateDate']) || ($original['vnp_TmnCode'] ?? '') !== config('vnpay.tmn_code')) return;
        // Bound status polling and concurrent browser/IPN requests. Short window so a
        // failed query is retried quickly instead of blocking the result page.
        if (!Cache::add('vnpay:query:'.$payment->id, true, 5)) return;
        $request = [
            'vnp_RequestId' => str_replace('-', '', (string) Str::uuid()),
            'vnp_Version' => '2.1.0', 'vnp_Command' => 'querydr',
            'vnp_TmnCode' => config('vnpay.tmn_code'), 'vnp_TxnRef' => $payment->payment_code,
            'vnp_TransactionDate' => $original['vnp_CreateDate'],
            'vnp_CreateDate' => now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
            // VNPay rejects loopback/private addresses on querydr, which silently
            // dropped the connection and left every payment stuck in PENDING.
            // Fall back to the caller's real IP before the configured value.
            'vnp_IpAddr' => $this->queryIp($clientIp),
            'vnp_OrderInfo' => 'Truy van giao dich '.$payment->payment_code,
        ];
        $request['vnp_SecureHash'] = hash_hmac('sha512', implode('|', $request), (string) config('vnpay.hash_secret'));
        try {
            $response = $this->https()->connectTimeout(5)->timeout(10)
                ->asForm()->post(config('vnpay.query_url'), $request);
            $data = $response->json();
            if (!$response->successful() || !is_array($data) || !$this->verifiedQuery($data)
                || ($data['vnp_TxnRef'] ?? '') !== $payment->payment_code) {
                Log::warning('VNPay query response invalid', [
                    'payment_id' => $payment->id,
                    'txn_ref' => $payment->payment_code,
                    'http_status' => $response->status(),
                    'content_type' => $response->header('Content-Type')[0] ?? null,
                    'response_code' => is_array($data) ? ($data['vnp_ResponseCode'] ?? null) : null,
                    'response_message' => is_array($data) ? ($data['vnp_Message'] ?? null) : null,
                ]);
            } elseif (($data['vnp_ResponseCode'] ?? '') === '00'
                && in_array($data['vnp_TransactionStatus'] ?? '', ['00', '02'], true)
                && ($data['vnp_TransactionType'] ?? '') === '01') {
                // Only final payment results are applied. Query success alone does not mean paid.
                if ($data['vnp_TransactionStatus'] === '02') {
                    // querydr's ResponseCode describes the query, not the failed payment.
                    $data['vnp_ResponseCode'] = '02';
                    if ($callback && $this->verified($callback)
                        && ($callback['vnp_TxnRef'] ?? '') === $payment->payment_code
                        && ($callback['vnp_Amount'] ?? '') === ($data['vnp_Amount'] ?? '')
                        && ($callback['vnp_TransactionStatus'] ?? '') === '02'
                        && ($callback['vnp_ResponseCode'] ?? '') === '24') $data['vnp_ResponseCode'] = '24';
                }
                $this->applyResult($data);
                return;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Log the transport failure verbatim: this used to be swallowed as a
            // generic warning, which hid a loopback-IP rejection for months.
            Log::warning('VNPay query unavailable', [
                'payment_id' => $payment->id,
                'txn_ref' => $payment->payment_code,
                'vnp_IpAddr' => $request['vnp_IpAddr'],
                'query_url' => config('vnpay.query_url'),
                'error' => $e->getMessage(),
            ]);
        }

        // QueryDR could not confirm the payment (unreachable, 5xx, or an unverifiable
        // response). Fall back to the return payload when it is cryptographically
        // signed by VNPAY and matches the stored payment exactly.
        $this->applyVerifiedCallback($payment, $callback ?? $this->storedReturn($payment));
    }

    /**
     * The return payload captured earlier by the return handler.
     *
     * It is re-verified from scratch in applyVerifiedCallback(), so a tampered row
     * cannot settle a payment. Returning null when it has no signature keeps the
     * common path cheap.
     */
    private function storedReturn(Payment $payment): ?array
    {
        $stored = $payment->raw_response['return'] ?? null;
        return is_array($stored) && isset($stored['vnp_SecureHash']) ? $stored : null;
    }

    /**
     * Settle from the VNPAY return payload.
     *
     * Requires a signature that verifies against the merchant secret, a matching
     * transaction reference and a matching amount. Editing the return URL cannot
     * bypass this: any parameter change invalidates vnp_SecureHash.
     */
    private function applyVerifiedCallback(Payment $payment, ?array $callback): void
    {
        if (!$callback) {
            return;
        }
        if (!$this->verified($callback)) {
            Log::warning('VNPay return payload rejected', [
                'payment_id' => $payment->id,
                'txn_ref' => $payment->payment_code,
                'reason' => 'signature verification failed',
            ]);
            return;
        }
        if (($callback['vnp_TxnRef'] ?? '') !== $payment->payment_code) {
            Log::warning('VNPay return payload rejected', [
                'payment_id' => $payment->id,
                'reason' => 'vnp_TxnRef does not match the stored payment',
            ]);
            return;
        }
        if (($callback['vnp_Amount'] ?? '') !== (string) Money::minor($payment->amount)) {
            Log::warning('VNPay return payload rejected', [
                'payment_id' => $payment->id,
                'reason' => 'vnp_Amount does not match the stored payment amount',
            ]);
            return;
        }
        Log::info('VNPay settling from verified return payload (querydr unavailable)', [
            'payment_id' => $payment->id,
            'txn_ref' => $payment->payment_code,
            'response_code' => $callback['vnp_ResponseCode'] ?? null,
            'transaction_status' => $callback['vnp_TransactionStatus'] ?? null,
            'transaction_no' => $callback['vnp_TransactionNo'] ?? null,
        ]);
        $this->applyResult($callback);
    }

    /**
     * A cURL client that can actually verify TLS certificates.
     *
     * PHP on Windows frequently ships without a CA bundle, which makes every
     * HTTPS call fail with "cURL error 60: unable to get local issuer
     * certificate". When that happens VNPay reconciliation could never reach the
     * gateway, so payments stayed PENDING forever.
     *
     * A real php.ini setting always wins. Only when cURL has no bundle at all do
     * we fall back to the copy stored with the project, so production setups
     * that are configured properly are left untouched.
     */
    private function https(): PendingRequest
    {
        $client = Http::acceptJson();
        if (!\extension_loaded('curl')) {
            return $client;
        }
        $configured = (string) (\curl_version()['cainfo'] ?? '');
        if ($configured !== '' && is_file($configured)) {
            return $client;
        }
        $bundle = base_path('storage/app/cacert.pem');
        if (is_file($bundle)) {
            Log::info('Using project CA bundle for outbound HTTPS', ['cainfo' => $bundle]);
            // Guzzle rejects CURLOPT_CAINFO, so pass the bundle through "verify".
            return $client->withOptions(['verify' => $bundle]);
        }
        Log::warning('No CA bundle available for outbound HTTPS; TLS verification will fail.', [
            'php_ini' => PHP_CONFIG_FILE_PATH,
            'hint' => 'Download https://curl.se/ca/cacert.pem to storage/app/cacert.pem or set curl.cainfo in php.ini.',
        ]);
        return $client;
    }

    /**
     * VNPay drops querydr requests whose vnp_IpAddr is loopback or RFC1918. That
     * surfaced only as a ConnectionException, leaving every payment in PENDING.
     * Pick the first genuinely public address available; 0.0.0.0 lets VNPay fill
     * in the caller IP itself, which beats sending an unroutable one.
     */
    private function queryIp(?string $clientIp): string
    {
        foreach ([$clientIp, config('vnpay.server_ip')] as $candidate) {
            $ip = is_string($candidate) ? trim($candidate) : '';
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    private function verifiedQuery(array $data): bool
    {
        $fields = ['vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode',
            'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode', 'vnp_PayDate', 'vnp_TransactionNo',
            'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo', 'vnp_PromotionCode', 'vnp_PromotionAmount'];
        foreach ($data as $value) if (!is_string($value)) return false;
        $payload = implode('|', array_map(fn ($field) => $data[$field] ?? '', $fields));
        return !empty($data['vnp_SecureHash']) && ($data['vnp_TmnCode'] ?? '') === config('vnpay.tmn_code')
            && ($data['vnp_Command'] ?? 'querydr') === 'querydr'
            && hash_equals(hash_hmac('sha512', $payload, (string) config('vnpay.hash_secret')), $data['vnp_SecureHash']);
    }

    private function applyResult(array $data): array
    {
        if (!isset($data['vnp_ResponseCode'], $data['vnp_TransactionStatus'], $data['vnp_TransactionNo'])) return $this->reply('99', 'Missing result');
        $paid = $data['vnp_ResponseCode'] === '00' && $data['vnp_TransactionStatus'] === '00';
        $failed = $data['vnp_ResponseCode'] !== '00' && $data['vnp_TransactionStatus'] === '02';
        if (!$paid && !$failed) return $this->reply('99', 'Payment not final');
        if ($paid && !preg_match('/^[1-9][0-9]{0,14}$/D', $data['vnp_TransactionNo'])) return $this->reply('99', 'Invalid transaction');
        $payment = Payment::where('payment_code', $data['vnp_TxnRef'] ?? '')->where('provider', 'vnpay')->first();
        if (!$payment) return $this->reply('01', 'Order not found');
        $invoiceOrder = DB::table('invoices')->where('id', $payment->invoice_id)->value('order_id');
        $order = $invoiceOrder ? Order::whereKey($invoiceOrder)->first() : null;
        $invoice = $invoiceOrder ? DB::table('invoices')->where('id', $payment->invoice_id)->first() : null;
        if (!$order || !$invoice) return $this->reply('01', 'Order not found');
        if ((int) $payment->invoice_id !== (int) $invoice->id
            || !in_array($order->order_type, ['ONLINE', 'TAKEAWAY'], true)
            || $order->payment_method !== 'PREPAID'
            || (int) $invoice->customer_id !== (int) $order->customer_id) return $this->reply('01', 'Invalid order');
        if (($data['vnp_Amount'] ?? '') !== (string) Money::minor($payment->amount)
            || Money::minor($payment->amount) !== Money::minor($invoice->total_amount)) return $this->reply('04', 'Invalid amount');
        if ($payment->status !== 'PENDING') {
            Log::info('VNPay result already processed', ['txn_ref' => $payment->payment_code]);
            return $this->reply('02', 'Already confirmed');
        }
        $transactionId = preg_match('/^[1-9][0-9]{0,14}$/D', $data['vnp_TransactionNo']) ? $data['vnp_TransactionNo'] : null;
        if ($transactionId && Payment::where('provider', 'vnpay')->where('provider_transaction_id', $transactionId)
            ->whereKeyNot($payment->id)->exists()) return $this->reply('99', 'Transaction already used');

        // ================================================================
        // TẦNG 1 — Tài chính (commit độc lập, KHÔNG bị rollback bởi tầng 2).
        // Khi VNPay xác nhận SUCCESS, tiền THỰC SỰ đã được trừ từ khách.
        // Payment + Invoice PAID phải commit ngay để khách thấy "Thanh toán
        // thành công" mà không phụ thuộc vào việc nhà hàng xác nhận đơn.
        // ================================================================
        try {
            DB::transaction(function () use ($payment, $data, $paid, $transactionId, $order, $invoice) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $invoice = DB::table('invoices')->where('id', $invoice->id)->lockForUpdate()->first();
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($payment->status !== 'PENDING') return;
                abort_unless(Money::minor($payment->amount) === Money::minor($invoice->total_amount), 409);
                $payment->fill([
                    'status' => $paid ? 'SUCCESS' : 'FAILED',
                    'provider_transaction_id' => $transactionId,
                    'bank_code' => $data['vnp_BankCode'] ?? null,
                    'response_code' => $data['vnp_ResponseCode'],
                    'transaction_status' => $data['vnp_TransactionStatus'],
                    'paid_at' => $paid ? now() : null,
                    'processed_at' => now(),
                    // Keep the verified return payload alongside the settled summary:
                    // it is the only evidence available when querydr stays down, and
                    // it is re-verified from its signature before being reused.
                    'raw_response' => array_merge(
                        array_intersect_key($payment->raw_response ?? [], ['return' => true, 'platform' => true]),
                        array_intersect_key($data, array_flip([
                            'vnp_TxnRef', 'vnp_Amount', 'vnp_ResponseCode', 'vnp_TransactionStatus',
                            'vnp_TransactionNo', 'vnp_BankCode', 'vnp_PayDate',
                        ])),
                    ),
                ])->save();
                if ($paid) {
                    // Invoice PAID — chỉ khi thực sự UNPAID (tránh double-pay).
                    if ($invoice->status === 'UNPAID' && Money::minor($invoice->paid_amount) === 0) {
                        app(OnlineOrderService::class)->settleInvoice($order, $invoice);
                    }
                }
                app(OnlineOrderService::class)->event($order, null, $paid ? 'VNPAY_PAID' : 'VNPAY_FAILED');
                Log::info($paid ? 'VNPay payment successful' : 'VNPay payment failed', [
                    'txn_ref' => $payment->payment_code,
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                ]);
            }, 3);
        } catch (\Illuminate\Validation\ValidationException | \Symfony\Component\HttpKernel\Exception\HttpException | \Illuminate\Database\QueryException $e) {
            Log::error('VNPay financial settlement failed', ['payment_id' => $payment->id, 'error_type' => get_class($e), 'error' => $e->getMessage()]);
            return $this->reply('99', 'Requires reconciliation');
        }

        // ================================================================
        // TẦNG 2 — Vận hành (transaction RIÊNG, lỗi KHÔNG ảnh hưởng tầng 1).
        // Nếu order đã bị CANCELLED hoặc state không cho
        // phép confirm → log warning để admin xử lý, trả RspCode 00 để VNPay
        // không retry (vì payment đã settled thành công).
        // ================================================================
        if ($paid) {
            try {
                DB::transaction(function () use ($order) {
                    $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
                    if (!$fresh) return;
                    // Order đã CANCELLED hoặc staff đã xử lý → KHÔNG tự động confirm.
                    // Admin phải xử lý thủ công (refund hoặc reopen).
                    if ($fresh->status === 'CANCELLED') {
                        Log::warning('VNPay paid but order already CANCELLED — admin must refund', [
                            'order_id' => $fresh->id, 'order_code' => $fresh->code,
                        ]);
                        return;
                    }
                    if ($fresh->status !== 'PENDING') {
                        // Order đã được xử lý bởi staff (CONFIRMED, PREPARING, ...).
                        return;
                    }
                    // ONLINE requires staff approval with a selected shipper after payment.
                    if ($fresh->order_type === 'ONLINE') return;
                    app(OnlineOrderService::class)->confirm(null, $fresh->id);
                }, 3);
            } catch (\Throwable $e) {
                // KHÔNG rollback payment. Log để admin xử lý.
                Log::warning('VNPay order confirmation deferred — manual review needed', [
                    'order_id' => $order->id, 'error_type' => get_class($e), 'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->reply('00', 'Confirm Success');
    }

    private function reply(string $code, string $message): array
    {
        return ['RspCode' => $code, 'Message' => $message];
    }
}
