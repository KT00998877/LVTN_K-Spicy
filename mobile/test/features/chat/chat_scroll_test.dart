import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/core/config.dart';
import 'package:micay_app/features/chat/data/chat_repository.dart';
import 'package:micay_app/features/chat/presentation/chat_screen.dart';

/// Trả sẵn một phiên chat dài đủ để ListView phải huỷ element ngoài
/// vùng nhìn (đúng điều kiện gây lỗi "tin nhắn cũ tải lại").
class _FakeChatRepository extends ChatRepository {
  _FakeChatRepository(this._messages) : super(Dio());

  final List<Map<String, dynamic>> _messages;

  @override
  Future<int?> readStoredSessionId(int? userId) async => 1;

  @override
  Future<void> saveSessionId(int? userId, int sessionId) async {}

  @override
  Future<Map<String, dynamic>> loadSession(int id) async =>
      {'status': 'active', 'messages': _messages};

  @override
  Future<List<Map<String, dynamic>>> pendingActions(int id) async => [];

  /// Trả lời treo (không bao giờ xong) để giữ trạng thái "đang trả lời".
  @override
  Future<Map<String, dynamic>> sendMessage(
    int id,
    String message, {
    String? clientMessageId,
  }) =>
      Completer<Map<String, dynamic>>().future;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUpAll(ApiConfig.load);

  testWidgets('tin nhắn cũ giữ nguyên element khi cuộn lên', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});

    // 40 lượt hỏi đáp — nhiều hơn viewport 800×600, buộc ListView dựng
    // lazy và huỷ element của các tin nhắn nằm ngoài vùng nhìn.
    final messages = <Map<String, dynamic>>[];
    for (var i = 0; i < 40; i++) {
      messages
        ..add({
          'id': i * 2 + 1,
          'role': 'user',
          'content': 'Câu hỏi số $i',
        })
        ..add({
          'id': i * 2 + 2,
          'role': 'assistant',
          'content': 'Câu trả lời số $i',
        });
    }

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          chatRepositoryProvider
              .overrideWithValue(_FakeChatRepository(messages)),
        ],
        child: const MaterialApp(home: ChatScreen()),
      ),
    );
    await tester.pumpAndSettle();

    // Mở màn hình luôn cuộn xuống cuối — vị trí khách vừa vào.
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();

    // Cuộn lên đầu, tin nhắn cũ đã bị đẩy ra khỏi vùng nhìn.
    await tester.drag(find.byType(ListView), const Offset(0, 20000));
    await tester.pumpAndSettle();

    final oldest = find.text('Câu hỏi số 0');
    expect(oldest, findsOneWidget);
    final elementBefore = tester.element(oldest);

    // Cuộn xuống cuối rồi ngược lại đầu — giống hệt thao tác của khách.
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -20000));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, 20000));
    await tester.pumpAndSettle();

    // Không có keep-alive: element cũ bị huỷ và dựng lại → khác instance,
    // hiệu ứng gõ chữ chạy lại từ 0% và ảnh bị tải lại.
    expect(tester.element(find.text('Câu hỏi số 0')), same(elementBefore));
  });

  testWidgets('ba chấm đang gõ không tràn khung khi gửi câu hỏi', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          chatRepositoryProvider.overrideWithValue(
            _FakeChatRepository(const [
              {'id': 1, 'role': 'user', 'content': 'Menu có gì ngon?'},
            ]),
          ),
        ],
        child: const MaterialApp(home: ChatScreen()),
      ),
    );
    await tester.pumpAndSettle();

    // Gửi câu hỏi → bật chỉ báo "đang trả lời" với ba chấm nhấp nháy.
    await tester.enterText(find.byType(TextField), 'Mì cay giá bao nhiêu?');
    await tester.pumpAndSettle();
    await tester.tap(find.bySemanticsLabel('Gửi tin nhắn'));
    await tester.pump();

    expect(find.textContaining('Đang trả lời'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}