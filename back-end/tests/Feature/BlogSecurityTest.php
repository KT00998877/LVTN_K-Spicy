<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Services\BlogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlogSecurityTest extends TestCase
{
    #[DataProvider('xssPayloads')]
    public function test_blog_sanitizer_removes_xss_payloads(string $payload): void
    {
        $cleaned = app(BlogService::class)->sanitizeHtml($payload);
        $normalized = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5));

        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $cleaned);
        $this->assertStringNotContainsString('javascript:', strtolower((string) $normalized));
        $this->assertStringNotContainsString('<script', strtolower($cleaned));
    }

    /** @return array<string, array{string}> */
    public static function xssPayloads(): array
    {
        return [
            'unquoted event handler' => ['<img src=x onerror=alert(1)>'],
            'spaced event handler' => ['<img src="x" onerror = alert(1)>'],
            'unquoted javascript URI' => ['<a href=javascript:alert(1)>x</a>'],
            'entity encoded scheme' => ['<a href="java&#x73;cript:alert(1)">x</a>'],
            'uppercase scheme' => ['<a href="JAVASCRIPT:alert(1)">x</a>'],
            'control character scheme' => ["<a href=\"java\x0Dscript:alert(1)\">x</a>"],
            'malformed markup' => ['<div><img src=x onerror=alert(1)//<p>safe'],
            'script element' => ['<script>alert(1)</script><p>safe</p>'],
        ];
    }

    public function test_blog_sanitizer_keeps_supported_safe_content(): void
    {
        $html = '<h2>Tiêu đề</h2><p><strong>Mì cay</strong> <a href="https://example.com/menu" target="_blank">menu</a></p>'
            .'<img src="https://example.com/noodle.jpg" alt="Tô mì">';

        $cleaned = app(BlogService::class)->sanitizeHtml($html);

        $this->assertStringContainsString('<h2>Tiêu đề</h2>', $cleaned);
        $this->assertStringContainsString('<strong>Mì cay</strong>', $cleaned);
        $this->assertStringContainsString('href="https://example.com/menu"', $cleaned);
        $this->assertStringContainsString('src="https://example.com/noodle.jpg"', $cleaned);
    }

    public function test_command_sanitizes_existing_blog_content(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        Schema::create('blogs', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->timestamps();
        });

        $blog = Blog::create([
            'title' => 'Bài cũ',
            'slug' => 'bai-cu',
            'content' => '<p>Nội dung</p><img src=x onerror=alert(1)>',
        ]);

        $this->artisan('blogs:sanitize-content')
            ->expectsOutput('1 blog post(s) updated.')
            ->assertSuccessful();

        $cleaned = (string) $blog->fresh()->content;
        $this->assertStringContainsString('<p>Nội dung</p>', $cleaned);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $cleaned);
    }
}
