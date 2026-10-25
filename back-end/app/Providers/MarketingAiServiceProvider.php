<?php

namespace App\Providers;

use App\Services\AI\Gemini\GeminiClient;
use App\Services\AI\Gemini\GeminiCopyGenerator;
use App\Services\AI\Gemini\GeminiImageGenerator;
use App\Services\AI\MockCopyGenerator;
use App\Services\AI\MockImageGenerator;
use Illuminate\Support\ServiceProvider;

class MarketingAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, fn () => new GeminiClient(
            apiKey: (string) config('ai.providers.gemini.api_key', ''),
            model: (string) config('ai.providers.gemini.model', 'gemini-2.0-flash'),
            baseUrl: (string) config('ai.providers.gemini.base_url'),
            timeout: (int) config('ai.timeout', 30),
        ));
        $this->app->bind(GeminiCopyGenerator::class, fn ($app) => new GeminiCopyGenerator(
            $app->make(GeminiClient::class),
            $app->make(MockCopyGenerator::class),
        ));
        $this->app->bind(GeminiImageGenerator::class, fn ($app) => new GeminiImageGenerator(
            $app->make(GeminiClient::class),
            $app->make(MockImageGenerator::class),
        ));
    }
}
