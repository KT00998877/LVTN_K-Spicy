<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function googleStart(Request $request): RedirectResponse
    {
        $data = $request->validate(['nonce' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/']]);
        $google = config('services.google');
        abort_unless($google['client_id'] && $google['client_secret'] && $google['redirect_uri'], 503, 'Đăng nhập Google chưa được cấu hình.');

        $state = Str::random(64);
        Cache::put('auth:google:state:' . $state, hash('sha256', $data['nonce']), now()->addMinutes(10));
        $query = http_build_query([
            'client_id' => $google['client_id'],
            'redirect_uri' => $google['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $frontend = rtrim((string) config('services.google.frontend_url'), '/') . '/dang-nhap/google/callback';
        $state = $request->query('state');
        $nonceHash = is_string($state) ? Cache::pull('auth:google:state:' . $state) : null;
        if (!is_string($nonceHash)) {
            return redirect()->away($frontend . '?error=invalid_state');
        }
        $code = $request->query('code');
        if (!is_string($code) || $code === '') {
            return redirect()->away($frontend . '?error=cancelled');
        }

        try {
            $google = config('services.google');
            $token = $this->googleHttp()->asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $google['client_id'],
                'client_secret' => $google['client_secret'],
                'redirect_uri' => $google['redirect_uri'],
                'grant_type' => 'authorization_code',
            ])->throw()->json('access_token');
            if (!is_string($token) || $token === '') throw new \RuntimeException('Missing Google access token');

            $profile = $this->googleHttp()->withToken($token)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
            $googleId = $profile['sub'] ?? null;
            $email = $profile['email'] ?? null;
            if (!is_string($googleId) || $googleId === '' || !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || ($profile['email_verified'] ?? false) !== true) {
                return redirect()->away($frontend . '?error=unverified_email');
            }

            $user = DB::transaction(function () use ($googleId, $email, $profile): ?User {
                $user = User::where('google_id', $googleId)->lockForUpdate()->first();
                if (!$user) $user = User::where('email', $email)->lockForUpdate()->first();
                if ($user) {
                    if (!$user->is_active || ($user->google_id && $user->google_id !== $googleId)
                        || !$user->roles()->where('code', 'CUSTOMER')->exists()
                        || $user->roles()->whereIn('code', ['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER', 'SUPPLIER'])->exists()) return null;
                    if (!$user->google_id) $user->forceFill(['google_id' => $googleId])->save();
                    return $user;
                }

                $name = trim((string) ($profile['name'] ?? '')) ?: explode('@', $email)[0];
                $role = Role::firstOrCreate(['code' => 'CUSTOMER'], ['name' => 'Khách hàng', 'description' => 'Đặt món, xem menu, thanh toán trực tuyến']);
                $user = User::create([
                    'name' => mb_substr($name, 0, 100), 'full_name' => mb_substr($name, 0, 100),
                    'email' => $email, 'password' => Str::random(64), 'is_active' => true,
                ]);
                $user->forceFill(['google_id' => $googleId, 'email_verified_at' => now()])->save();
                $user->roles()->attach($role->id, ['assigned_at' => now()]);
                return $user;
            });
            if (!$user) return redirect()->away($frontend . '?error=account_unavailable');

            $ticket = Str::random(64);
            Cache::put('auth:google:ticket:' . $ticket, ['user_id' => $user->id, 'nonce_hash' => $nonceHash], now()->addMinutes(2));
            return redirect()->away($frontend . '?ticket=' . rawurlencode($ticket));
        } catch (\Throwable $e) {
            report($e);
            return redirect()->away($frontend . '?error=google_unavailable');
        }
    }

    public function googleExchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'size:64'],
            'nonce' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $ticketData = Cache::pull('auth:google:ticket:' . $data['ticket']);
        $user = is_array($ticketData) && hash_equals($ticketData['nonce_hash'], hash('sha256', $data['nonce']))
            ? User::find($ticketData['user_id']) : null;
        if (!$user || !$user->is_active || !$user->roles()->where('code', 'CUSTOMER')->exists()
            || $user->roles()->whereIn('code', ['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER', 'SUPPLIER'])->exists()) {
            return response()->json(['message' => 'Phiên đăng nhập Google không còn hợp lệ. Vui lòng thử lại.'], 422);
        }

        $token = $user->createToken('spa', ['*'], now()->addDays(7))->plainTextToken;
        return response()->json(['success' => true, 'data' => ['user' => $this->formatUser($user), 'token' => $token]]);
    }

    private function googleHttp(): PendingRequest
    {
        $options = PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            ? ['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]] : [];
        return Http::withOptions($options)->timeout(10);
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'full_name' => ['nullable', 'string', 'max:100'],
            'email'    => ['required', 'string', 'email:rfc', 'max:191', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/', 'unique:users,phone'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'name.required' => 'Vui lòng nhập họ tên.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'email.unique' => 'Email đã được sử dụng.',
            'phone.regex' => 'Số điện thoại không hợp lệ.',
            'phone.unique' => 'Số điện thoại đã được sử dụng.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
            'password.mixed' => 'Mật khẩu phải có ít nhất một chữ hoa và một chữ thường.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
        ]);

        $result = $this->authService->register($data, $request);

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công',
            'data'    => [
                'user'  => $this->formatUser($result['user']),
                'token' => $result['token'],
            ],
        ], 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password'   => ['required', 'string', 'min:1'],
        ], [
            'identifier.required' => 'Vui lòng nhập email hoặc số điện thoại.',
            'password.required'   => 'Vui lòng nhập mật khẩu.',
        ]);

        $result = $this->authService->login($data['identifier'], $data['password'], $request);
        $roles = $result['user']->roles()->pluck('code')->map(fn ($role) => strtoupper($role))->all();
        $staffRoles = array_values(array_intersect(['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER', 'SUPPLIER'], $roles));

        if ($result['requires_two_factor']) {
            return response()->json([
                'success' => true,
                'message' => 'Vui lòng nhập mã xác minh từ ứng dụng bảo mật.',
                'data' => [
                    'requires_two_factor' => true,
                    'challenge_token' => $result['challenge_token'],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data'    => [
                'roles' => $roles,
                'primary_role' => $staffRoles[0] ?? null,
                'user'  => $this->formatUser($result['user']),
                'token' => $result['token'],
                'requires_two_factor' => false,
            ],
        ]);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
        ]);
        $result = $this->authService->completeTwoFactorLogin(
            $data['challenge_token'],
            $data['code'],
            $request,
        );
        $roles = $result['user']->roles()->pluck('code')->map(fn ($role) => strtoupper($role))->all();
        $staffRoles = array_values(array_intersect(['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER', 'SUPPLIER'], $roles));

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data' => [
                'roles' => $roles,
                'primary_role' => $staffRoles[0] ?? null,
                'user' => $this->formatUser($result['user']),
                'token' => $result['token'],
                'requires_two_factor' => false,
            ],
        ]);
    }

    public function twoFactorStatus(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $request->user()->two_factor_confirmed_at !== null,
                'setup_pending' => $request->user()->two_factor_secret !== null
                    && $request->user()->two_factor_confirmed_at === null,
            ],
        ]);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->authService->beginTwoFactorSetup($request->user()),
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $this->authService->confirmTwoFactor($request->user(), $data['code'], $request);

        return response()->json(['success' => true, 'message' => 'Đã bật xác thực hai lớp.']);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);
        $this->authService->disableTwoFactor(
            $request->user(),
            $data['password'],
            $data['code'],
            $request,
        );

        return response()->json(['success' => true, 'message' => 'Đã tắt xác thực hai lớp.']);
    }

    /**
     * POST /api/auth/logout
     * Sanctum auth required.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Đăng xuất thành công',
        ]);
    }

    /**
     * POST /api/auth/logout-all
     * Revoke tất cả token của user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->authService->logoutAll($user, $request);

        return response()->json([
            'success' => true,
            'message' => "Đã thu hồi {$count} phiên đăng nhập.",
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
        ]);
    }

    /**
     * PUT /api/auth/me
     * Cập nhật thông tin cá nhân (full_name, phone).
     * Email và password được cập nhật qua endpoint riêng để bảo mật.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'     => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^(0|\+84)[0-9]{9,10}$/',
                // Bỏ qua chính user hiện tại khi check unique
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ], [
            'name.required' => 'Vui lòng nhập họ tên.',
            'phone.regex'   => 'Số điện thoại không hợp lệ.',
            'phone.unique'  => 'Số điện thoại đã được sử dụng.',
        ]);

        $user->fill($data);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thông tin thành công.',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/me/avatar
     * Upload ảnh đại diện. Tạm thời giới hạn ở ảnh vuông để client hiển thị
     * tròn không bị méo; client nên crop trước khi gửi.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'avatar.required' => 'Vui lòng chọn ảnh đại diện.',
            'avatar.image'    => 'Tệp phải là hình ảnh.',
            'avatar.mimes'    => 'Ảnh phải có định dạng JPG, PNG hoặc WebP.',
            'avatar.max'      => 'Ảnh đại diện không được vượt quá 4MB.',
        ]);

        $disk = Storage::disk('public');
        $extension = strtolower($data['avatar']->getClientOriginalExtension() ?: 'jpg');
        // Tên random để không lộ tên/đường dẫn thật của khách lên URL.
        $relativePath = 'avatars/' . $user->id . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        $disk->put($relativePath, file_get_contents($data['avatar']->getRealPath()));

        // Xóa ảnh cũ để không tích rác trong storage.
        $previous = $user->avatar_path;
        if ($previous && $previous !== $relativePath && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        $user->avatar_path = $relativePath;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật ảnh đại diện thành công.',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * DELETE /api/auth/me/avatar
     * Xóa ảnh đại diện, quay về chữ cái đầu.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $disk = Storage::disk('public');
        if ($user->avatar_path && $disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }

        $user->avatar_path = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa ảnh đại diện.',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->authService->changePassword($user, $data['current_password'], $data['password'], $request);

        return response()->json([
            'success' => true,
            'message' => 'Đổi mật khẩu thành công',
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     * Luôn trả generic response để tránh user enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
        ]);

        $message = $this->authService->sendPasswordResetLink($data['email'], $request);

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'email:rfc'],
            'token'    => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        $message = $this->authService->resetPassword(
            $data['email'],
            $data['token'],
            $data['password'],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Chuẩn hóa thông tin user trả về cho FE (ẩn field nhạy cảm).
     */
    private function formatUser(User $user): array
    {
        $user->loadMissing('tier');

        // Tính tier hiện tại (cached trong request) + progress sang tier kế tiếp.
        $tierService = app(\App\Services\LoyaltyTierService::class);
        [$current, $next] = $tierService->resolveForUser($user);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $publicDisk */
        $publicDisk = Storage::disk('public');

        $payload = [
            'id'             => $user->id,
            'name'           => $user->name,
            'full_name'      => $user->full_name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'avatar_url'     => $user->avatar_path
                ? $publicDisk->url($user->avatar_path)
                : null,
            'is_active'      => (bool) $user->is_active,
            'can_close_daily_stock' => (bool) $user->can_close_daily_stock,
            'loyalty_points' => (int) ($user->loyalty_points ?? 0),
            'total_spent'    => (int) ($user->total_spent ?? 0),
            'tier'           => $current ? [
                'id'           => (int) $current->id,
                'code'         => $current->code,
                'name'         => $current->name,
                'color'        => $current->color,
                'icon'         => $current->icon,
                'point_multiplier' => (float) $current->pointMultiplier(),
                'free_ship_threshold' => $current->freeShipThreshold(),
                'extra_discount_pct' => (float) $current->extraDiscountPercent(),
            ] : null,
            'next_tier'      => $next ? [
                'id'           => (int) $next->id,
                'code'         => $next->code,
                'name'         => $next->name,
                'min_spent'    => (int) $next->min_spent,
                'amount_to_next' => (int) max(0, ((int) $next->min_spent) - ((int) ($user->total_spent ?? 0))),
            ] : null,
            'created_at'     => $user->created_at?->toIso8601String(),
        ];

        // Tỉ lệ tiến trình tới hạng kế (chỉ trả khi có tier kế).
        if ($current && $next) {
            $curMin   = (int) $current->min_spent;
            $nextMin  = (int) $next->min_spent;
            $spent    = (int) ($user->total_spent ?? 0);
            $payload['tier_progress_percent'] = $nextMin > $curMin
                ? (float) min(100.0, round(max(0.0, ($spent - $curMin) / ($nextMin - $curMin) * 100), 1))
                : 100.0;
        } else {
            $payload['tier_progress_percent'] = null;
        }

        return $payload;
    }
}
