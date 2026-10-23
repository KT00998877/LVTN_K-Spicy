import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/core/api/dio_client.dart';
import 'package:micay_app/core/config.dart';

/// Adapter giả lập: ghi lại request rồi trả về JSON rỗng.
class _RecordingAdapter implements HttpClientAdapter {
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': <String, dynamic>{}}),
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

  setUpAll(() async {
    await ApiConfig.load();
  });

  late Dio dio;
  late _RecordingAdapter adapter;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({
      AppConfig.tokenKey: 'test-token',
      AppConfig.chatGuestTokenKey: 'guest-token-abc',
    });
    adapter = _RecordingAdapter();
    dio = Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl))
      ..httpClientAdapter = adapter;

    // Gắn đúng interceptor như ứng dụng thật.
    final container = ProviderContainer();
    addTearDown(container.dispose);
    final real = container.read(dioProvider);
    dio.interceptors.addAll(real.interceptors);
  });

  test('request chatbot đi qua chat gateway, không phải business API', () async {
    await dio.post<Map<String, dynamic>>('/chatbot/sessions');

    final request = adapter.requests.single;
    expect(
      request.baseUrl,
      AppConfig.chatBaseUrl,
      reason: 'chatbot phải qua gateway riêng để không bị bế tắc',
    );
    expect(request.baseUrl, contains(':8002'));
  });

  test('request thường vẫn giữ business API', () async {
    await dio.get<Map<String, dynamic>>('/menu');

    final request = adapter.requests.single;
    expect(request.baseUrl, AppConfig.apiBaseUrl);
    expect(request.baseUrl, contains(':8000'));
  });

  test('gửi kèm Bearer token và X-Chat-Guest-Token', () async {
    await dio.post<Map<String, dynamic>>('/chatbot/sessions');

    final request = adapter.requests.single;
    expect(request.headers['Authorization'], 'Bearer test-token');
    expect(request.headers['X-Chat-Guest-Token'], 'guest-token-abc');
  });

  test('request thường không gửi X-Chat-Guest-Token', () async {
    await dio.get<Map<String, dynamic>>('/menu');

    final request = adapter.requests.single;
    expect(request.headers.containsKey('X-Chat-Guest-Token'), isFalse);
  });
}
