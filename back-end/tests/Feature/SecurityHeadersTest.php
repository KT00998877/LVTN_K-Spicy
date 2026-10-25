<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_security-headers-test', fn () => response()->json(['ok' => true]));
    }

    public function test_api_responses_include_security_headers(): void
    {
        $this->getJson('/api/_security-headers-test')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'")
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
    }

    public function test_cors_does_not_allow_arbitrary_origins(): void
    {
        $trustedOrigin = config('cors.allowed_origins.0');
        $this->assertIsString($trustedOrigin);
        $this->assertNotSame('*', $trustedOrigin);

        $untrustedResponse = $this->withHeader('Origin', 'https://untrusted.example')
            ->getJson('/api/_security-headers-test');
        $this->assertNotSame(
            'https://untrusted.example',
            $untrustedResponse->headers->get('Access-Control-Allow-Origin'),
        );

        $this->withHeader('Origin', $trustedOrigin)
            ->getJson('/api/_security-headers-test')
            ->assertHeader('Access-Control-Allow-Origin', $trustedOrigin);
    }
}
