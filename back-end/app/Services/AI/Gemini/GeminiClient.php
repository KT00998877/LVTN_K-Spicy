<?php

namespace App\Services\AI\Gemini;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /** @return array{text:string, usage:array<string, mixed>, raw:array<string, mixed>} */
    public function generateText(string $prompt, array $options = []): array
    {
        $this->throwIfNotConfigured();
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => array_merge([
                'temperature' => 0.7,
                'maxOutputTokens' => 1024,
            ], $options),
        ];
        $url = sprintf(
            '%s/models/%s:generateContent',
            rtrim($this->baseUrl, '/'),
            rawurlencode($this->model),
        );
        $startedAt = microtime(true);
        $request = Http::timeout($this->timeout)
            ->withHeaders(['X-Goog-Api-Key' => $this->apiKey])
            ->acceptJson()
            ->asJson();
        $caBundle = storage_path('app/cacert.pem');
        if (is_file($caBundle)) {
            $request = $request->withOptions(['verify' => $caBundle]);
        }
        $response = $request->post($url, $payload);
        if ($response->failed()) {
            throw new RuntimeException("Gemini API call failed: HTTP {$response->status()}");
        }

        $body = $response->json();
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini trả về response không có text.');
        }

        return [
            'text' => $text,
            'usage' => $body['usageMetadata'] ?? [],
            'raw' => [
                'status' => $response->status(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'model' => $body['modelVersion'] ?? $this->model,
            ],
        ];
    }

    public function throwIfNotConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Gemini provider chưa được cấu hình. Hãy đặt GEMINI_API_KEY hoặc dùng AI_PROVIDER=mock.');
        }
    }
}
