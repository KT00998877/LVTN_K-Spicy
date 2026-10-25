<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Contracts\CopyGenerator;
use App\Services\AI\MockCopyGenerator;
use Illuminate\Support\Facades\Log;

class GeminiCopyGenerator implements CopyGenerator
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly MockCopyGenerator $fallback,
    ) {}

    public function generate(array $context): array
    {
        if ((string) config('ai.providers.gemini.api_key', '') === '') {
            return $this->fallback->generate($context);
        }

        $prompt = $this->prompt($context);
        try {
            $result = $this->client->generateText($prompt, [
                'responseMimeType' => 'application/json',
            ]);
            $copy = json_decode($result['text'], true);
            if (!is_array($copy)) {
                throw new \RuntimeException('Gemini trả về JSON không hợp lệ.');
            }

            return [
                'headline' => (string) ($copy['headline'] ?? ''),
                'caption' => $copy['caption'] ?? null,
                'body' => $copy['body'] ?? null,
                'hashtags' => is_array($copy['hashtags'] ?? null) ? $copy['hashtags'] : [],
                'meta' => array_merge($copy['meta'] ?? [], ['provider_raw' => $result['raw']]),
                'prompt_used' => $prompt,
            ];
        } catch (\Throwable $exception) {
            Log::warning('GeminiCopyGenerator fallback to Mock', [
                'error' => $exception->getMessage(),
            ]);
            $copy = $this->fallback->generate($context);
            $copy['meta']['fallback_reason'] = $exception->getMessage();

            return $copy;
        }
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    private function prompt(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
Bạn là chuyên gia content marketing cho nhà hàng K-Spicy.
Viết bằng tiếng Việt, đúng dữ liệu trong context và không tự bịa giá hoặc khuyến mãi.
Chỉ trả về JSON hợp lệ theo schema:
{"headline":"string","caption":"string|null","body":"string|null","hashtags":["#tag"],"meta":{}}

Context:
{$json}
PROMPT;
    }
}
