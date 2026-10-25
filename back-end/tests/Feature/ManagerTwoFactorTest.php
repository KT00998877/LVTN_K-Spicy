<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use OTPHP\TOTP;
use Tests\TestCase;

class ManagerTwoFactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('failed_login_count')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('user_role', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamp('assigned_at')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('auth_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->string('identifier')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('success');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_manager_password_login_requires_totp_before_token_is_issued(): void
    {
        [$user, $service, $request] = $this->manager();
        $setup = $service->beginTwoFactorSetup($user);
        $code = TOTP::createFromSecret($setup['secret'])->now();
        $service->confirmTwoFactor($user->fresh(), $code, $request);

        $passwordResult = $service->login($user->email, 'Password!123', $request);

        $this->assertTrue($passwordResult['requires_two_factor']);
        $this->assertNull($passwordResult['token']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $result = $service->completeTwoFactorLogin(
            $passwordResult['challenge_token'],
            TOTP::createFromSecret($setup['secret'])->now(),
            $request,
        );

        $this->assertNotEmpty($result['token']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_or_expired_challenge_does_not_issue_token(): void
    {
        [$user, $service, $request] = $this->manager();
        $setup = $service->beginTwoFactorSetup($user);
        $service->confirmTwoFactor(
            $user->fresh(),
            TOTP::createFromSecret($setup['secret'])->now(),
            $request,
        );
        $login = $service->login($user->email, 'Password!123', $request);

        try {
            $service->completeTwoFactorLogin($login['challenge_token'], '000000', $request);
            $this->fail('Invalid TOTP should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        Cache::flush();
        try {
            $service->completeTwoFactorLogin(
                $login['challenge_token'],
                TOTP::createFromSecret($setup['secret'])->now(),
                $request,
            );
            $this->fail('Expired challenge should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_enabled_manager_cannot_replace_secret_without_disabling_first(): void
    {
        [$user, $service, $request] = $this->manager();
        $setup = $service->beginTwoFactorSetup($user);
        $service->confirmTwoFactor(
            $user->fresh(),
            TOTP::createFromSecret($setup['secret'])->now(),
            $request,
        );

        try {
            $service->beginTwoFactorSetup($user->fresh());
            $this->fail('Enabled TOTP secret must not be replaceable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('two_factor', $exception->errors());
        }

        $this->assertSame($setup['secret'], $user->fresh()->two_factor_secret);
    }

    /** @return array{User, AuthService, Request} */
    private function manager(): array
    {
        $user = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => Hash::make('Password!123'),
            'is_active' => true,
        ]);
        $role = Role::create(['code' => 'MANAGER', 'name' => 'Quản lý']);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);
        $request = Request::create('/api/auth/login', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        return [$user, app(AuthService::class), $request];
    }
}
