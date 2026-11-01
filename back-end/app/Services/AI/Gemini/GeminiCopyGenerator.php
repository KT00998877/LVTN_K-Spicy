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
                'maxOutputTokens' => 4096,
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
Bạn là biên tập viên nội dung cho trang khách hàng của nhà hàng K-Spicy. Viết bài bằng tiếng Việt tự nhiên, giàu hình ảnh vị giác nhưng rõ ràng và đáng tin. Đây là bài đọc trên website, không phải caption mạng xã hội.

Yêu cầu đầu ra:
- headline: tiêu đề hấp dẫn, cụ thể, tối đa 80 ký tự; tránh giật tít và sáo ngữ.
- caption: tóm tắt 1–2 câu, 35–55 từ, đủ ý để hiển thị ở danh sách bài viết.
- body: bài viết Markdown 450–650 từ, có mở bài dẫn vào chủ đề, 2–3 mục với tiêu đề ##, các đoạn ngắn dễ đọc và đoạn kết mời khách khám phá thực đơn hoặc ghé quán. Chỉ đề cập món ăn, nguyên liệu, giá, ưu đãi, thời gian, địa chỉ khi context cung cấp thông tin đó. Không lặp lại cùng một ý để kéo dài bài.
- hashtags: mảng rỗng vì bài chỉ đăng trên website.
- Giữ giọng điệu theo tone trong context. Không tự bịa trải nghiệm khách hàng, đánh giá, xuất xứ nguyên liệu, khuyến mãi hoặc lời hứa sức khỏe.
- Không thêm lời giải thích bên ngoài JSON và không dùng HTML trong body.

Chỉ trả về JSON hợp lệ theo schema:
{"headline":"string","caption":"string|null","body":"string|null","hashtags":["#tag"],"meta":{}}

Context:
{$json}
PROMPT;
    }
}
