<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a Sanctum user when one is present, without ever rejecting the guest.
 *
 * The chatbot is usable both logged-in and logged-out. `auth:sanctum` would
 * force a 401 on every guest turn, but omitting auth middleware entirely means
 * `$request->user()` is always null — a logged-in customer silently got a guest
 * session (user_id = NULL), could not read it back, and the AI Service never
 * received the bearer token its cart/order tools need.
 *
 * So: attempt to resolve the user and set the default guard, then let the
 * request through either way. An invalid/expired token is treated as a guest
 * rather than an error, matching how the frontend behaves.
 */
class AuthenticateSanctumOrGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->authenticate();
            $user = $request->user();
            if ($user && $user->roles()->where('code', '!=', 'CUSTOMER')->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chatbot chỉ dành cho khách hàng.',
                ], 403);
            }
        }

        return $next($request);
    }
}
