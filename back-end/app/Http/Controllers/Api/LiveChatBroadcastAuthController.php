<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveChatSession;
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
 *  - `live-chat-queue`         → chỉ nhân viên
 *  - `live-chat-session.{id}`  → nhân viên, hoặc khách sở hữu phiên đó
 *
 * Token nhân viên và token khách đều dùng Sanctum; phân biệt bằng role.
 */
class LiveChatBroadcastAuthController extends BroadcastController
{
    /** Nhân viên được phép nghe cả hàng chờ lẫn bất kỳ phiên nào. */
    private const STAFF_ROLES = ['MANAGER', 'WAITER', 'CASHIER', 'KITCHEN', 'SHIPPER'];

    public function authenticate(Request $request): Response
    {
        $channelName = (string) $request->input('channel_name', '');

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'Không xác thực được kênh chat.',
            ], 403);
        }

        if (!$this->canAccess($user, $channelName)) {
            return response()->json([
                'message' => 'Không có quyền truy cập.',
            ], 403);
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

    /**
     * Người dùng có được nghe kênh này không.
     *
     * Tách riêng khỏi `authenticate()` vì bước cấp quyền là logic của ứng dụng
     * (test được, không cần broadcaster), còn phần ký HMAC là việc của Pusher.
     */
    public function canAccess(\App\Models\User $user, string $channelName): bool
    {
        if (!$user->is_active) {
            return false;
        }

        $isStaff = $user->roles()->whereIn('code', self::STAFF_ROLES)->exists();

        // Kênh hàng chờ dùng chung cho mọi nhân viên; khách không cần nghe.
        if ($channelName === 'live-chat-queue') {
            return $isStaff;
        }

        if (preg_match('/^live-chat-session\.(\d+)$/', $channelName, $matches)) {
            // Nhân viên nghe được mọi phiên.
            if ($isStaff) {
                return true;
            }

            // Khách chỉ nghe được phiên của chính mình.
            return LiveChatSession::where('id', (int) $matches[1])
                ->where('user_id', $user->id)
                ->exists();
        }

        return false;
    }
}