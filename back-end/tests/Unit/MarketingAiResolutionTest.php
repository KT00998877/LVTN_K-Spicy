<?php

namespace Tests\Unit;

use App\Services\AI\AiManager;
use App\Services\AI\Gemini\GeminiClient;
use App\Services\AI\Gemini\GeminiCopyGenerator;
use App\Services\AI\MockCopyGenerator;
use App\Services\Marketing\MarketingAssetService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingAiResolutionTest extends TestCase
{
    public function test_marketing_ai_dependencies_resolve_and_generate_copy(): void
    {
        config(['ai.default' => 'mock']);

        $service = $this->app->make(MarketingAssetService::class);
        $manager = $this->app->make(AiManager::class);
        $copy = $manager->copy()->generate([
            'subject' => 'Mì cay hải sản',
            'tone' => 'FUN',
        ]);

        $this->assertInstanceOf(MarketingAssetService::class, $service);
        $this->assertInstanceOf(MockCopyGenerator::class, $manager->copy());
        $this->assertNotEmpty($copy['headline']);
    }

    public function test_gemini_key_is_sent_in_a_header_not_in_the_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"headline":"ok"}']]],
                ]],
            ]),
        ]);

        $client = new GeminiClient(
            'TEST_KEY',
            'gemini-2.5-flash',
            'https://generativelanguage.googleapis.com/v1beta',
            5,
        );
        $client->generateText('test');

        Http::assertSent(fn (Request $request) =>
            !str_contains($request->url(), 'TEST_KEY')
            && $request->hasHeader('X-Goog-Api-Key', 'TEST_KEY')
        );
    }

    public function test_website_copy_requests_a_long_structured_article(): void
    {
        config(['ai.providers.gemini.api_key' => 'TEST_KEY']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"headline":"Mì cay","caption":"Giới thiệu","body":"## Hương vị\\nNội dung","hashtags":[]}']]],
            ]],
        ])]);

        $generator = new GeminiCopyGenerator(
            new GeminiClient('TEST_KEY', 'gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta', 5),
            new MockCopyGenerator,
        );
        $copy = $generator->generate(['subject' => 'Mì cay']);

        $this->assertSame('Mì cay', $copy['headline']);
        Http::assertSent(fn (Request $request) =>
            $request['generationConfig']['maxOutputTokens'] === 4096
            && str_contains($request['contents'][0]['parts'][0]['text'], '450–650 từ')
        );
    }
}
