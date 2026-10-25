<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\CopyGenerator;
use App\Services\AI\Contracts\ImageGenerator;
use App\Services\AI\Gemini\GeminiCopyGenerator;
use App\Services\AI\Gemini\GeminiImageGenerator;
use InvalidArgumentException;

class AiManager
{
    private string $defaultProvider;

    public function __construct()
    {
        $this->defaultProvider = (string) config('ai.default', 'mock');
    }

    public function copy(): CopyGenerator
    {
        return $this->resolve([
            'mock' => MockCopyGenerator::class,
            'gemini' => GeminiCopyGenerator::class,
        ], 'copy');
    }

    public function image(): ImageGenerator
    {
        return $this->resolve([
            'mock' => MockImageGenerator::class,
            'gemini' => GeminiImageGenerator::class,
        ], 'image');
    }

    public function providerName(): string
    {
        return $this->defaultProvider;
    }

    /** @param array<string, class-string> $providers */
    private function resolve(array $providers, string $kind): object
    {
        $class = $providers[$this->defaultProvider] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException(
                "AI provider '{$this->defaultProvider}' chưa được đăng ký cho {$kind}()."
            );
        }

        return app($class);
    }
}
