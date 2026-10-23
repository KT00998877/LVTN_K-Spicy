import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/chat_repository.dart';
import '../domain/chat_message.dart';

/// Trạng thái của màn hội thoại.
enum ChatStatus { loading, ready, failed }

/// ============================================================
/// 🧠 CHAT CONTROLLER — quản lý hội thoại với trợ lý
/// ============================================================
///
/// Màn chat trước đây tự quản lý mọi thứ bằng `setState` và biến `Map`
/// thô, nên mỗi thao tác (gửi, xác nhận, khôi phục) đều phải tự nhớ trạng
/// thái. Ở đây trạng thái nằm trong một [ChatState] rõ ràng để màn hình chỉ
/// cần vẽ theo state.
class ChatState {
  const ChatState({
    this.status = ChatStatus.loading,
    this.sessionId,
    this.messages = const [],
    this.pendingActions = const [],
    this.isSending = false,
    this.error,
    this.waitSeconds = 0,
  });

  final ChatStatus status;
  final int? sessionId;
  final List<ChatMessage> messages;
  final List<PendingAction> pendingActions;

  /// Đang chờ trợ lý trả lời.
  final bool isSending;

  /// Thông báo lỗi hiển thị trong hội thoại.
  final String? error;

  /// Số giây đã chờ trong lượt hiện tại (để khách biết mình chờ bao lâu).
  final int waitSeconds;

  bool get canSend => sessionId != null && !isSending && status == ChatStatus.ready;

  bool get isEmpty => messages.isEmpty;

  ChatState copyWith({
    ChatStatus? status,
    int? sessionId,
    List<ChatMessage>? messages,
    List<PendingAction>? pendingActions,
    bool? isSending,
    String? error,
    int? waitSeconds,
    bool clearError = false,
    bool clearSession = false,
  }) {
    return ChatState(
      status: status ?? this.status,
      sessionId: clearSession ? null : (sessionId ?? this.sessionId),
      messages: messages ?? this.messages,
      pendingActions: pendingActions ?? this.pendingActions,
      isSending: isSending ?? this.isSending,
      error: clearError ? null : (error ?? this.error),
      waitSeconds: waitSeconds ?? this.waitSeconds,
    );
  }
}

final chatControllerProvider =
    StateNotifierProvider<ChatController, ChatState>((ref) {
  return ChatController(
    ref.watch(chatRepositoryProvider),
    ref.read(authControllerProvider.notifier),
  );
});

class ChatController extends StateNotifier<ChatState> {
  ChatController(this._repo, this._auth) : super(const ChatState()) {
    _load();
  }

  final ChatRepository _repo;
  final AuthController _auth;

  /// Id tài khoản hiện tại — dùng để tách phiên chat của từng khách.
  int? get _userId => _auth.state.user?.id;

  Timer? _waitTimer;

  /// Mở hoặc khôi phục phiên chat.
  Future<void> _load() async {
    state = state.copyWith(
      status: ChatStatus.loading,
      clearError: true,
    );
    try {
      final saved = await _repo.readStoredSessionId(_userId);
      if (saved != null) {
        try {
          final session = await _repo.loadSession(saved);
          if (session['status'] == 'active') {
            final raw = session['messages'];
            final messages = raw is List
                ? raw
                    .whereType<Map>()
                    .map((m) => ChatMessage.fromJson(Map<String, dynamic>.from(m)))
                    .where((m) => m.role == ChatRole.user || m.role == ChatRole.assistant)
                    .toList()
                : <ChatMessage>[];
            state = state.copyWith(
              status: ChatStatus.ready,
              sessionId: saved,
              messages: messages,
              pendingActions: await _loadPendingActions(saved),
            );
            return;
          }
        } catch (_) {
          // Phiên cũ không dùng được nữa → bỏ và tạo phiên mới.
          await _repo.clearStoredSessionId(_userId);
        }
      }
      await _startNewSession();
    } on ApiException catch (e) {
      state = state.copyWith(
        status: ChatStatus.failed,
        error: e.message,
      );
    } catch (_) {
      state = state.copyWith(
        status: ChatStatus.failed,
        error: 'Không thể kết nối trợ lý. Vui lòng thử lại.',
      );
    }
  }

  Future<void> _startNewSession() async {
    final id = await _repo.startSession();
    await _repo.saveSessionId(_userId, id);
    state = state.copyWith(
      status: ChatStatus.ready,
      sessionId: id,
      messages: const [],
      pendingActions: const [],
      clearError: true,
    );
  }

  /// Các thao tác đang chờ xác nhận. Chỉ tài khoản đã đăng nhập mới có.
  Future<List<PendingAction>> _loadPendingActions(int sessionId) async {
    if (!_auth.state.isAuthenticated) return const [];
    try {
      final raw = await _repo.pendingActions(sessionId);
      return raw.map(PendingAction.fromJson).where((a) => a.isPending).toList();
    } catch (_) {
      return const [];
    }
  }

  /// Bắt đầu hội thoại mới — xoá lịch sử và mở phiên khác.
  Future<void> startNewChat() async {
    if (state.isSending) return;
    _stopWaitTimer();
    state = state.copyWith(
      status: ChatStatus.loading,
      isSending: false,
      clearError: true,
    );
    try {
      await _repo.clearStoredSessionId(_userId);
      await _repo.clearGuestToken();
      await _startNewSession();
    } catch (e) {
      state = state.copyWith(
        status: ChatStatus.failed,
        error: e is ApiException ? e.message : 'Không thể tạo hội thoại mới.',
      );
    }
  }

  /// Gửi tin nhắn của khách.
  ///
  /// Tin nhắn được hiện ngay (optimistic) để không phải chờ mạng, và bị gỡ
  /// lại nếu gửi hỏng — trả lời sai còn tệ hơn chờ.
  Future<void> send(String text) async {
    final message = text.trim();
    if (message.isEmpty || !state.canSend) return;

    final sessionId = state.sessionId!;
    final clientMessageId = const Uuid().v4();

    state = state.copyWith(
      messages: [
        ...state.messages,
        ChatMessage(role: ChatRole.user, content: message),
      ],
      isSending: true,
      clearError: true,
      waitSeconds: 0,
    );
    _startWaitTimer();

    try {
      final reply = await _repo.sendMessage(
        sessionId,
        message,
        clientMessageId: clientMessageId,
      );
      _stopWaitTimer();

      final assistant = ChatMessage(
        id: reply['message_id'] as int?,
        role: ChatRole.assistant,
        content: (reply['content'] ?? '').toString(),
        createdAt: DateTime.now(),
        menuSuggestions: _parseSuggestions(reply['menu_suggestions']),
        quickAction: QuickAction.tryParse(reply['quick_action']),
        animate: true,
      );

      final rawActions = reply['pending_actions'];
      final actions = rawActions is List
          ? rawActions
              .whereType<Map>()
              .map((a) => PendingAction.fromJson(Map<String, dynamic>.from(a)))
              .toList()
          : const <PendingAction>[];

      state = state.copyWith(
        messages: [...state.messages, assistant],
        pendingActions: [...state.pendingActions, ...actions],
        isSending: false,
      );
    } on ApiException catch (e) {
      _stopWaitTimer();
      // Gỡ tin nhắn chưa gửi được để khách không tưởng đã gửi thành công.
      state = state.copyWith(
        messages: state.messages
            .where((m) => !(m.isUser && m.content == message))
            .toList(),
        isSending: false,
        error: e.message,
      );
    } catch (_) {
      _stopWaitTimer();
      state = state.copyWith(
        messages: state.messages
            .where((m) => !(m.isUser && m.content == message))
            .toList(),
        isSending: false,
        error: 'Chưa gửi được tin nhắn. Vui lòng thử lại.',
      );
    }
  }

  /// Xác nhận hoặc huỷ một thao tác đang chờ.
  Future<void> resolveAction(int id, {required bool confirm}) async {
    if (state.isSending) return;
    state = state.copyWith(isSending: true, clearError: true);
    try {
      await _repo.resolveAction(id, confirm: confirm);
      state = state.copyWith(
        pendingActions:
            state.pendingActions.where((a) => a.id != id).toList(),
        isSending: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(isSending: false, error: e.message);
    } catch (_) {
      state = state.copyWith(
        isSending: false,
        error: 'Không thể xử lý yêu cầu. Vui lòng thử lại.',
      );
    }
  }

  /// Thử lại sau khi có lỗi kết nối.
  Future<void> retry() => _load();

  void clearError() {
    state = state.copyWith(clearError: true);
  }

  void _startWaitTimer() {
    _waitTimer?.cancel();
    _waitTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!state.isSending) return;
      state = state.copyWith(waitSeconds: state.waitSeconds + 1);
    });
  }

  void _stopWaitTimer() {
    _waitTimer?.cancel();
    _waitTimer = null;
  }

  static List<MenuSuggestion> _parseSuggestions(Object? raw) {
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((e) => MenuSuggestion.fromJson(Map<String, dynamic>.from(e)))
        .where((s) => s.slug.isNotEmpty)
        .toList();
  }

  @override
  void dispose() {
    _stopWaitTimer();
    super.dispose();
  }
}
