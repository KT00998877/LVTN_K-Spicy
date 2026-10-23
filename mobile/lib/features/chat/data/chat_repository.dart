import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/dio_client.dart';
import '../../../core/config.dart';

final chatRepositoryProvider = Provider<ChatRepository>(
  (ref) => ChatRepository(ref.watch(dioProvider)),
);

/// ============================================================
/// 💬 CHAT REPOSITORY — gọi API chatbot của Laravel
/// ============================================================
///
/// Mọi request đi qua chat gateway (xem [ApiConfig.chatBaseUrl]) nên không
/// bị bế tắc với AI Service như khi gọi chung business API.
///
/// Lớp này cũng giữ lưu trạng thái phiên (id phiên, guest token) trong
/// secure storage: đó là dữ liệu của tầng data, giữ ở đây giúp tầng
/// presentation không cần biết chi tiết lưu trữ.
class ChatRepository {
  ChatRepository(this._dio);
  final Dio _dio;

  static const _storage = FlutterSecureStorage();

  /// Timeout cho một lượt chat.
  ///
  /// Lượt chat gọi Gemini nên có thể chậm; ngân sách thời gian của server là
  /// AI 70s < Laravel 75s < PHP 90s. Client đặt 95s để chờ được chính câu
  /// trả lời lỗi của server thay vì tự cắt ngang.
  static const _chatTimeout = Duration(seconds: 95);

  /// Timeout cho các request nhanh (tạo/khôi phục phiên).
  static const _quickTimeout = Duration(seconds: 20);

  // ── Lưu trữ phiên ─────────────────────────────────────────

  /// Khoá lưu phiên theo tài khoản — mỗi khách một phiên riêng.
  static String _sessionKey(int? userId) => 'customer_chat_session_${userId ?? 0}';

  /// Id phiên đã lưu, hoặc null nếu chưa có / không đọc được.
  Future<int?> readStoredSessionId(int? userId) async {
    try {
      return int.tryParse(await _storage.read(key: _sessionKey(userId)) ?? '');
    } catch (_) {
      // Secure storage hỏng (không có plugin, dữ liệu hỏng) → coi như
      // chưa có phiên, không nên chặn khách vào màn chat.
      return null;
    }
  }

  Future<void> saveSessionId(int? userId, int sessionId) async {
    try {
      await _storage.write(key: _sessionKey(userId), value: '$sessionId');
    } catch (_) {
      // Mất phiên đã lưu không chặn được phiên đang chạy.
    }
  }

  Future<void> clearStoredSessionId(int? userId) async {
    try {
      await _storage.delete(key: _sessionKey(userId));
    } catch (_) {
      // Bỏ qua.
    }
  }

  // ── API ───────────────────────────────────────────────────

  /// Mở phiên chat mới và lưu guest token nếu khách chưa đăng nhập.
  ///
  /// Guest token là thứ duy nhất chứng minh quyền sở hữu phiên có
  /// `user_id = null`; mất nó thì mọi request về phiên đó trả 404.
  Future<int> startSession() async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/chatbot/sessions',
      options: Options(receiveTimeout: _quickTimeout),
    );
    final data = response.data!['data'] as Map<String, dynamic>;
    final guestToken = data['guest_token'] as String?;
    if (guestToken != null && guestToken.isNotEmpty) {
      await _saveGuestToken(guestToken);
    }
    return data['id'] as int;
  }

  Future<Map<String, dynamic>> loadSession(int id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/chatbot/sessions/$id',
      options: Options(receiveTimeout: _quickTimeout),
    );
    return Map<String, dynamic>.from(response.data!['data'] as Map);
  }

  /// Gửi tin nhắn và nhận câu trả lời của trợ lý.
  ///
  /// `clientMessageId` là mã idempotent để gửi lại cùng một lượt chat sau
  /// khi mạng lỗi không tạo ra câu trả lời trùng lặp.
  Future<Map<String, dynamic>> sendMessage(
    int id,
    String message, {
    String? clientMessageId,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/chatbot/sessions/$id/messages',
      data: {
        'message': message,
        'use_tools': true,
        'client_message_id': clientMessageId ?? const Uuid().v4(),
      },
      options: Options(receiveTimeout: _chatTimeout),
    );
    return Map<String, dynamic>.from(response.data!['data'] as Map);
  }

  /// Các thao tác đang chờ khách xác nhận (khôi phục sau khi app bị tắt).
  ///
  /// Endpoint này yêu cầu đăng nhập — khách chưa đăng nhập không có thao tác
  /// ghi nào để chờ, nên trả về rỗng thay vì ném lỗi 401.
  Future<List<Map<String, dynamic>>> pendingActions(int id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/chatbot/sessions/$id/pending-actions',
      options: Options(receiveTimeout: _quickTimeout),
    );
    final data = response.data!['data'] as Map<String, dynamic>;
    return (data['items'] as List)
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();
  }

  Future<void> resolveAction(int id, {required bool confirm}) async {
    await _dio.post(
      '/chatbot/pending-actions/$id/${confirm ? 'confirm' : 'cancel'}',
      options: Options(receiveTimeout: _quickTimeout),
    );
  }

  // ── Guest token ───────────────────────────────────────────

  /// Lưu guest token để các request sau chứng minh được quyền sở hữu phiên
  /// của khách chưa đăng nhập.
  Future<void> _saveGuestToken(String token) async {
    try {
      await _storage.write(key: AppConfig.chatGuestTokenKey, value: token);
    } catch (_) {
      // Không lưu được thì phiên guest sẽ không đọc lại được — nhưng phiên
      // đăng nhập vẫn dùng bình thường.
    }
  }

  /// Xoá guest token đã lưu (khi bắt đầu hội thoại mới).
  Future<void> clearGuestToken() async {
    try {
      await _storage.delete(key: AppConfig.chatGuestTokenKey);
    } catch (_) {
      // Bỏ qua.
    }
  }
}
