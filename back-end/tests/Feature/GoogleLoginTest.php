<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google', [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost:8000/auth/google/callback',
            'frontend_url' => 'http://localhost:5173',
        ]);
    }

    private function state(): string
    {
        $location = $this->get('/api/auth/google/start?nonce=' . self::NONCE)->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('http://localhost:8000/auth/google/callback', $query['redirect_uri']);
        return $query['state'];
    }

    private function fakeGoogle(string $email = 'customer@example.com', bool $verified = true): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-123', 'email' => $email, 'email_verified' => $verified, 'name' => 'Khách Google',
            ]),
        ]);
    }

    public function test_verified_google_user_gets_single_use_ticket_and_customer_account(): void
    {
        $this->fakeGoogle();
        $state = $this->state();
        $callback = $this->get('/auth/google/callback?state=' . $state . '&code=google-code')->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('ticket', $query);
        $ticket = $query['ticket'];

        $this->postJson('/api/auth/google/exchange', ['ticket' => $ticket, 'nonce' => str_repeat('b', 64)])->assertUnprocessable();
        // A wrong browser consumes the single-use ticket; start a fresh flow.
        $callback = $this->get('/auth/google/callback?state=' . $this->state() . '&code=google-code')->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $freshQuery);
        $ticket = $freshQuery['ticket'];
        $this->postJson('/api/auth/google/exchange', ['ticket' => $ticket, 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('data.user.email', 'customer@example.com')->assertJsonStructure(['data' => ['token']]);
        $this->postJson('/api/auth/google/exchange', ['ticket' => $ticket, 'nonce' => self::NONCE])->assertUnprocessable();
        $this->get('/auth/google/callback?state=' . $state . '&code=google-code')->assertRedirectContains('invalid_state');
        $this->assertDatabaseHas('users', ['email' => 'customer@example.com', 'google_id' => 'google-sub-123']);
        $this->assertSame('CUSTOMER', User::where('email', 'customer@example.com')->firstOrFail()->roles()->firstOrFail()->code);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeGoogle(verified: false);
        $this->get('/auth/google/callback?state=' . $this->state() . '&code=google-code')->assertRedirectContains('unverified_email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_customer_with_verified_email_is_linked_without_duplicate_account(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.com']);
        $role = Role::firstOrCreate(['code' => 'CUSTOMER'], ['name' => 'Khách hàng']);
        $customer->roles()->attach($role->id, ['assigned_at' => now()]);
        $this->fakeGoogle();

        $callback = $this->get('/auth/google/callback?state=' . $this->state() . '&code=google-code')->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->postJson('/api/auth/google/exchange', ['ticket' => $query['ticket'], 'nonce' => self::NONCE])
            ->assertOk()->assertJsonPath('data.user.id', $customer->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('google-sub-123', $customer->fresh()->google_id);
    }

    public function test_staff_account_cannot_enter_customer_google_flow(): void
    {
        $staff = User::factory()->create(['email' => 'staff@example.com']);
        $role = Role::firstOrCreate(['code' => 'MANAGER'], ['name' => 'Quản lý']);
        $staff->roles()->attach($role->id, ['assigned_at' => now()]);
        $this->fakeGoogle(email: 'staff@example.com');
        $this->get('/auth/google/callback?state=' . $this->state() . '&code=google-code')->assertRedirectContains('account_unavailable');
        $this->assertNull($staff->fresh()->google_id);
    }
}
