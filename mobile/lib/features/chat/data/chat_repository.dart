import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/dio_client.dart';

final chatRepositoryProvider = Provider<ChatRepository>(
  (ref) => ChatRepository(ref.watch(dioProvider)),
);

class ChatRepository {
  ChatRepository(this._dio);
  final Dio _dio;

  Future<int> startSession() async {
    final response = await _dio.post<Map<String, dynamic>>('/chatbot/sessions');
    return (response.data!['data'] as Map<String, dynamic>)['id'] as int;
  }

  Future<Map<String, dynamic>> loadSession(int id) async {
    final response = await _dio.get<Map<String, dynamic>>('/chatbot/sessions/$id');
    return Map<String, dynamic>.from(response.data!['data'] as Map);
  }

  Future<Map<String, dynamic>> sendMessage(int id, String message) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/chatbot/sessions/$id/messages',
      data: {'message': message, 'use_tools': true},
      options: Options(receiveTimeout: const Duration(seconds: 75)),
    );
    return Map<String, dynamic>.from(response.data!['data'] as Map);
  }

  Future<List<Map<String, dynamic>>> pendingActions(int id) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/chatbot/sessions/$id/pending-actions',
    );
    final data = response.data!['data'] as Map<String, dynamic>;
    return (data['items'] as List)
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();
  }

  Future<void> resolveAction(int id, {required bool confirm}) async {
    await _dio.post('/chatbot/pending-actions/$id/${confirm ? 'confirm' : 'cancel'}');
  }
}
