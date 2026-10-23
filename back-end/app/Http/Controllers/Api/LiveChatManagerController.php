<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveChatMessageSent;
use App\Events\LiveChatSessionUpdated;
use App\Http\Controllers\Controller;
use App\Models\LiveChatMessage;
use App\Models\LiveChatSession;
use App\Models\User;
use App\Services\LiveChatSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LiveChatManagerController — API phía nhân viên cho tư vấn trực tiếp.
 *
 * Hàng chờ gồm hai phần:
 *  - `waiting`: phiên chưa ai nhận — mọi nhân viên đều thấy.
 *  - `mine`:     phiên mình đang phụ trách.
 */
class LiveChatManagerController extends Controller
{
    public function __construct(
        private LiveChatSuggestionService $suggestionService,
    ) {
    }

    /**
     * GET /api/live-chat/manager/queue
     * Hàng chờ + các phiên của tôi.
     */
    public function queue(Request $request): JsonResponse
    {
        $staff = $request->user();

        $waiting = LiveChatSession::query()
            ->waiting()
            ->with('assignedStaff')
            ->orderBy('created_at')
            ->get();

        $mine = LiveChatSession::query()
            ->where('assigned_staff_id', $staff->id)
            ->whereIn('status', ['waiting', 'accepted'])
            ->with('assignedStaff')
            ->orderBy('last_message_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'waiting' => $waiting->map(fn ($s) => $this->sessionPayload($s))->values()->all(),
                'mine' => $mine->map(fn ($s) => $this->sessionPayload($s))->values()->all(),
            ],
        ]);
    }

    /**
     * GET /api/live-chat/manager/sessions/{id}
     * Chi tiết phiên + lịch sử tin nhắn.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::with('assignedStaff')->find($id);

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
     * GET /api/live-chat/manager/sessions/{id}/suggestions
     * Gợi ý câu trả lời nhanh.
     */
    public function suggestions(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        $result = $this->suggestionService->suggest($session);

        return response()->json([
            'success' => true,
            'data' => [
                'suggestions' => $result['suggestions'],
                'source' => $result['source'],
            ],
        ]);
    }

    /**
     * POST /api/live-chat/manager/sessions/{id}/accept
     * Nhận phiên từ hàng chờ.
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $staff = $request->user();

        $session = DB::transaction(function () use ($id, $staff) {
            // Khoá dòng để hai nhân viên không nhận cùng một phiên.
            $session = LiveChatSession::where('id', $id)->lockForUpdate()->first();

            if (!$session) {
                return null;
            }

            if ($session->isClosed()) {
                return 'closed';
            }

            if ($session->assigned_staff_id && $session->assigned_staff_id !== $staff->id) {
                return 'taken';
            }

            if (!$session->assigned_staff_id) {
                $session->update([
                    'assigned_staff_id' => $staff->id,
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);
            }

            return $session;
        });

        if ($session === null) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        if ($session === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'Phiên tư vấn đã kết thúc.',
            ], 409);
        }

        if ($session === 'taken') {
            return response()->json([
                'success' => false,
                'message' => 'Phiên này đã được nhân viên khác nhận.',
            ], 409);
        }

        $session->load('assignedStaff');
        LiveChatSessionUpdated::dispatch($session);

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session),
        ]);
    }

    /**
     * POST /api/live-chat/manager/sessions/{id}/messages
     * Nhân viên gửi tin nhắn.
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $staff = $request->user();

        $session = LiveChatSession::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên tư vấn.',
            ], 404);
        }

        if ($session->isClosed()) {
            return response()->json([
                'success' => false,
                'message' => 'Phiên tư vấn đã kết thúc.',
            ], 409);
        }

        if (!$session->assigned_staff_id) {
            // Nhân viên phải nhận phiên trước khi trả lời.
            $session->update([
                'assigned_staff_id' => $staff->id,
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $message = DB::transaction(function () use ($session, $staff, $validated) {
            $message = LiveChatMessage::create([
                'session_id' => $session->id,
                'sender_type' => 'staff',
                'sender_id' => $staff->id,
                'content' => $validated['content'],
            ]);

            $session->update(['last_message_at' => now()]);

            return $message;
        });

        $session->refresh();
        $session->load('assignedStaff');
        $message->setRelation('session', $session);

        LiveChatMessageSent::dispatch($session, $message);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => [
                    'id' => $message->id,
                    'session_id' => $message->session_id,
                    'sender_type' => $message->sender_type,
                    'sender_id' => $message->sender_id,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
                'session' => $this->sessionPayload($session),
            ],
        ], 201);
    }

    /**
     * POST /api/live-chat/manager/sessions/{id}/close
     * Nhân viên kết thúc phiên.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $session = LiveChatSession::find($id);

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
            $session->load('assignedStaff');
            LiveChatSessionUpdated::dispatch($session);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session),
        ]);
    }

    // ===== Helpers =====

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(LiveChatSession $session, bool $withMessages = false): array
    {
        $payload = [
            'id' => $session->id,
            'status' => $session->status,
            'customer_name' => $session->customer_name,
            'customer_phone' => $session->customer_phone,
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

            $payload['messages'] = $messages->map(fn ($m) => [
                'id' => $m->id,
                'session_id' => $m->session_id,
                'sender_type' => $m->sender_type,
                'sender_id' => $m->sender_id,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $payload;
    }
}
