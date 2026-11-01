<?php

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    // Flutter Web dev server uses a different local port on each run.
    'allowed_origins_patterns' => ['#^http://(?:localhost|127\.0\.0\.1):[0-9]+$#'],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Chat-Guest-Token',
        'X-Requested-With',
        'X-Socket-ID',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
