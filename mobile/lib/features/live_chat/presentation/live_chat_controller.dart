import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/config.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/live_chat_repository.dart';
import '../data/reverb_client.dart';
import '../domain/live_chat_models.dart';

/// Trạng thái màn hình tư vấn trực tiếp.
enum LiveChatViewStatus { loading, ready, requiresLogin, failed }

/// ============================================================
/// 💬 LIVE CHAT CONTROLLER — tư vấn trực tiếp với nhân viên
/// ============================================================
///
/// Khác chatbot ở hai điểm:
///
///  1. CHỈ khách đã đăng nhập mới vào được. Khách vãng lai thấy lời mời
///     đăng nhập thay vì màn trống.
///  2. Nhân viên nhận phiên khi khách đã NÓI, không phải khi khách mở
///     widget — nếu không, hàng chờ của mọi nhân viên sẽ đầy phiên rỗng.
///
/// Tin nhắn đến qua realtime nên không cần bấm "tải lại" để thấy nhân viên
/// đã phản hồi.
class LiveChatState {
  const LiveChatState({
    this.status = LiveChatViewStatus.loading,
    this.sessionId,
    this.sessionStatus,
    this.staffName,
    this.messages = const [],
    this.isSending = false,
    this.isConnected = false,
    this.error,
  });

  final LiveChatViewStatus status;
  final int? sessionId;
  final LiveChatStatus? sessionStatus;
  final String? staffName;
  final List<LiveChatMessage> messages;
  final bool isSending;

  /// Đang có kết nối realtime.
  final bool isConnected;
  final String? error;

  /// Khách đã nói ít nhất một lượt.
  ///
  /// Cờ này quyết định có hiện dòng "Đang chờ nhân viên tư vấn trả lời" hay
  /// không — mở widget mà chưa nói gì thì hiện dòng đó là vô nghĩa.
  bool get hasCustomerMessage => messages.any((m) => m.isCustomer);

  /// Nhân viên đã nhận phiên.
  bool get hasStaff =>
      staffName != null && staffName!.isNotEmpty && sessionStatus == LiveChatStatus.accepted;

  bool get canSend =>
      sessionId != null &&
      !isSending &&
      sessionStatus != LiveChatStatus.closed &&
      status == LiveChatViewStatus.ready;

  bool get isEmpty => messages.isEmpty;

  LiveChatState copyWith({
    LiveChatViewStatus? status,
    int? sessionId,
    LiveChatStatus? sessionStatus,
    String? staffName,
    List<LiveChatMessage>? messages,
    bool? isSending,
    bool? isConnected,
    String? error,
    bool clearError = false,
    bool clearSession = false,
  }) {
    return LiveChatState(
      status: status ?? this.status,
      sessionId: clearSession ? null : (sessionId ?? this.sessionId),
      sessionStatus: clearSession ? null : (sessionStatus ?? this.sessionStatus),
      staffName: clearSession ? null : (staffName ?? this.staffName),
      messages: clearSession ? const [] : (messages ?? this.messages),
      isSending: isSending ?? this.isSending,
      isConnected: isConnected ?? this.isConnected,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final liveChatControllerProvider =
    StateNotifierProvider<LiveChatController, LiveChatState>((ref) {
  return LiveChatController(
    ref.watch(liveChatRepositoryProvider),
    ref.watch(reverbClientProvider),
    ref.read(authControllerProvider.notifier),
  );
});

class LiveChatController extends StateNotifier<LiveChatState> {
  LiveChatController(this._repo, this._reverb, this._auth)
      : super(const LiveChatState()) {
    _listenRealtime();
    // Mở phiên ngay nếu khách đã đăng nhập; nếu không thì màn hiện lời mời.
    if (_auth.state.isAuthenticated) {
      _load();
    } else {
      state = state.copyWith(status: LiveChatViewStatus.requiresLogin);
    }
  }

  final LiveChatRepository _repo;
  final ReverbClient _reverb;
  final AuthController _auth;

  static const _storage = FlutterSecureStorage();

  int? get _userId => _auth.state.user?.id;

  StreamSubscription<Map<String, dynamic>>? _events;

  /// Đăng ký lại realtime khi khách đăng nhập xong (từ màn đăng nhập quay lại).
  void onAuthChanged() {
    if (_auth.state.isAuthenticated) {
      _load();
    } else {
      state = state.copyWith(
        status: LiveChatViewStatus.requiresLogin,
        clearSession: true,
      );
    }
  }

  /// Mở hoặc khôi phục phiên.
  Future<void> _load() async {
    state = state.copyWith(status: LiveChatViewStatus.loading, clearError: true);
    try {
      final saved = await _readStoredSessionId();
      if (saved != null) {
        try {
          final session = await _repo.loadSession(saved);
          if (!session.status.isClosed) {
            _applySession(session);
            return;
          }
        } catch (_) {
          // Phiên cũ không dùng được (đã xoá / hết hạn) → tạo phiên mới.
          await _clearStoredSessionId();
        }
      }
      await _startNewSession();
    } on ApiException catch (e) {
      _handleAuthFailure(e, fallback: e.message);
    } catch (_) {
      state = state.copyWith(
        status: LiveChatViewStatus.failed,
        error: 'Không thể bắt đầu tư vấn trực tiếp. Vui lòng thử lại.',
      );
    }
  }

  Future<void> _startNewSession() async {
    final session = await _repo.startSession();
    await _saveSessionId(session.id);
    _applySession(session);
  }

  void _applySession(LiveChatSession session) {
    state = state.copyWith(
      status: LiveChatViewStatus.ready,
      sessionId: session.id,
      sessionStatus: session.status,
      staffName: session.assignedStaffName,
      messages: session.messages,
      clearError: true,
    );
    unawaited(_reverb.subscribe('live-chat-session.${session.id}'));
  }

  /// Gửi tin nhắn của khách.
  ///
  /// Tin hiện ngay (optimistic) để không phải chờ mạng, và bị gỡ lại nếu gửi
  /// hỏng — khách không được nghĩ là đã gửi thành công.
  Future<void> send(String text) async {
    final content = text.trim();
    if (content.isEmpty || !state.canSend) return;

    final sessionId = state.sessionId!;

    state = state.copyWith(
      messages: [
        ...state.messages,
        LiveChatMessage(
          sender: LiveChatSender.customer,
          content: content,
          createdAt: DateTime.now(),
        ),
      ],
      isSending: true,
      clearError: true,
    );

    try {
      final sent = await _repo.sendMessage(sessionId, content);
      // Tin optimistic chỉ là để phản hồi tức thì; tin thật từ server thay
      // thế nó — nếu giữ cả hai, khách thấy tin mình bị lặp đôi.
      _removeOptimistic(content);
      state = state.copyWith(isSending: false);
      _appendMessage(sent);
    } on ApiException catch (e) {
      _removeOptimistic(content);
      _handleAuthFailure(e, fallback: 'Chưa gửi được tin nhắn. Vui lòng thử lại.');
    } catch (_) {
      _removeOptimistic(content);
      state = state.copyWith(
        isSending: false,
        error: 'Chưa gửi được tin nhắn. Vui lòng thử lại.',
      );
    }
  }

  /// Kết thúc phiên tư vấn.
  Future<void> closeSession() async {
    final sessionId = state.sessionId;
    if (sessionId == null || state.sessionStatus == LiveChatStatus.closed) return;

    try {
      final session = await _repo.closeSession(sessionId);
      state = state.copyWith(
        sessionStatus: session.status,
        isConnected: false,
      );
      await _reverb.disconnect();
    } catch (_) {
      state = state.copyWith(error: 'Không kết thúc được cuộc tư vấn.');
    }
  }

  /// Bắt đầu phiên mới (sau khi phiên cũ đã đóng).
  Future<void> startNewChat() async {
    if (state.isSending) return;
    await _clearStoredSessionId();
    await _reverb.disconnect();
    state = state.copyWith(clearSession: true, status: LiveChatViewStatus.loading);
    await _load();
  }

  void clearError() {
    state = state.copyWith(clearError: true);
  }

  // ── Realtime ──────────────────────────────────────────────

  void _listenRealtime() {
    _events = _reverb.events.listen(_handleEvent);
  }

  void _handleEvent(Map<String, dynamic> data) {
    // Chỉ quan tâm tin nhắn mới.
    final message = data['message'];
    if (message is Map) {
      _appendMessage(
        LiveChatMessage.fromJson(Map<String, dynamic>.from(message)),
      );
    }

    // Trạng thái phiên có thể vừa đổi (nhân viên vừa nhận).
    final session = data['session'];
    if (session is Map) {
      final parsed = LiveChatSession.fromJson(Map<String, dynamic>.from(session));
      state = state.copyWith(
        sessionStatus: parsed.status,
        staffName: parsed.assignedStaffName ?? state.staffName,
        isConnected: true,
      );
    }
  }

  void _appendMessage(LiveChatMessage message) {
    final id = message.id;
    // Tin của chính mình cũng đến qua realtime → chống hiện hai lần.
    if (id != null && state.messages.any((m) => m.id == id)) return;
    state = state.copyWith(messages: [...state.messages, message], isConnected: true);
  }

  void _removeOptimistic(String content) {
    state = state.copyWith(
      messages: state.messages
          .where((m) => !(m.isCustomer && m.content == content && m.id == null))
          .toList(),
    );
  }
  // ── 401 ───────────────────────────────────────────────────

  /// Backend trả 401 khi khách chưa đăng nhập — đó không phải lỗi mà là trạng
  /// thái bình thường của màn hình này.
  void _handleAuthFailure(ApiException error, {required String fallback}) {
    final unauthorized =
        error.isUnauthorized || error.message.contains('đăng nhập');

    if (unauthorized) {
      state = state.copyWith(
        status: LiveChatViewStatus.requiresLogin,
        clearSession: true,
      );
      return;
    }

    state = state.copyWith(
      status: LiveChatViewStatus.failed,
      error: error.message.isNotEmpty ? error.message : fallback,
    );
  }

  // ── Lưu phiên ─────────────────────────────────────────────

  Future<int?> _readStoredSessionId() async {
    try {
      return int.tryParse(
        await _storage.read(key: AppConfig.liveChatSessionKey(_userId)) ?? '',
      );
    } catch (_) {
      return null;
    }
  }

  Future<void> _saveSessionId(int id) async {
    try {
      await _storage.write(
        key: AppConfig.liveChatSessionKey(_userId),
        value: '$id',
      );
    } catch (_) {
      // Mất phiên đã lưu không chặn được phiên đang chạy.
    }
  }

  Future<void> _clearStoredSessionId() async {
    try {
      await _storage.delete(key: AppConfig.liveChatSessionKey(_userId));
    } catch (_) {
      // Bỏ qua.
    }
  }

  @override
  void dispose() {
    _events?.cancel();
    unawaited(_reverb.disconnect());
    super.dispose();
  }
}