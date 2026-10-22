<?php

// Read-only sandbox connectivity probe. Never logs credentials or changes payment records.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payment = App\Models\Payment::where('provider', 'vnpay')->latest('id')->first();
if (!$payment || parse_url(config('vnpay.query_url'), PHP_URL_HOST) !== 'sandbox.vnpayment.vn') {
    echo "Skipped: requires a sandbox endpoint and an existing VNPAY payment.\n";
    exit(1);
}
parse_str(parse_url($payment->payment_url, PHP_URL_QUERY) ?: '', $original);
$data = [
    'vnp_RequestId' => str_replace('-', '', (string) Illuminate\Support\Str::uuid()),
    'vnp_Version' => '2.1.0', 'vnp_Command' => 'querydr', 'vnp_TmnCode' => config('vnpay.tmn_code'),
    'vnp_TxnRef' => $payment->payment_code, 'vnp_TransactionDate' => $original['vnp_CreateDate'] ?? '',
    'vnp_CreateDate' => now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
    'vnp_IpAddr' => config('vnpay.server_ip'), 'vnp_OrderInfo' => 'Truy van giao dich '.$payment->payment_code,
];
$data['vnp_SecureHash'] = hash_hmac('sha512', implode('|', $data), (string) config('vnpay.hash_secret'));
try {
    $response = Illuminate\Support\Facades\Http::connectTimeout(3)->timeout(8)->post(config('vnpay.query_url'), $data);
    $result = $response->json();
    echo json_encode(['http_status' => $response->status(),
        'response_code' => $result['vnp_ResponseCode'] ?? null,
        'transaction_status' => $result['vnp_TransactionStatus'] ?? null,
        'database_modified' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Illuminate\Http\Client\ConnectionException $e) {
    preg_match('/cURL error (\d+)/', $e->getMessage(), $matches);
    echo json_encode(['connection' => 'failed', 'curl_error' => $matches[1] ?? null, 'database_modified' => false]).PHP_EOL;
    exit(1);
}
