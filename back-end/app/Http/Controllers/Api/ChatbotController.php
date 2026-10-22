<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\ChatPendingAction;
use App\Models\User;
use App\Services\Chatbot\PendingActionExecutor;
use App\Services\Chatbot\ConfirmationSummaryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ChatbotController - Phase C3 Integration
 * 
 * Provides chatbot API that integrates with AI Service for:
 * - Menu lookup
 * - Cart management
 * - Order tracking
 * - Reservation booking
 * - Business tool execution
 * - Confirmation flow for write operations
 */
class ChatbotController extends Controller
{
    private string $aiServiceUrl;
    private string $aiServiceToken;
    private int $aiServiceTimeout;

    public function __construct(
        private PendingActionExecutor $actionExecutor,
        private ConfirmationSummaryBuilder $summaryBuilder,
    ) {
        // Read via config() so values resolve through config/rag.php and stay
        // correct under config:cache, where env() outside config returns null.
        $this->aiServiceUrl = (string) config('rag.api_url');
        $this->aiServiceToken = (string) config('rag.internal_key');
        $this->aiServiceTimeout = (int) config('rag.timeout', 60);
    }

    /**
     * POST /api/chatbot/sessions
     * Start a new chat session.
     *
     * Guests receive a guest_token they must present on every later request;
     * without it a guest cannot prove ownership of a session (user_id is NULL).
     */
    public function startSession(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;

        $session = ChatSession::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : (string) Str::uuid(),
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'status' => $session->status,
                'guest_token' => $session->guest_token,
                'created_at' => $session->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * GET /api/chatbot/sessions
     * List chat sessions for current user (or guest token).
     */
    public function listSessions(Request $request): JsonResponse
    {
        $sessions = $this->ownedSessionsQuery($request)
            ->with('messages')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'status' => $s->status,
                'created_at' => $s->created_at->toIso8601String(),
                'updated_at' => $s->updated_at->toIso8601String(),
                'last_message' => $s->messages->last()?->content,
            ])->values(),
        ]);
    }

    /**
     * GET /api/chatbot/sessions/{id}
     * Get session details with messages.
     */
    public function showSession(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSessionsQuery($request)
            ->with(['messages' => fn ($q) => $q->orderByDesc('id')->limit(50)])
            ->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên chat.',
            ], 404);
        }

        $slugs = $session->messages->flatMap(fn ($message) => array_column($message->metadata['menu_suggestions'] ?? [], 'slug'))
            ->unique()->values()->all();
        $menuItems = $slugs ? DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)->where('c.is_active', true)
            ->whereIn('m.slug', $slugs)
            ->get(['m.name', 'm.slug', 'm.image_url', 'm.description'])->keyBy('slug') : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'status' => $session->status,
                'created_at' => $session->created_at->toIso8601String(),
                'messages' => $session->messages->sortBy('id')->values()->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'metadata' => $this->metadataWithCurrentMenu($m->metadata, $menuItems),
                    'created_at' => $m->created_at->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Restrict a session query to the caller's own sessions.
     *
     * A session is owned by the caller when any of the following holds:
     *  - the caller is authenticated and the session.user_id matches, OR
     *  - the X-Chat-Guest-Token header matches a session whose user_id is
     *    still NULL (the original guest path).
     *
     * Returning both branches lets a guest who later logs in keep reading the
     * same session id, instead of getting a misleading 404 from the strict
     * either/or check the controller had before.
     */
    private function ownedSessionsQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $userId = $request->user()?->id;
        $guestToken = (string) $request->header('X-Chat-Guest-Token', '');

        return ChatSession::query()->where(function ($q) use ($userId, $guestToken) {
            // Neither credential presented: an empty closure would compile to no
            // WHERE clause and expose every session, so match nothing instead.
            if ($userId === null && $guestToken === '') {
                $q->whereRaw('1 = 0');
                return;
            }
            if ($userId !== null) {
                $q->where('user_id', $userId);
            }
            if ($guestToken !== '') {
                $q->orWhere(function ($qq) use ($guestToken) {
                    $qq->whereNull('user_id')->where('guest_token', $guestToken);
                });
            }
        });
    }

    /**
     * POST /api/chatbot/sessions/{id}/messages
     * Send a message to the chatbot.
     * 
     * Integrates with AI Service for:
     * - RAG-based responses
     * - Business tool execution
     * - Laravel API calls for dynamic data
     * - Write tool confirmation flow
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'use_tools' => 'boolean',
            'client_message_id' => 'nullable|uuid',
        ]);
        // The web SAPI's default limit can expire before the HTTP client does.
        set_time_limit($this->aiServiceTimeout + 10);
        $lock = Cache::lock("chatbot:session:{$id}", $this->aiServiceTimeout + 15);
        if (!$lock->get()) {
            return response()->json(['success' => false, 'message' => 'Một tin nhắn đang được xử lý. Vui lòng đợi.'], 409);
        }
        try {
            return $this->processMessage($request, $id);
        } finally {
            $lock->release();
        }
    }

    private function processMessage(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()?->id;
        $requestId = $request->input('client_message_id') ?: (string) Str::uuid();

        // Validate session ownership (user_id or guest_token).
        $session = $this->ownedSessionsQuery($request)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên chat.',
            ], 404);
        }

        if (!$session->isActive()) {
            return response()->json(['success' => false, 'message' => 'Phiên chat đã đóng. Vui lòng bắt đầu hội thoại mới.'], 409);
        }
        $message = $request->input('message');
        $useTools = $request->boolean('use_tools', false);
        $previous = $session->messages()->where('metadata->request_id', $requestId)->where('role', 'assistant')->first();
        if ($previous) {
            $previousUser = $session->messages()->where('metadata->request_id', $requestId)->where('role', 'user')->first();
            if ($previousUser?->content !== $message) {
                return response()->json(['success' => false, 'message' => 'Mã tin nhắn đã được sử dụng cho nội dung khác.'], 409);
            }
            return $this->messageResponse($previous);
        }

        // Build request to AI Service
        try {
            $response = $this->callAiService(
                $session,
                $message,
                $useTools,
                $requestId,
                $userId,
                $userId ? $request->bearerToken() : null,
            );
            
            $aiResponse = $response->json();
            if (!$response->successful() || !is_array($aiResponse) || ($aiResponse['intent'] ?? '') === 'error' || !is_string($aiResponse['reply'] ?? null) || trim($aiResponse['reply']) === '') {
                Log::warning('AI Service failed', ['request_id' => $requestId, 'status' => $response->status()]);
                return response()->json([
                    'success' => false,
                    'message' => $response->status() === 504 ? 'Trợ lý phản hồi quá lâu. Vui lòng thử lại.' : 'Trợ lý tạm thời gián đoạn. Vui lòng thử lại.',
                    'request_id' => $requestId,
                ], $response->status() === 504 ? 504 : 503);
            }
            $aiContent = $aiResponse['reply'];
        } catch (ConnectionException $e) {
            Log::warning('AI Service connection failed', ['request_id' => $requestId]);
            return response()->json(['success' => false, 'message' => 'Chưa kết nối được trợ lý. Vui lòng thử lại.', 'request_id' => $requestId], 504);
        }

        return DB::transaction(function () use ($session, $message, $aiResponse, $aiContent, $requestId, $useTools, $userId) {
            $userMessage = ChatMessage::create([
                'session_id' => $session->id, 'role' => 'user', 'content' => $message,
                'metadata' => ['request_id' => $requestId],
            ]);
            // Prepare metadata
            $metadata = [
                'request_id' => $requestId,
                'intent' => $aiResponse['intent'] ?? 'unknown',
                'use_tools' => $useTools,
            ];

            // Quick action: a UI affordance the frontend renders next to the
            // reply (e.g. a button that opens the reservation form). Only the
            // action TYPE crosses the boundary — the payload is rendered by
            // the frontend, so a model cannot smuggle data through it.
            $quickAction = $this->sanitizeQuickAction($aiResponse['quick_action'] ?? null);
            if ($quickAction) {
                $metadata['quick_action'] = $quickAction;
            }
            $menuSuggestions = $this->menuSuggestionsInReply($aiContent);
            if ($menuSuggestions) {
                $metadata['menu_suggestions'] = $menuSuggestions;
            }

            // Handle confirmation flow for write tools
            $confirmationRequired = false;
            $pendingActionIds = [];
            $pendingActions = [];

            if (isset($aiResponse['confirmation_required']) && $aiResponse['confirmation_required']) {
                $toolCalls = $aiResponse['tool_calls'] ?? [];
            
                // Check if user is authenticated for write operations
                if (!$userId) {
                    // Guest trying write tool - return login message
                    $aiContent = "Xin quý khách hãy đăng nhập để sử dụng dịch vụ nhé.";
                } else {
                    // Create pending actions for each write tool
                    foreach ($toolCalls as $toolCall) {
                        if (in_array($toolCall['tool'] ?? '', ['cart_add_item'])
                            && ($toolCall['error_code'] ?? null) === 'CONFIRMATION_REQUIRED') {
                            $pendingAction = $this->createPendingAction(
                                $session,
                                $userMessage,
                                $userId,
                                $toolCall,
                                $requestId
                            );
                        
                            if ($pendingAction) {
                                $pendingActionIds[] = $pendingAction->id;
                                $pendingActions[] = $pendingAction;
                                $confirmationRequired = true;
                            }
                        }
                    }
                
                    if ($confirmationRequired) {
                        $metadata['confirmation_required'] = true;
                        $metadata['pending_action_ids'] = $pendingActionIds;
                    }
                }
            }

            // Save AI response
            $assistantMessage = ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'content' => $aiContent,
                'metadata' => $metadata,
            ]);

            // Update pending actions with message_id
            foreach ($pendingActions as $pa) {
                $pa->update(['message_id' => $assistantMessage->id]);
            }

            // Update session
            $session->touch();

            return $this->messageResponse($assistantMessage);
        });
    }

    private function messageResponse(ChatMessage $message): JsonResponse
    {
        $data = [
            'message_id' => $message->id, 'role' => 'assistant', 'content' => $message->content,
            'intent' => $message->metadata['intent'] ?? 'unknown',
            'request_id' => $message->metadata['request_id'],
        ];
        if (!empty($message->metadata['quick_action'])) {
            $data['quick_action'] = $message->metadata['quick_action'];
        }
        if (!empty($message->metadata['menu_suggestions'])) {
            $data['menu_suggestions'] = $message->metadata['menu_suggestions'];
        }
        if ($message->requiresConfirmation()) {
            $data['confirmation_required'] = true;
            $data['pending_actions'] = ChatPendingAction::whereIn('id', $message->pendingActionIds())->get()->map(fn ($pa) => [
                'id' => $pa->id, 'action_type' => $pa->action_type, 'tool_name' => $pa->tool_name, 'summary' => $pa->summary,
                'status' => $pa->status, 'expires_at' => $pa->expires_at->toIso8601String(),
            ])->values()->all();
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Quick action nào được frontend render.
     *
     * Chỉ những type đã biết mới được chấp nhận — AI service là nguồn
     * không đáng tin, và type lạ sẽ bị frontend bỏ qua. Payload của action
     * không đi qua đây: form đặt bàn tự tải quy định từ API.
     */
    private const QUICK_ACTION_TYPES = [
        'reservation_form' => 'Đặt bàn',
    ];

    /** Link only dishes that the assistant named and that are on the active menu. */
    private function menuSuggestionsInReply(string $reply): array
    {
        $items = DB::table('menu_items as m')
            ->join('categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)
            ->where('c.is_active', true)
            ->get(['m.name', 'm.slug', 'm.image_url', 'm.description']);

        $matches = [];
        foreach ($items as $item) {
            $position = mb_stripos($reply, $item->name);
            if ($position !== false) {
                $matches[] = ['position' => $position, 'name' => $item->name, 'slug' => $item->slug,
                    'image_url' => $item->image_url, 'description' => $item->description];
            }
        }
        usort($matches, fn ($a, $b) => $a['position'] <=> $b['position']);
        return array_map(
            fn ($item) => ['name' => $item['name'], 'slug' => $item['slug'],
                'image_url' => $item['image_url'], 'description' => $item['description']],
            array_slice($matches, 0, 6)
        );
    }

    private function metadataWithCurrentMenu(?array $metadata, $menuItems): ?array
    {
        if (empty($metadata['menu_suggestions'])) return $metadata;

        $metadata['menu_suggestions'] = array_values(array_filter(array_map(
            fn ($suggestion) => isset($menuItems[$suggestion['slug'] ?? '']) ? [
                'name' => $menuItems[$suggestion['slug']]->name,
                'slug' => $suggestion['slug'],
                'image_url' => $menuItems[$suggestion['slug']]->image_url,
                'description' => $menuItems[$suggestion['slug']]->description,
            ] : null,
            $metadata['menu_suggestions']
        )));
        return $metadata;
    }

    private function sanitizeQuickAction(?array $quickAction): ?array
    {
        if (!is_array($quickAction)) {
            return null;
        }

        $type = $quickAction['type'] ?? null;
        if (!is_string($type) || !isset(self::QUICK_ACTION_TYPES[$type])) {
            return null;
        }

        return [
            'type' => $type,
            'label' => $quickAction['label'] ?? self::QUICK_ACTION_TYPES[$type],
            'title' => is_string($quickAction['title'] ?? null) ? $quickAction['title'] : '',
        ];
    }

    /**
     * POST /api/chatbot/sessions/{id}/close
     * Close a chat session.
     */
    public function closeSession(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSessionsQuery($request)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên chat.',
            ], 404);
        }

        $session->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'status' => $session->status,
            ],
        ]);
    }

    /**
     * POST /api/chatbot/pending-actions/{id}/confirm
     * Confirm and execute a pending action.
     */
    public function confirmPendingAction(Request $request, int $id): JsonResponse
    {
        return DB::transaction(fn () => $this->confirmLockedAction($request, $id));
    }

    private function confirmLockedAction(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'unauthorized',
                'message' => 'Vui lòng đăng nhập để xác nhận.',
            ], 401);
        }

        $pendingAction = ChatPendingAction::lockForUpdate()->find($id);

        if (!$pendingAction) {
            return response()->json([
                'success' => false,
                'error' => 'not_found',
                'message' => 'Không tìm thấy yêu cầu.',
            ], 404);
        }

        // Check ownership
        if (!$pendingAction->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'error' => 'forbidden',
                'message' => 'Bạn không có quyền xác nhận yêu cầu này.',
            ], 403);
        }

        // Check status
        if ($pendingAction->status === 'confirmed') {
            return response()->json(['success' => true, 'data' => [
                'pending_action_id' => $pendingAction->id, 'status' => 'confirmed',
                'action_type' => $pendingAction->action_type, 'result' => $pendingAction->result,
                'confirmed_at' => $pendingAction->confirmed_at->toIso8601String(),
            ]]);
        }
        if ($pendingAction->status !== 'pending') {
            return response()->json([
                'success' => false,
                'error' => 'pending_action_not_pending',
                'message' => 'Yêu cầu này đã được xử lý trước đó.',
            ], 409);
        }

        // Check expiration
        if (!$pendingAction->isValid()) {
            $pendingAction->update([
                'status' => 'expired',
                'error_message' => 'Yêu cầu đã hết hạn.',
            ]);

            return response()->json([
                'success' => false,
                'error' => 'pending_action_expired',
                'message' => 'Yêu cầu đã hết hạn. Vui lòng gửi lại tin nhắn.',
            ], 410);
        }

        // Execute action atomically
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($pendingAction, $user) {
                $pendingAction->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);

                return $this->actionExecutor->execute($pendingAction, $user);
            });

            $pendingAction->update(['result' => $result]);

            Log::info('Chatbot pending action confirmed', [
                'request_id' => $pendingAction->request_id,
                'user_id' => $user->id,
                'action_type' => $pendingAction->action_type,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'pending_action_id' => $pendingAction->id,
                    'status' => 'confirmed',
                    'action_type' => $pendingAction->action_type,
                    'result' => $result,
                    'confirmed_at' => $pendingAction->confirmed_at->toIso8601String(),
                ],
            ]);

        } catch (ValidationException $e) {
            $pendingAction->update([
                'status' => 'failed',
                'error_message' => $e->validator->errors()->first(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'action_execution_failed',
                'message' => 'Không thể thực hiện: ' . $e->validator->errors()->first(),
            ], 502);

        } catch (\Exception $e) {
            $pendingAction->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Pending action execution failed', [
                'pending_action_id' => $pendingAction->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'action_execution_failed',
                'message' => 'Chưa thể thực hiện yêu cầu. Vui lòng thử lại.',
            ], 502);
        }
    }

    /**
     * POST /api/chatbot/pending-actions/{id}/cancel
     * Cancel a pending action.
     */
    public function cancelPendingAction(Request $request, int $id): JsonResponse
    {
        return DB::transaction(fn () => $this->cancelLockedAction($request, $id));
    }

    private function cancelLockedAction(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'unauthorized',
                'message' => 'Vui lòng đăng nhập để hủy.',
            ], 401);
        }

        $pendingAction = ChatPendingAction::lockForUpdate()->find($id);

        if (!$pendingAction) {
            return response()->json([
                'success' => false,
                'error' => 'not_found',
                'message' => 'Không tìm thấy yêu cầu.',
            ], 404);
        }

        // Check ownership
        if (!$pendingAction->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'error' => 'forbidden',
                'message' => 'Bạn không có quyền hủy yêu cầu này.',
            ], 403);
        }

        // Idempotent - allow cancel even if already cancelled
        if ($pendingAction->status !== 'pending') {
            return response()->json([
                'success' => true,
                'data' => [
                    'pending_action_id' => $pendingAction->id,
                    'status' => $pendingAction->status,
                    'message' => 'Yêu cầu đã ở trạng thái: ' . $pendingAction->status,
                ],
            ]);
        }

        $pendingAction->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Log::info('Chatbot pending action cancelled', [
            'pending_action_id' => $pendingAction->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'pending_action_id' => $pendingAction->id,
                'status' => 'cancelled',
            ],
        ]);
    }

    /**
     * GET /api/chatbot/pending-actions/{id}
     * Get pending action details.
     */
    public function getPendingAction(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'unauthorized',
                'message' => 'Vui lòng đăng nhập.',
            ], 401);
        }

        $pendingAction = ChatPendingAction::find($id);

        if (!$pendingAction) {
            return response()->json([
                'success' => false,
                'error' => 'not_found',
                'message' => 'Không tìm thấy yêu cầu.',
            ], 404);
        }

        // Check ownership
        if (!$pendingAction->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'error' => 'forbidden',
                'message' => 'Bạn không có quyền xem yêu cầu này.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $pendingAction->id,
                'action_type' => $pendingAction->action_type,
                'tool_name' => $pendingAction->tool_name,
                'status' => $pendingAction->status,
                'summary' => $pendingAction->summary,
                'expires_at' => $pendingAction->expires_at->toIso8601String(),
                'created_at' => $pendingAction->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/chatbot/sessions/{id}/pending-actions
     * Get pending actions for a session (F5 recovery).
     */
    public function getSessionPendingActions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'unauthorized',
                'message' => 'Vui lòng đăng nhập.',
            ], 401);
        }

        $session = $this->ownedSessionsQuery($request)->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phiên chat.',
            ], 404);
        }

        $status = $request->query('status', 'pending');
        
        $query = ChatPendingAction::where('session_id', $id)
            ->where('user_id', $user->id);

        if ($status === 'pending') {
            $query->pending();
        } else {
            $query->where('status', $status);
        }

        $pendingActions = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $id,
                'items' => $pendingActions->map(fn ($pa) => [
                    'id' => $pa->id,
                    'action_type' => $pa->action_type,
                    'summary' => $pa->summary,
                    'status' => $pa->status,
                    'expires_at' => $pa->expires_at->toIso8601String(),
                    'message_id' => $pa->message_id,
                ])->values(),
            ],
        ]);
    }

    /**
     * Create a pending action from tool call.
     */
    private function createPendingAction(
        ChatSession $session,
        ChatMessage $message,
        int $userId,
        array $toolCall,
        string $requestId
    ): ?ChatPendingAction {
        $toolName = $toolCall['tool'] ?? '';
        $args = $toolCall['args'] ?? [];

        // Build summary for UI
        $summary = $this->summaryBuilder->build($toolName, $args);

        // Determine Laravel endpoint
        $endpoint = match ($toolName) {
            'cart_add_item' => '/api/cart',
            default => '/api/unknown',
        };

        return ChatPendingAction::create([
            'idempotency_key' => Str::uuid()->toString(),
            'session_id' => $session->id,
            'user_id' => $userId,
            'action_type' => $toolName,
            'tool_name' => $toolName,
            'payload' => $args,
            'endpoint' => $endpoint,
            'summary' => $summary,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Call AI Service with chat message.
     */
    private function callAiService(
        ChatSession $session,
        string $message,
        bool $useTools,
        string $requestId,
        ?int $userId = null,
        ?string $userToken = null
    ): \Illuminate\Http\Client\Response {
        // Build messages history (last 10 for context)
        $messages = $session->messages()
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])
            ->toArray();

        // Add current message
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $payload = [
            'session_id' => "sess_{$session->id}",
            'message' => $message,
            'request_id' => $requestId,
            'user_id' => $userId,
            'messages' => $messages,
            'use_tools' => $useTools,
        ];

        // Forward the customer's Sanctum token so AI Service can call
        // authenticated Laravel endpoints (cart, orders, voucher preview).
        if ($userToken) {
            $payload['user_token'] = $userToken;
        }

        Log::info('Calling AI Service', [
            'session_id' => $session->id,
            'request_id' => $requestId,
            'use_tools' => $useTools,
            'message_length' => strlen($message),
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->aiServiceToken) {
            $headers['X-Internal-Token'] = $this->aiServiceToken;
        }

        $url = rtrim($this->aiServiceUrl, '/') . '/api/v1/chat';

        $response = Http::connectTimeout(3)->timeout($this->aiServiceTimeout)
            ->withHeaders($headers)
            ->post($url, $payload);

        return $response;
    }
}
