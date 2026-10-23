import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/dio_client.dart';
import '../domain/live_chat_models.dart';

final liveChatRepositoryProvider = Provider<LiveChatRepository>(
  (ref) => LiveChatRepository(ref.watch(dioProvider)),
);

/// ============================================================
/// 💬 LIVE CHAT REPOSITORY — gọi API tư vấn trực tiếp của Laravel
/// ============================================================
///
/// Khác chatbot ở một điểm quan trọng: KHÔNG có chat gateway riêng. Tư vấn
/// trực tiếp không gọi AI Service nên không có vòng gọi lại vào business API
/// — mọi request đi thẳng qua backend, cùng đường với menu/đơn hàng.
class LiveChatRepository {
  LiveChatRepository(this._dio);
  final Dio _dio;

  /// Timeout cho các request của live chat (nhanh — không có AI trong đường).
  static const _timeout = Duration(seconds: 20);

  /// Mở phiên tư vấn (hoặc nhận lại phiên đang mở của chính mình).
  ///
  /// Yêu cầu đăng nhập — backend trả 401 nếu khách chưa đăng nhập.
  Future<LiveChatSession> startSession({String? topic}) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/live-chat/sessions',
      data: topic == null || topic.isEmpty ? null : {'topic': topic},
      options: Options(receiveTimeout: _timeout),
    );
    return LiveChatSession.fromJson(
      Map<String, dynamic>.from(response.data!['data'] as Map),
    );
  }

  /// Lấy phiên + lịch sử tin nhắn (khôi phục sau khi app bị tắt).
  Future<LiveChatSession> loadSession(int id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/live-chat/sessions/$id',
      options: Options(receiveTimeout: _timeout),
    );
    return LiveChatSession.fromJson(
      Map<String, dynamic>.from(response.data!['data'] as Map),
    );
  }

  /// Gửi tin nhắn tới nhân viên.
  Future<LiveChatMessage> sendMessage(int sessionId, String content) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/live-chat/sessions/$sessionId/messages',
      data: {'content': content},
      options: Options(receiveTimeout: _timeout),
    );
    final data = Map<String, dynamic>.from(response.data!['data'] as Map);
    return LiveChatMessage.fromJson(
      Map<String, dynamic>.from(data['message'] as Map),
    );
  }

  /// Kết thúc phiên.
  Future<LiveChatSession> closeSession(int sessionId) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/live-chat/sessions/$sessionId/close',
      options: Options(receiveTimeout: _timeout),
    );
    return LiveChatSession.fromJson(
      Map<String, dynamic>.from(response.data!['data'] as Map),
    );
  }

  /// Xác thực kênh WebSocket — endpoint dùng chung cho cả khách và nhân viên.
  ///
  /// Trả về `auth` (HMAC) mà Reverb dùng để cấp quyền nghe kênh.
  Future<Map<String, dynamic>> authenticateChannel({
    required String socketId,
    required String channelName,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/live-chat/broadcast-auth',
      data: {'socket_id': socketId, 'channel_name': channelName},
      options: Options(receiveTimeout: _timeout),
    );
    return Map<String, dynamic>.from(response.data as Map);
  }
}