import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/core/api/dio_client.dart';
import 'package:micay_app/core/config.dart';
import 'package:micay_app/features/auth/domain/app_user.dart';
import 'package:micay_app/features/auth/presentation/auth_controller.dart';
import 'package:micay_app/features/live_chat/data/live_chat_repository.dart';
import 'package:micay_app/features/live_chat/domain/live_chat_models.dart';
import 'package:micay_app/features/live_chat/presentation/live_chat_controller.dart';
import 'package:micay_app/features/live_chat/presentation/live_chat_screen.dart';

/// Adapter trả về payload tuỳ theo path của request.
class _FakeAdapter implements HttpClientAdapter {
  _FakeAdapter({this.onRequest});

  final void Function(RequestOptions options)? onRequest;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    onRequest?.call(options);

    final path = options.path;
    final Map<String, dynamic> body;

    if (path.endsWith('/sessions') && options.method == 'POST') {
      body = {
        'success': true,
        'data': {
          'id': 7,
          'status': 'waiting',
          'customer_name': 'Chị Lan',
          'assigned_staff_name': null,
          'last_message_at': null,
          'created_at': '2026-10-23T10:00:00Z',
          'messages': <Map<String, dynamic>>[],
        },
      };
    } else if (path.contains('/messages') && options.method == 'POST') {
      body = {
        'success': true,
        'data': {
          'message': {
            'id': 99,
            'session_id': 7,
            'sender_type': 'customer',
            'sender_id': 1,
            'content': 'Cho mình hỏi mì cay bò giá bao nhiêu?',
            'created_at': '2026-10-23T10:01:00Z',
          },
          'session': {
            'id': 7,
            'status': 'waiting',
            'customer_name': 'Chị Lan',
            'assigned_staff_name': null,
            'last_message_at': '2026-10-23T10:01:00Z',
            'created_at': '2026-10-23T10:00:00Z',
          },
        },
      };
    } else {
      body = {
        'success': true,
        'data': {
          'id': 7,
          'status': 'waiting',
          'customer_name': 'Chị Lan',
          'assigned_staff_name': null,
          'last_message_at': null,
          'created_at': '2026-10-23T10:00:00Z',
          'messages': <Map<String, dynamic>>[],
        },
      };
    }

    return ResponseBody.fromString(
      jsonEncode(body),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUpAll(ApiConfig.load);

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  Widget buildApp(ProviderContainer container) {
    return UncontrolledProviderScope(
      container: container,
      child: const MaterialApp(home: LiveChatScreen()),
    );
  }

  testWidgets('khách chưa đăng nhập thấy lời mời đăng nhập', (tester) async {
    final container = ProviderContainer();
    addTearDown(container.dispose);

    await tester.pumpWidget(buildApp(container));
    await tester.pumpAndSettle();

    expect(find.text('Tư vấn trực tiếp cần đăng nhập'), findsOneWidget);
    expect(find.text('Đăng nhập để tư vấn'), findsOneWidget);
    expect(container.read(liveChatControllerProvider).status,
        LiveChatViewStatus.requiresLogin);
  });

  testWidgets('khách đã đăng nhập thấy ô soạn tin nhắn', (tester) async {
    final container = ProviderContainer(
      overrides: [
        authControllerProvider.overrideWith(
          (ref) => AuthController(
            ref.watch(authRepositoryProvider),
            bootstrap: false,
          )..debugSetAuthenticated(),
        ),
        liveChatRepositoryProvider.overrideWith((ref) {
          final dio = Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl))
            ..httpClientAdapter = _FakeAdapter();
          return LiveChatRepository(dio);
        }),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(buildApp(container));
    await tester.pumpAndSettle();

    expect(find.text('Bạn cần tư vấn gì hôm nay?'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Nhập tin nhắn...'), findsOneWidget);
  });

  testWidgets('gửi tin nhắn hiện ngay trong khung chat', (tester) async {
    final container = ProviderContainer(
      overrides: [
        authControllerProvider.overrideWith(
          (ref) => AuthController(
            ref.watch(authRepositoryProvider),
            bootstrap: false,
          )..debugSetAuthenticated(),
        ),
        liveChatRepositoryProvider.overrideWith((ref) {
          final dio = Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl))
            ..httpClientAdapter = _FakeAdapter();
          return LiveChatRepository(dio);
        }),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(buildApp(container));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byType(TextField),
      'Cho mình hỏi mì cay bò giá bao nhiêu?',
    );
    await tester.tap(find.text('Gửi'));
    // Dòng "Đang chờ nhân viên" có animation lặp vô hạn nên không dùng
    // pumpAndSettle ở đây.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(
      find.text('Cho mình hỏi mì cay bò giá bao nhiêu?'),
      findsOneWidget,
    );
    expect(find.text('Đang chờ nhân viên tư vấn trả lời'), findsOneWidget);
  });
}
