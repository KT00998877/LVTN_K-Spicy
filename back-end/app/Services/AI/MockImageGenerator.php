<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ImageGenerator;

class MockImageGenerator implements ImageGenerator
{
    public function generate(array $context): array
    {
        $subject = (string) ($context['subject'] ?? 'Korean dish');
        $tone = strtoupper((string) ($context['tone'] ?? 'FUN'));
        $mood = match ($tone) {
            'WARM' => 'cozy',
            'PREMIUM' => 'premium_dark',
            'CASUAL' => 'casual_bright',
            'PUNCHY' => 'bold_red',
            default => 'warm_spicy',
        };

        return [
            'prompt' => "{$subject}, professional food photography, mood: {$mood}",
            'layout' => 'hero_shot',
            'mood' => $mood,
            'palette' => ['#E63946', '#F1B23F', '#FFF3E6'],
            'meta' => ['mock_seed' => substr(md5(json_encode($context) ?: ''), 0, 8)],
        ];
    }

    public function providerName(): string
    {
        return 'mock';
    }
}
