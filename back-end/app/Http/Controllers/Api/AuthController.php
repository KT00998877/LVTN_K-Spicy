<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
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
        $staffRoles = array_values(array_intersect(['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER'], $roles));

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data'    => [
                'roles' => $roles,
                'primary_role' => $staffRoles[0] ?? null,
                'user'  => $this->formatUser($result['user']),
                'token' => $result['token'],
            ],
        ]);
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

        $payload = [
            'id'             => $user->id,
            'name'           => $user->name,
            'full_name'      => $user->full_name,
            'email'          => $user->email,
            'phone'          => $user->phone,
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
