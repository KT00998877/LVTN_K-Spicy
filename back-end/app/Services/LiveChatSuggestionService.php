<?php

namespace App\Services;

use App\Models\LiveChatMessage;
use App\Models\LiveChatQuickReply;
use App\Models\LiveChatSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LiveChatSuggestionService — gợi ý câu trả lời nhanh cho nhân viên.
 *
 * Hai nguồn gợi ý, theo thứ tự ưu tiên:
 *
 *  1. AI: đọc lời khách vừa gửi và đề xuất câu trả lời phù hợp ngữ cảnh.
 *  2. Mẫu trong DB (`live_chat_quick_replies`): luôn có sẵn để nhân viên bấm,
 *     kể cả khi dịch vụ AI gián đoạn.
 *
 * Gợi ý KHÔNG bao giờ tự động gửi cho khách — nhân viên đọc và chịu trách
 * nhiệm về câu đã gửi.
 */
class LiveChatSuggestionService
{
    private string $aiServiceUrl;
    private string $aiServiceToken;
    private int $aiServiceTimeout;

    /** Số gợi ý AI tối đa. */
    private const MAX_AI_SUGGESTIONS = 3;

    /** Số mẫu DB tối đa khi fallback. */
    private const MAX_TEMPLATE_SUGGESTIONS = 3;

    public function __construct()
    {
        // config() thay vì env() để giá trị vẫn đúng khi config:cache.
        $this->aiServiceUrl = (string) config('rag.api_url');
        $this->aiServiceToken = (string) config('rag.internal_key');
        $this->aiServiceTimeout = (int) config('rag.timeout', 75);
    }

    /**
     * Gợi ý cho một phiên.
     *
     * @return array{suggestions: list<string>, source: string}
     */
    public function suggest(LiveChatSession $session): array
    {
        $lastCustomerMessage = LiveChatMessage::where('session_id', $session->id)
            ->where('sender_type', 'customer')
            ->orderByDesc('id')
            ->first();

        if (!$lastCustomerMessage) {
            // Chưa có lượt nào của khách → chỉ có mẫu mặc định.
            return [
                'suggestions' => $this->templateSuggestions(),
                'source' => 'template',
            ];
        }

        $ai = $this->askAi($session, $lastCustomerMessage);

        if ($ai !== null && $ai !== []) {
            return [
                'suggestions' => $ai,
                'source' => 'ai',
            ];
        }

        return [
            'suggestions' => $this->templateSuggestions(),
            'source' => 'template',
        ];
    }

    /**
     * Gọi AI Service xin gợi ý.
     *
     * @return list<string>|null null khi AI không dùng được — caller dùng mẫu DB.
     */
    private function askAi(LiveChatSession $session, LiveChatMessage $lastCustomerMessage): ?array
    {
        if (!$this->aiServiceUrl || !$this->aiServiceToken) {
            return null;
        }

        $payload = [
            'session_id' => 'live_chat_' . $session->id,
            'message' => $lastCustomerMessage->content,
            'messages' => $this->history($session),
        ];

        try {
            $response = Http::connectTimeout(3)->timeout(min($this->aiServiceTimeout, 30))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Internal-Token' => $this->aiServiceToken,
                ])
                ->post(rtrim($this->aiServiceUrl, '/') . '/api/v1/chat/quick-replies', $payload);
        } catch (\Throwable $e) {
            Log::warning('Live chat suggestions AI call failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning('Live chat suggestions AI error', [
                'session_id' => $session->id,
                'status' => $response->status(),
            ]);
            return null;
        }

        $data = $response->json();
        $suggestions = $data['suggestions'] ?? null;

        if (!is_array($suggestions)) {
            return null;
        }

        $clean = [];
        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion)) {
                continue;
            }
            $suggestion = trim($suggestion);
            if ($suggestion !== '') {
                $clean[] = mb_substr($suggestion, 0, 300);
            }
        }

        return array_slice($clean, 0, self::MAX_AI_SUGGESTIONS);
    }

    /**
     * Lịch sử hội thoại gửi kèm để AI hiểu ngữ cảnh.
     *
     * Chỉ `customer` và `staff` được giữ. Tin nhắn hệ thống (`system`) không
     * phải lượt nói của ai và sẽ làm AI hiểu sai bối cảnh.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(LiveChatSession $session): array
    {
        $messages = LiveChatMessage::where('session_id', $session->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        return $messages->map(fn ($message) => [
            'role' => $message->sender_type === 'staff' ? 'assistant' : 'user',
            'content' => $message->content,
        ])->all();
    }

    /**
     * Mẫu câu trả lời lấy từ DB.
     *
     * @return list<string>
     */
    private function templateSuggestions(): array
    {
        return LiveChatQuickReply::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_TEMPLATE_SUGGESTIONS)
            ->pluck('content')
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->values()
            ->all();
    }
}
