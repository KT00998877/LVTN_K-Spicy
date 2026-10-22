<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware kiểm tra user đã đăng nhập VÀ có ít nhất 1 role nằm trong
 * danh sách cho phép (WAITER / KITCHEN / CASHIER / MANAGER).
 *
 * Sử dụng: Route::middleware(['auth:sanctum', 'staff:WAITER,MANAGER'])
 *
 * Phân biệt với CUSTOMER: nếu user không có role nhân viên nào thì trả 403.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa đăng nhập.',
            ], 401);
        }

        // Lấy roles code của user từ bảng user_role + roles
        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Tài khoản đã bị vô hiệu hóa.'], 403);
        }

        $userRoles = $user->roles()->pluck('code')->map(fn ($r) => strtoupper($r))->all();

        // Nếu tham số trống → chỉ cần user là staff (có 1 trong 4 role chuẩn)
        $allowed = empty($roles)
            ? ['WAITER', 'KITCHEN', 'CASHIER', 'MANAGER', 'SHIPPER']
            : array_map(fn ($r) => strtoupper($r), $roles);

        $intersect = array_intersect($userRoles, $allowed);
        if (empty($intersect)) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản không có quyền truy cập khu vực nhân viên.',
                'required_roles' => $allowed,
                'your_roles'     => $userRoles,
            ], 403);
        }

        // Lưu primary role (lấy role đầu tiên match) vào request để controller dùng
        $request->attributes->set('staff_role', reset($intersect));

        return $next($request);
    }
}
