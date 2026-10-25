<?php

return [
    'default' => env('AI_PROVIDER', 'mock'),
    'cache_ttl' => (int) env('AI_CACHE_TTL', 86400),
    'rate_limit_per_hour' => (int) env('AI_RATE_LIMIT_PER_HOUR', 100),
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'providers' => [
        'mock' => [
            'enabled' => true,
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', ''),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],
    ],
];
