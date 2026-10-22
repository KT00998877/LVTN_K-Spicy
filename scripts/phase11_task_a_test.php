<?php
/**
 * Test Phase 11 Task A: tool loop end-to-end.
 *
 * Gọi thẳng AI service /v1/chat với prompt mẫu → xem narrative có data thật từ
 * Laravel /api/home/categories hay không.
 */
$aiUrl = 'http://127.0.0.1:8001';
$token = 'phase11-test-token-12345';

function call_ai($url, $token, $payload) {
    $ch = curl_init($url . '/v1/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp];
}

echo "=== Test 1: Hỏi menu (tool: home_get_categories) ===" . PHP_EOL;
$payload = [
    'messages' => [
        ['role' => 'user', 'content' => 'Quán có những món gì?'],
    ],
    'user_id' => null,
    'session_id' => null,
    'request_id' => 'test-tool-loop-001',
];
$r = call_ai($aiUrl, $token, $payload);
echo "HTTP {$r['code']}" . PHP_EOL;
$data = json_decode($r['body'], true);
if (isset($data['narrative'])) {
    echo "Intent: " . ($data['intent'] ?? 'n/a') . PHP_EOL;
    echo "Tool calls: " . json_encode($data['tool_calls'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "Hops: " . ($data['model_hops'] ?? 'n/a') . PHP_EOL;
    echo "Tool traces: " . count($data['tool_traces'] ?? []) . PHP_EOL;
    foreach ($data['tool_traces'] ?? [] as $t) {
        echo "  - {$t['tool']}: HTTP {$t['http_status']} success=" . ($t['success'] ? 'true' : 'false') . " ({$t['latency_ms']}ms)" . PHP_EOL;
        if (!empty($t['data_preview'])) {
            echo "    preview: " . substr($t['data_preview'], 0, 150) . "..." . PHP_EOL;
        }
    }
    echo "Narrative: " . substr($data['narrative'], 0, 400) . PHP_EOL;
    echo "Latency: " . ($data['latency_ms'] ?? 'n/a') . "ms" . PHP_EOL;
    echo "Tokens: in=" . ($data['input_tokens'] ?? 0) . " out=" . ($data['output_tokens'] ?? 0) . PHP_EOL;
} else {
    echo "Body: " . substr($r['body'], 0, 500) . PHP_EOL;
}

echo PHP_EOL . "=== Test 2: Hỏi giỏ hàng (tool: cart_index - auth required, expect blocked) ===" . PHP_EOL;
$payload2 = [
    'messages' => [
        ['role' => 'user', 'content' => 'Giỏ hàng của tôi có gì?'],
    ],
    'request_id' => 'test-tool-loop-002',
];
$r = call_ai($aiUrl, $token, $payload2);
echo "HTTP {$r['code']}" . PHP_EOL;
$data = json_decode($r['body'], true);
echo "Intent: " . ($data['intent'] ?? 'n/a') . PHP_EOL;
echo "Narrative: " . substr($data['narrative'] ?? '(empty)', 0, 300) . PHP_EOL;
echo "Tool traces count: " . count($data['tool_traces'] ?? []) . PHP_EOL;

echo PHP_EOL . "=== Test 3: Hỏi thông tin nhà hàng (tool: home_get_restaurant) ===" . PHP_EOL;
$payload3 = [
    'messages' => [
        ['role' => 'user', 'content' => 'Cho mình biết địa chỉ nhà hàng với giờ mở cửa?'],
    ],
    'request_id' => 'test-tool-loop-003',
];
$r = call_ai($aiUrl, $token, $payload3);
echo "HTTP {$r['code']}" . PHP_EOL;
$data = json_decode($r['body'], true);
echo "Intent: " . ($data['intent'] ?? 'n/a') . PHP_EOL;
echo "Tool calls: " . json_encode($data['tool_calls'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "Narrative: " . substr($data['narrative'] ?? '(empty)', 0, 300) . PHP_EOL;

echo PHP_EOL . "=== DONE ===" . PHP_EOL;
