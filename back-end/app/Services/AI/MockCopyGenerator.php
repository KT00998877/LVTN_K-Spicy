<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\CopyGenerator;

class MockCopyGenerator implements CopyGenerator
{
    private const TEMPLATES = [
        'FUN' => [
            'headline' => '🔥 {SUBJECT} – cay không thể cưỡng!',
            'caption' => "Cay nồng {SUBJECT} – đậm đà chuẩn vị Hàn.\n{PRICE_LINE}Đến ngay hôm nay nhé 🌶️",
        ],
        'WARM' => [
            'headline' => 'Hôm nay ăn gì – {SUBJECT} ấm lòng',
            'caption' => "Một phần {SUBJECT} nóng hổi cho ngày mới.\n{PRICE_LINE}Đợi bạn tại {BRAND}.",
        ],
        'PREMIUM' => [
            'headline' => 'Trải nghiệm {SUBJECT} đẳng cấp',
            'caption' => "Tinh hoa ẩm thực Hàn Quốc – {SUBJECT} chuẩn vị.\n{PRICE_LINE}{STORE_INFO}",
        ],
        'CASUAL' => [
            'headline' => '{SUBJECT} – hôm nay gọi gì?',
            'caption' => "{SUBJECT}. {PRICE_LINE}Ăn thôi!",
        ],
        'PUNCHY' => [
            'headline' => '{SUBJECT}. Thử ngay.',
            'caption' => "Không cần suy nghĩ. {SUBJECT}.\n{PRICE_LINE}{CAMPAIGN}",
        ],
    ];

    public function generate(array $context): array
    {
        $subject = trim((string) ($context['subject'] ?? 'Món ngon K-Spicy')) ?: 'Món ngon K-Spicy';
        $tone = strtoupper((string) ($context['tone'] ?? 'FUN'));
        $brand = (string) ($context['brand'] ?? 'K-Spicy');
        $campaign = (string) ($context['campaign'] ?? '');
        $storeInfo = (string) ($context['store_info'] ?? '');
        $priceLine = isset($context['price'])
            ? 'Chỉ '.number_format((float) $context['price'], 0, ',', '.')."đ!\n"
            : '';
        $template = self::TEMPLATES[$tone] ?? self::TEMPLATES['FUN'];
        $replacements = [
            '{SUBJECT}' => $subject,
            '{PRICE_LINE}' => $priceLine,
            '{BRAND}' => $brand,
            '{CAMPAIGN}' => $campaign,
            '{STORE_INFO}' => $storeInfo,
        ];
        $headline = strtr($template['headline'], $replacements);
        $caption = strtr($template['caption'], $replacements);
        if ($campaign !== '') {
            $caption .= "\n\n🎯 {$campaign}";
        }

        $body = "# {$subject}\n\n**Thương hiệu:** {$brand}";
        if (isset($context['price'])) {
            $body .= "\n**Giá:** ".number_format((float) $context['price'], 0, ',', '.').'đ';
        }
        if ($storeInfo !== '') {
            $body .= "\n\n{$storeInfo}";
        }

        return [
            'headline' => $headline,
            'caption' => $caption,
            'body' => $body,
            'hashtags' => $this->hashtags($subject.' '.$campaign),
            'meta' => [
                'tokens_estimated' => (int) max(1, ceil(mb_strlen($headline.$caption.$body) / 1.5)),
                'latency_ms' => 0,
                'mock_seed' => substr(md5(json_encode($context) ?: ''), 0, 8),
            ],
            'prompt_used' => '[MOCK] context keys: '.implode(', ', array_keys($context)),
        ];
    }

    public function providerName(): string
    {
        return 'mock';
    }

    /** @return array<int, string> */
    private function hashtags(string $text): array
    {
        $text = mb_strtolower($text);
        if (str_contains($text, 'khai trương')) return ['#KhaiTruong', '#UuDai', '#KSpicy'];
        if (str_contains($text, 'tokbokki')) return ['#Tokbokki', '#BanhGaoCay', '#HanQuoc'];
        if (str_contains($text, 'kimbap')) return ['#Kimbap', '#CuonHanQuoc', '#KSpicy'];
        if (str_contains($text, 'mì cay') || str_contains($text, 'mi cay')) return ['#MiCay', '#HanQuoc', '#KSpicy'];

        return ['#KSpicy', '#AnVui', '#AmThucHanQuoc'];
    }
}
