<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveChatMessageSent;
use App\Events\LiveChatSessionUpdated;
use App\Http\Controllers\Controller;
use App\Models\LiveChatMessage;
use App\Models\LiveChatSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LiveChatController — API phía khách cho tư vấn trực tiếp.
 *
 * Quy ước quan trọng:
 *  - Chỉ khách ĐÃ ĐĂNG NHẬP mới được mở phiên tư vấn. Khách vãng lai dùng
 *    chatbot (không cần đăng nhập) nhưng tư vấn trực tiếp cần để nhân viên
 *    biết ai mình đang nói chuyện và để ghi nhận lịch sử phục vụ.
 *  - Mỗi khách chỉ có MỘT phiên đang mở. Mở phiên mới khi phiên cũ còn
 *    hoạt động sẽ làm hàng chờ nhân viên rối, nên ta trả về phiên cũ.
 *  - Nhân viên KHÔNG nhận phiên → mỗi tin nhắn của khách không làm nhảy
 *    thông báo "có khách mới" cho tất cả nhân viên.
 */
class LiveChatController extends Controller
{
    /**
     * POST /api/live-chat/sessions
     * Mở phiên tư vấn (hoặc trả về phiên đang có).
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng đăng nhập để được tư vấn trực tiếp với nhân viên.',
                'requires_login' => true,
            ], 401);
        }

        // Một khách chỉ có một phiên đang mở: tái sử dụng phiên cũ.
        $existing = LiveChatSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'data' => $this->sessionPayload($existing),
            ]);
        }

        $request->validate([
            'topic' => 'nullable|string|max:500',
        ]);

        $session = LiveChatSession::create([
            'user_id' => $user->id,
            'customer_name' => $user->full_name ?: $user->name,
            'customer_phone' => $user->phone,
            'customer_email' => $user->email,
            'status' => 'waiting',
            'topic' => $request->input('topic'),
        ]);

        // Nhân viên cần biết có khách mới ngay, nên phát sự kiện hàng chờ.
        LiveChatSessionUpdated::dispatch($session);

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session),
        ], 201);
    }

    /**
     * GET /api/live-chat/sessions/{id}
     * Lấy phiên + lịch sử tin nhắn (dùng để khôi phục sau F5).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session, withMessages: true),
        ]);
    }

    /**
     * POST /api/live-chat/sessions/{id}/messages
     * Khách gửi tin nhắn.
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        if ($session->isClosed()) {
            return response()->json([
                'success' => false,
                'message' => 'Phiên tư vấn đã kết thúc. Vui lòng mở phiên mới.',
            ], 409);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $message = DB::transaction(function () use ($session, $validated) {
            $message = LiveChatMessage::create([
                'session_id' => $session->id,
                'sender_type' => 'customer',
                'sender_id' => $session->user_id,
                'content' => $validated['content'],
            ]);

            $session->update(['last_message_at' => now()]);

            return $message;
        });

        $session->refresh();
        $message->setRelation('session', $session);

        LiveChatMessageSent::dispatch($session, $message);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $this->messagePayload($message),
                'session' => $this->sessionPayload($session),
            ],
        ], 201);
    }

    /**
     * POST /api/live-chat/sessions/{id}/close
     * Khách kết thúc phiên.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        if (!$session->isClosed()) {
            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            LiveChatSessionUpdated::dispatch($session);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session),
        ]);
    }

    // ===== Helpers =====

    /**
     * Phiên của chính khách gọi — không cho xem phiên người khác.
     */
    private function ownedSession(Request $request, int $id): ?LiveChatSession
    {
        $userId = $request->user()?->id;
        if ($userId === null) {
            return null;
        }

        return LiveChatSession::where('id', $id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(LiveChatSession $session, bool $withMessages = false): array
    {
        $session->loadMissing('assignedStaff');

        $payload = [
            'id' => $session->id,
            'status' => $session->status,
            'customer_name' => $session->customer_name,
            'topic' => $session->topic,
            'assigned_staff_id' => $session->assigned_staff_id,
            'assigned_staff_name' => $session->assignedStaff?->name,
            'last_message_at' => $session->last_message_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];

        if ($withMessages) {
            $messages = LiveChatMessage::where('session_id', $session->id)
                ->orderBy('id')
                ->get();

            $payload['messages'] = $messages->map(fn ($m) => $this->messagePayload($m))->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(LiveChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'session_id' => $message->session_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
