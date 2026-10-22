<?php

$url = 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['vnp_TxnRef' => 'probe']),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
echo 'errno       = '.curl_errno($ch)."\n";
echo 'error       = '.curl_error($ch)."\n";
echo 'http_code   = '.curl_getinfo($ch, CURLINFO_HTTP_CODE)."\n";
echo 'ssl_verify  = '.curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT)."\n";
echo 'cainfo      = '.var_export(curl_getinfo($ch, CURLINFO_CAINFO), true)."\n";
echo 'body        = '.substr((string) $body, 0, 200)."\n";
curl_close($ch);
