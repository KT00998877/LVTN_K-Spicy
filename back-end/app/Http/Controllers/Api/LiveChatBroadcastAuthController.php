<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveChatSession;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác thực kênh realtime cho live chat.
 *
 * Route broadcasting chuẩn của ứng dụng bị khoá cho nhân viên
 * (`auth:sanctum` + `staff:...`), nên khách hàng không ký được kênh của mình.
 * Endpoint này mở riêng cho live chat và tự kiểm tra quyền theo từng kênh:
 *
 *  - `live-chat-queue`          → chỉ nhân viên
 *  - `live-chat-session.{id}`   → nhân viên, hoặc khách sở hữu phiên đó
 *
 * Token nhân viên và token khách đều dùng Sanctum; phân biệt bằng role.
 */
class LiveChatBroadcastAuthController extends BroadcastController
{
    public function authenticate(Request $request): Response
    {
        $channelName = (string) $request->input('channel_name', '');

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'Không xác thực được kênh chat.',
            ], 403);
        }

        $isStaff = $user->roles()->whereIn('code', ['MANAGER', 'WAITER', 'CASHIER', 'KITCHEN', 'SHIPPER'])->exists();

        // Kênh hàng chờ: chỉ nhân viên được nghe, khách không cần.
        if ($channelName === 'live-chat-queue') {
            if (!$isStaff) {
                return response()->json(['message' => 'Không có quyền truy cập.'], 403);
            }
        } elseif (preg_match('/^live-chat-session\.(\d+)$/', $channelName, $matches)) {
            $sessionId = (int) $matches[1];

            if (!$isStaff) {
                // Khách chỉ được nghe phiên của chính mình.
                $owns = LiveChatSession::where('id', $sessionId)
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$owns) {
                    return response()->json(['message' => 'Không có quyền truy cập.'], 403);
                }
            }
        } else {
            return response()->json(['message' => 'Kênh không hợp lệ.'], 403);
        }

        try {
            return parent::authenticate($request);
        } catch (\Throwable $e) {
            Log::warning('Live chat broadcast auth failed', [
                'channel' => $channelName,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Không xác thực được kênh chat.'], 403);
        }
    }
}
