<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff session endpoints; login delegates to the shared authentication flow. */
class StaffAuthController extends Controller
{
    private const ALLOWED_ROLES = ['WAITER', 'KITCHEN', 'CASHIER', 'MANAGER', 'SHIPPER'];

    /** Staff session endpoints; login delegates to the shared authentication flow. */
    public function login(Request $request): JsonResponse
    {
        // Compatibility for old clients; all logins use the same validation and lockout flow.
        $request->merge(['identifier' => $request->input('identifier', $request->input('email'))]);
        return app(AuthController::class)->login($request);
    }
    public function logout(Request $request): JsonResponse
    {
        /** Staff session endpoints; login delegates to the shared authentication flow. */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đăng xuất thành công.',
        ]);
    }

    /** Staff session endpoints; login delegates to the shared authentication flow. */
    public function me(Request $request): JsonResponse
    {
        /** Staff session endpoints; login delegates to the shared authentication flow. */
        $user = $request->user();
        $userRoles = $user->roles()->pluck('code')->map(fn ($r) => strtoupper($r))->all();
        $staffRoles = array_values(array_intersect($userRoles, self::ALLOWED_ROLES));
        $primaryRole = $this->choosePrimaryRole($staffRoles);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'         => $this->formatUser($user),
                'roles'        => $staffRoles,
                'primary_role' => $primaryRole,
            ],
        ]);
    }

    /** Staff session endpoints; login delegates to the shared authentication flow. */
    private function choosePrimaryRole(array $staffRoles): ?string
    {
        $priority = ['MANAGER', 'CASHIER', 'WAITER', 'KITCHEN', 'SHIPPER'];
        foreach ($priority as $p) {
            if (in_array($p, $staffRoles, true)) return $p;
        }
        return $staffRoles[0] ?? null;
    }

    /** Staff session endpoints; login delegates to the shared authentication flow. */
    private function formatUser(User $user): array
    {
        return [
            'id'         => (int) $user->id,
            'name'       => $user->name,
            'full_name'  => $user->full_name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'is_active'  => (bool) $user->is_active,
            'can_close_daily_stock' => (bool) $user->can_close_daily_stock,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
