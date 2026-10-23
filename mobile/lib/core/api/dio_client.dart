import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api_exception.dart';
import 'cart_debug_interceptor.dart';
import '../config.dart';

/// ============================================================
/// 🌐 DIO CLIENT — HTTP client dùng chung toàn app
/// ============================================================
/// - Tự gắn `Authorization: Bearer <token>` từ secure storage
/// - Parse lỗi Laravel thành [ApiException] có cấu trúc
/// - Logging cơ bản (tắt trong release nếu cần)
/// ============================================================

/// Singleton secure storage — dùng để đọc/ghi token Sanctum.
const _secureStorage = FlutterSecureStorage(
  aOptions: AndroidOptions(encryptedSharedPreferences: true),
);

/// True nếu request này thuộc nhóm endpoint chatbot.
///
/// Nhóm này phải đi qua chat gateway riêng, không đi qua business API —
/// xem [ApiConfig.chatBaseUrl] để hiểu vì sao.
bool isChatbotPath(String path) => path.startsWith('/chatbot/');

/// True nếu request thuộc nhóm endpoint tư vấn trực tiếp.
///
/// Khác chatbot, nhóm này KHÔNG đi qua chat gateway: mỗi request chỉ là
/// ghi/đọc vài bản ghi, không có lượt nào chờ AI nên không có nguy cơ bế tắc
/// với business API.
bool isLiveChatPath(String path) => path.startsWith('/live-chat/');

/// Provider Dio instance (overridden trong main.dart với auth provider)
final sessionExpiredProvider = StateProvider<int>((ref) => 0);

final dioProvider = Provider<Dio>((ref) {
  final dio = Dio(
    BaseOptions(
      baseUrl: AppConfig.apiBaseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 20),
      sendTimeout: const Duration(seconds: 15),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      // KHÔNG throwOnError ở đây — để ResponseInterceptor xử lý
    ),
  );

  // ── Interceptor: gắn Bearer token ────────────────────────
  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) async {
        // Chatbot đi qua chat gateway riêng (cổng khác business API).
        // `php artisan serve` chỉ phục vụ tuần tự, nếu chatbot chạy chung
        // tiến trình với business API thì lúc Laravel chờ AI Service, AI
        // Service gọi ngược lại /api/menu sẽ bị treo → timeout → 503.
        if (isChatbotPath(options.path)) {
          options.baseUrl = AppConfig.chatBaseUrl;
        }

        // Bỏ qua public endpoint (không cần token)
        final isPublic = options.extra['public'] == true;
        if (!isPublic) {
          final token = await _secureStorage.read(key: AppConfig.tokenKey);
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
        }

        // Phiên chat của khách chưa đăng nhập có user_id = null: backend
        // chỉ nhận phiên đó qua header X-Chat-Guest-Token. Thiếu header thì
        // mọi request chatbot trả 404 dù id phiên hoàn toàn đúng.
        if (isChatbotPath(options.path)) {
          final guestToken =
              await _secureStorage.read(key: AppConfig.chatGuestTokenKey);
          if (guestToken != null && guestToken.isNotEmpty) {
            options.headers['X-Chat-Guest-Token'] = guestToken;
          }
        }

        // Tư vấn trực tiếp dùng chung cơ chế chứng minh quyền sở hữu phiên:
        // phiên của khách chưa đăng nhập có user_id = null, nên không có token
        // này thì mọi request trả 404 dù id phiên hoàn toàn đúng.
        if (isLiveChatPath(options.path)) {
          final guestToken =
              await _secureStorage.read(key: AppConfig.liveChatGuestTokenKey);
          if (guestToken != null && guestToken.isNotEmpty) {
            options.headers['X-Chat-Guest-Token'] = guestToken;
          }
        }
        handler.next(options);
      },
      onError: (e, handler) {
        if (e.response?.statusCode == 401 &&
            e.requestOptions.extra['public'] != true) {
          ref.read(sessionExpiredProvider.notifier).state++;
        }
        // Chuẩn hoá lỗi → ApiException
        final apiErr = apiExceptionFromDio(e);
        handler.reject(
          DioException(
            requestOptions: e.requestOptions,
            response: e.response,
            type: e.type,
            error: apiErr,
            message: apiErr.message,
          ),
        );
      },
    ),
  );

  dio.interceptors.add(CartDebugInterceptor());
  return dio;
});

/// ── Helper: bật public mode cho 1 request ─────────────────
extension DioPublicExt on Dio {
  Future<Response<T>> publicGet<T>(String path, {Map<String, dynamic>? query}) {
    return get<T>(
      path,
      queryParameters: query,
      options: Options(extra: {'public': true}),
    );
  }

  Future<Response<T>> publicPost<T>(String path, {dynamic data}) {
    return post<T>(
      path,
      data: data,
      options: Options(extra: {'public': true}),
    );
  }
}

/// ── Helper: chuyển DioException → ApiException ────────────
ApiException apiExceptionFromDio(DioException e) {
  final path = e.requestOptions.path;
  final res = e.response;
  final code = res?.statusCode;

  // Không có response: lỗi mạng / timeout
  if (res == null) {
    return ApiException(
      message: switch (e.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.receiveTimeout ||
        DioExceptionType.sendTimeout => 'Kết nối bị timeout. Vui lòng thử lại.',
        DioExceptionType.connectionError =>
          'Không thể kết nối máy chủ. Kiểm tra mạng và địa chỉ API.',
        _ => 'Đã có lỗi xảy ra. Vui lòng thử lại.',
      },
      statusCode: code,
      path: path,
    );
  }

  // Có response: parse body lỗi từ Laravel
  final data = res.data;
  String message = 'Đã có lỗi xảy ra.';
  Map<String, List<String>>? fieldErrors;

  if (data is Map) {
    message = (data['message'] ?? data['error'] ?? message).toString();
    final errs = data['errors'];
    if (errs is Map) {
      fieldErrors = errs.map(
        (k, v) => MapEntry(
          k.toString(),
          v is List ? v.map((e) => e.toString()).toList() : [v.toString()],
        ),
      );
    }
  }

  // Status-specific message fallback
  message = switch (code) {
    401 =>
      e.requestOptions.extra['public'] == true
          ? (data is Map && data['message'] != null
                ? data['message'].toString()
                : 'Email/số điện thoại hoặc mật khẩu không chính xác.')
          : 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.',
    403 => 'Bạn không có quyền thực hiện thao tác này.',
    404 => 'Không tìm thấy dữ liệu.',
    429 => 'Bạn thao tác quá nhanh. Vui lòng đợi một chút.',
    _ when code != null && code >= 500 => 'Lỗi máy chủ. Vui lòng thử lại sau.',
    _ when code == 422 && message.isEmpty => 'Dữ liệu không hợp lệ.',
    _ => message,
  };

  return ApiException(
    message: message,
    statusCode: code,
    errors: fieldErrors,
    path: path,
  );
}

/// Export secure storage để auth_service dùng
const secureStorage = _secureStorage;
