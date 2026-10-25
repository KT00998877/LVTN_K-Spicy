<?php

namespace App\Services;

use App\Models\AuthAuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OTPHP\TOTP;

/**
 * AuthService — xử lý nghiệp vụ auth với focus bảo mật:
 *
 *  - Password hashing: bcrypt cost 12
 *  - Account lockout: 5 lần sai → khóa 15 phút
 *  - Generic error: không phân biệt "email tồn tại" vs "sai mật khẩu"
 *  - Token management: Sanctum Personal Access Token
 *  - Audit log: ghi nhận login_success / login_failed / logout / register / lockout
 *  - Password reset: link có chữ ký, expires sau 60 phút
 */
class AuthService
{
    /** Số lần đăng nhập sai tối đa trước khi khóa */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Thời gian khóa tài khoản (phút) */
    private const LOCKOUT_MINUTES = 15;

    /** Tên token khi tạo (giúp quản lý dễ) */
    private const TOKEN_NAME = 'spa';

    private const TWO_FACTOR_CHALLENGE_TTL_MINUTES = 5;

    /**
     * Đăng ký tài khoản mới.
     *
     * @param array{name: string, email: string, password: string, full_name?: string, phone?: string} $data
     * @return array{user: User, token: string}
     */
    public function register(array $data, Request $request): array
    {
        // Check email/phone đã tồn tại chưa (vẫn trả generic error ở auth khác — đây là case đăng ký nên rõ ràng OK).
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Email đã được sử dụng.'],
            ]);
        }
        if (!empty($data['phone']) && User::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Số điện thoại đã được sử dụng.'],
            ]);
        }

        $user = DB::transaction(function () use ($data) {
            $role = Role::firstOrCreate(
                ['code' => 'CUSTOMER'],
                ['name' => 'Khách hàng', 'description' => 'Đặt món, xem menu, thanh toán trực tuyến']
            );

            $user = User::create([
                'name'              => $data['name'],
                'full_name'         => $data['full_name'] ?? $data['name'],
                'email'             => $data['email'],
                'phone'             => $data['phone'] ?? null,
                'password'          => $data['password'], // auto-hashed nhờ cast 'hashed'
                'is_active'         => true,
                'password_changed_at' => now(),
            ]);

            $user->roles()->attach($role->id, ['assigned_at' => now()]);

            return $user;
        });

        // Tạo Sanctum token
        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addDays(7))->plainTextToken;

        $this->log($user, 'registered', $data['email'], $request, true);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Đăng nhập. Trả về generic error nếu thất bại để tránh user enumeration.
     *
     * @return array{user: User, token: ?string, requires_two_factor: bool, challenge_token?: string}
     */
    public function login(string $identifier, string $password, Request $request): array
    {
        // Tìm user theo email hoặc phone
        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        // Generic error message — không phân biệt user không tồn tại / sai mật khẩu
        $genericError = 'Email/số điện thoại hoặc mật khẩu không chính xác.';

        // Check lockout TRƯỚC — kể cả khi user không tồn tại (giảm info leak)
        if ($user && $user->isLocked()) {
            $minutesLeft = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, false) * -1 / 60));
            $this->log($user, 'login_locked', $identifier, $request, false);
            throw ValidationException::withMessages([
                'email' => ["Tài khoản đang bị tạm khóa. Vui lòng thử lại sau {$minutesLeft} phút."],
            ]);
        }

        if (!$user || !Hash::check($password, $user->password)) {
            // Tăng failed_login_count nếu user tồn tại
            if ($user) {
                $this->incrementFailedAttempts($user);
            }
            $this->log($user, 'login_failed', $identifier, $request, false, [
                'reason' => !$user ? 'user_not_found' : 'wrong_password',
            ]);
            throw ValidationException::withMessages(['email' => [$genericError]]);
        }

        // Kiểm tra is_active
        if (!$user->is_active) {
            $this->log($user, 'login_inactive', $identifier, $request, false);
            throw ValidationException::withMessages(['email' => [$genericError]]);
        }

        // Thành công → reset counter
        $this->resetFailedAttempts($user);

        if ($this->requiresTwoFactor($user)) {
            $challengeToken = Str::random(64);
            Cache::put(
                $this->twoFactorChallengeKey($challengeToken),
                $user->id,
                now()->addMinutes(self::TWO_FACTOR_CHALLENGE_TTL_MINUTES),
            );

            $this->log($user, 'login_two_factor_required', $identifier, $request, true);

            return [
                'user' => $user,
                'token' => null,
                'requires_two_factor' => true,
                'challenge_token' => $challengeToken,
            ];
        }

        // Tạo token mới (đồng thời KHÔNG xóa token cũ — FE sẽ quản lý logout-all)
        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addDays(7))->plainTextToken;

        $this->log($user, 'login_success', $identifier, $request, true);

        return [
            'user'  => $user,
            'token' => $token,
            'requires_two_factor' => false,
        ];
    }

    /** @return array{user: User, token: string} */
    public function completeTwoFactorLogin(string $challengeToken, string $code, Request $request): array
    {
        $cacheKey = $this->twoFactorChallengeKey($challengeToken);
        $userId = Cache::get($cacheKey);
        $user = is_numeric($userId) ? User::find((int) $userId) : null;

        if (!$user || !$user->is_active || !$this->requiresTwoFactor($user)) {
            throw ValidationException::withMessages([
                'code' => ['Phiên xác minh đã hết hạn. Vui lòng đăng nhập lại.'],
            ]);
        }

        if (!$this->verifyTwoFactorCode($user, $code)) {
            $this->log($user, 'login_two_factor_failed', $user->email, $request, false);
            throw ValidationException::withMessages([
                'code' => ['Mã xác minh không chính xác.'],
            ]);
        }

        $replayKey = 'auth:2fa:used:' . $user->id . ':' . hash('sha256', $code);
        if (!Cache::add($replayKey, true, now()->addSeconds(90))) {
            throw ValidationException::withMessages([
                'code' => ['Mã xác minh đã được sử dụng. Vui lòng chờ mã mới.'],
            ]);
        }

        Cache::forget($cacheKey);
        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addDays(7))->plainTextToken;
        $this->log($user, 'login_success', $user->email, $request, true, ['two_factor' => true]);

        return ['user' => $user, 'token' => $token];
    }

    /** @return array{secret: string, provisioning_uri: string} */
    public function beginTwoFactorSetup(User $user): array
    {
        if ($this->requiresTwoFactor($user)) {
            throw ValidationException::withMessages([
                'two_factor' => ['Hãy tắt xác thực hai lớp hiện tại trước khi thiết lập lại.'],
            ]);
        }

        $totp = TOTP::generate();
        $this->configureTotp($totp, $user);

        $user->forceFill([
            'two_factor_secret' => $totp->getSecret(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $totp->getSecret(),
            'provisioning_uri' => $totp->getProvisioningUri(),
        ];
    }

    public function confirmTwoFactor(User $user, string $code, Request $request): void
    {
        if (!$user->two_factor_secret || !$this->verifyTwoFactorCode($user, $code)) {
            throw ValidationException::withMessages(['code' => ['Mã xác minh không chính xác.']]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->log($user, 'two_factor_enabled', $user->email, $request, true);
    }

    public function disableTwoFactor(User $user, string $password, string $code, Request $request): void
    {
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Mật khẩu không chính xác.']]);
        }
        if (!$this->requiresTwoFactor($user) || !$this->verifyTwoFactorCode($user, $code)) {
            throw ValidationException::withMessages(['code' => ['Mã xác minh không chính xác.']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->log($user, 'two_factor_disabled', $user->email, $request, true);
    }

    private function requiresTwoFactor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null
            && $user->two_factor_secret !== null
            && $user->roles()->whereRaw('UPPER(code) = ?', ['MANAGER'])->exists();
    }

    private function verifyTwoFactorCode(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) return false;

        $totp = TOTP::createFromSecret($user->two_factor_secret);
        $this->configureTotp($totp, $user);

        return $totp->verify($code, null, 15);
    }

    private function configureTotp(TOTP $totp, User $user): void
    {
        $totp->setLabel($user->email);
        $totp->setIssuer((string) config('app.name', 'K-SPICY'));
    }

    private function twoFactorChallengeKey(string $challengeToken): string
    {
        return 'auth:2fa:challenge:' . hash('sha256', $challengeToken);
    }

    /**
     * Đăng xuất: xóa token hiện tại.
     */
    public function logout(User $user, Request $request): void
    {
        $user->currentAccessToken()->delete();
        $this->log($user, 'logout', $user->email, $request, true);
    }

    /**
     * Đăng xuất tất cả thiết bị.
     */
    public function logoutAll(User $user, Request $request): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();
        $this->log($user, 'logout_all', $user->email, $request, true, ['revoked' => $count]);
        return $count;
    }

    /**
     * Đổi mật khẩu (user đang đăng nhập).
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, Request $request): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            $this->log($user, 'change_password_failed', $user->email, $request, false);
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không chính xác.'],
            ]);
        }

        $user->forceFill([
            'password' => $newPassword, // auto-hashed
            'password_changed_at' => now(),
        ])->save();

        $this->log($user, 'change_password_success', $user->email, $request, true);
    }

    /**
     * Gửi link reset mật khẩu qua email (dùng Laravel password broker).
     * Trả về trạng thái gửi (luôn trả SUCCESS để tránh user enumeration).
     */
    public function sendPasswordResetLink(string $email, Request $request): string
    {
        $status = Password::sendResetLink(['email' => $email]);

        // Log (kể cả khi email không tồn tại, vẫn log để audit)
        $user = User::where('email', $email)->first();
        $this->log(
            $user,
            'password_reset_requested',
            $email,
            $request,
            $status === Password::RESET_LINK_SENT,
            ['broker_status' => $status],
        );

        // Luôn trả OK để không lộ email có tồn tại hay không
        return 'Nếu email tồn tại trong hệ thống, chúng tôi đã gửi link đặt lại mật khẩu.';
    }

    /**
     * Reset mật khẩu từ token nhận được qua email.
     */
    public function resetPassword(string $email, string $token, string $newPassword, Request $request): string
    {
        $status = Password::reset(
            ['email' => $email, 'password' => $newPassword, 'password_confirmation' => $newPassword, 'token' => $token],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                ])->save();

                // Xóa tất cả token hiện tại để bắt buộc đăng nhập lại
                $user->tokens()->delete();
            }
        );

        $user = User::where('email', $email)->first();
        $this->log(
            $user,
            'password_reset_completed',
            $email,
            $request,
            $status === Password::PASSWORD_RESET,
            ['broker_status' => $status],
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.'],
            ]);
        }

        return 'Mật khẩu đã được đặt lại thành công. Vui lòng đăng nhập lại.';
    }

    /**
     * Tăng số lần đăng nhập sai, nếu vượt ngưỡng thì khóa tài khoản.
     */
    private function incrementFailedAttempts(User $user): void
    {
        $newCount = $user->failed_login_count + 1;
        $lockedUntil = null;

        if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->forceFill([
            'failed_login_count'   => $newCount,
            'last_failed_login_at' => now(),
            'locked_until'         => $lockedUntil,
        ])->save();

        if ($lockedUntil !== null) {
            $this->log($user, 'account_locked', $user->email, request(), false, [
                'reason' => 'too_many_failed_attempts',
                'locked_until' => $lockedUntil->toIso8601String(),
            ]);
        }
    }

    /**
     * Reset failed counter khi đăng nhập thành công.
     */
    private function resetFailedAttempts(User $user): void
    {
        if ($user->failed_login_count > 0 || $user->locked_until !== null) {
            $user->forceFill([
                'failed_login_count'   => 0,
                'last_failed_login_at' => null,
                'locked_until'         => null,
            ])->save();
        }
    }

    /**
     * Ghi audit log.
     */
    private function log(
        ?User $user,
        string $event,
        ?string $identifier,
        Request $request,
        bool $success,
        array $metadata = []
    ): void {
        try {
            AuthAuditLog::create([
                'user_id'    => $user?->id,
                'event'      => $event,
                'identifier' => $identifier,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'metadata'   => $metadata,
                'success'    => $success,
            ]);
        } catch (\Throwable $e) {
            // Không để audit log làm vỡ auth flow
            // eslint-disable-next-line no-console
            \Log::warning('Auth audit log failed: ' . $e->getMessage());
        }
    }
}
