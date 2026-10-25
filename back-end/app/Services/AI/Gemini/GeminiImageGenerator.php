<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Contracts\ImageGenerator;
use App\Services\AI\MockImageGenerator;

class GeminiImageGenerator implements ImageGenerator
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly MockImageGenerator $fallback,
    ) {}

    public function generate(array $context): array
    {
        $brief = $this->fallback->generate($context);
        $brief['meta']['provider_suggested'] = 'gemini-imagen-pending';

        return $brief;
    }

    public function providerName(): string
    {
        return 'gemini';
    }
}
