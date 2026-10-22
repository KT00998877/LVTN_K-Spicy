<?php

// AI Service (FastAPI) configuration.
//
// The chatbot proxies user turns to the AI Service on port 8001.
// Keep the default aligned with ai-service/app.py (uvicorn port 8001) —
// port 8000 is Laravel itself and would loop the request back into this app.
return [
    // RAG config is managed by AI-service, not Laravel.
    'enabled' => env('AI_RAG_ENABLED', true),

    // Base URL of the AI Service, without a trailing slash.
    'api_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),

    // Shared secret sent as X-Internal-Token; must match
    // AI_SERVICE_INTERNAL_KEY in ai-service/.env.
    'internal_key' => env('AI_SERVICE_INTERNAL_KEY', ''),

    // Generous timeout: a Gemini turn with RAG + function calling can take
    // several seconds, and the Laravel HTTP client must outlast it.
    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 75),
];