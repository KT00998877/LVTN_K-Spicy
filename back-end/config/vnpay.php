<?php

return [
    'tmn_code' => env('VNP_TMN_CODE'),
    'hash_secret' => env('VNP_HASH_SECRET'),
    'url' => env('VNP_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
    'query_url' => env('VNP_QUERY_URL', str_contains(env('VNP_URL', 'https://sandbox.vnpayment.vn'), 'sandbox.vnpayment.vn')
        ? 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'
        : 'https://vnpayment.vn/merchant_webapi/api/transaction'),
    'server_ip' => env('VNP_SERVER_IP'),
    'return_url' => env('VNP_RETURN_URL'),
    'ipn_url' => env('VNP_IPN_URL'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
|--------------------------------------------------------------------------
| Mobile Deep Link — Flutter app đăng ký scheme này để nhận VNPay return.
| Android & iOS sẽ mở app khi user redirect tới `<scheme>://payment/return`.
|--------------------------------------------------------------------------
*/
    'mobile_scheme' => env('MOBILE_DEEP_LINK_SCHEME', 'micay'),
];
