import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter_dotenv/flutter_dotenv.dart';

/// ============================================================
/// ⚙️ API CONFIG — chọn URL khi chạy web / mobile / emulator
/// ============================================================
///
/// Đọc từ `mobile/.env` (đã khai báo trong pubspec.yaml assets):
///
///     BASE_URL_WEB=http://127.0.0.1:8000/api
///     BASE_URL_MOBILE=http://192.168.1.19:8000/api
///
/// Pattern đơn giản:
///   - Web (Chrome):         lấy BASE_URL_WEB
///   - Mobile (Android/iOS): lấy BASE_URL_MOBILE
///
/// Nếu 1 biến không tồn tại trong .env → fallback về giá trị mặc định
/// hợp lý cho từng môi trường. App vẫn chạy được, không cần sửa code
/// khi đổi thiết bị — chỉ cần sửa `.env` rồi restart.
///
/// Sau khi sửa .env, dừng và chạy lại ứng dụng.
/// ============================================================

class ApiConfig {
  ApiConfig._();

  /// Khởi tạo dotenv — gọi 1 lần ở `main()` trước khi `runApp`.
  static Future<void> load() async {
    await dotenv.load(fileName: '.env');
  }

  /// Backend Laravel URL — chọn theo nền tảng đang chạy.
  ///
  /// Trả về:
  ///   - `BASE_URL_WEB`    khi chạy trên web (Chrome / Edge / Safari)
  ///   - `BASE_URL_MOBILE` khi chạy trên mobile (Android / iOS)
  ///
  /// Nếu biến không có trong .env, dùng fallback hợp lý.
  static String get baseUrl {
    if (kIsWeb) {
      return dotenv.env['BASE_URL_WEB'] ??
          'http://${_defaultWebHost()}:8000/api';
    }
    return dotenv.env['BASE_URL_MOBILE'] ??
        'http://${_defaultMobileHost()}:8000/api';
  }

  /// Cổng của Laravel "chat gateway" — tiến trình PHP riêng phục vụ
  /// `/api/chatbot/*`.
  static const int chatGatewayPort = 8002;

  /// Base URL cho endpoint chatbot.
  ///
  /// Chatbot KHÔNG được chạy trên cùng tiến trình Laravel với business API.
  /// `php artisan serve` phục vụ tuần tự: khi nó chờ AI Service trả lời thì
  /// không còn worker nào trả lời được request khác — mà AI Service lại gọi
  /// ngược về business API để lấy menu/giỏ/đơn. Hai bên chờ nhau nên bế tắc.
  /// Đo thực tế trên mobile: cùng cổng 8000 → 3/4 câu hỏi lỗi 503, trung vị
  /// 15,5s; tách sang gateway riêng → 0/4 lỗi, trung vị 3,0s.
  ///
  /// Thứ tự ưu tiên:
  ///   1. `BASE_URL_CHAT_MOBILE` / `BASE_URL_CHAT_WEB` trong `.env` (nếu có)
  ///   2. Suy ra từ base URL business, đổi cổng sang chat gateway
  ///
  /// Khi triển khai production dùng web server thật (php-fpm/nginx) thì nhiều
  /// worker chạy song song và không bị bế tắc; khi đó trỏ
  /// `BASE_URL_CHAT_*` về cùng host business là hợp lý.
  static String get chatBaseUrl {
    final explicit = (kIsWeb
            ? dotenv.env['BASE_URL_CHAT_WEB']
            : dotenv.env['BASE_URL_CHAT_MOBILE'])
        ?.trim();
    if (explicit != null && explicit.isNotEmpty) {
      return explicit;
    }
    final business = Uri.parse(baseUrl);
    return Uri(
      scheme: business.scheme,
      host: business.host,
      port: chatGatewayPort,
      path: business.path,
    ).toString();
  }

  /// URL tuyệt đối cho ảnh backend (`/storage/...`).
  /// Dùng để ẩn `/api` khi resolve ảnh.
  static String get imageOrigin {
    final u = Uri.parse(baseUrl);
    return Uri(
      scheme: u.scheme,
      host: u.host,
      port: u.hasPort ? u.port : null,
    ).toString();
  }

  /// Customer endpoint prefix (route /api/auth/*)
  static const String customerAuthPath = '/auth';

  /// Staff endpoint prefix (route /api/staff/auth/*)
  /// Quan trọng: 2 endpoint đăng nhập KHÁC NHAU cho customer vs staff.
  static const String staffAuthPath = '/staff/auth';

  /// Token lưu trong secure storage
  static const String tokenKey = 'auth_token';
  static const String userKey = 'auth_user';

  /// Guest token của phiên chat khách vãng lai.
  ///
  /// Phiên chat có `user_id = null` thì chỉ sở hữu được qua token này.
  /// Backend yêu cầu header `X-Chat-Guest-Token` cho mọi request chatbot
  /// của khách chưa đăng nhập — thiếu header thì trả 404 "Không tìm thấy
  /// phiên chat" dù id phiên vẫn đúng.
  static const String chatGuestTokenKey = 'chat_guest_token';

  /// Guest token của phiên tư vấn trực tiếp.
  ///
  /// Cùng cơ chế với chatbot: phiên của khách chưa đăng nhập có `user_id`
  /// bằng null nên chỉ sở hữu được qua token này. Khác key với chatbot để
  /// hai hệ thống không lẫn lộn phiên của nhau.
  static const String liveChatGuestTokenKey = 'live_chat_guest_token';

  /// Khóa lưu id phiên tư vấn đang mở, để khôi phục sau khi app bị tắt.
  static String liveChatSessionKey(int? userId) =>
      'live_chat_session_${userId ?? 0}';

  /// ============================================================
  /// Realtime (Laravel Reverb) cho tư vấn trực tiếp
  /// ============================================================

  /// App key của Reverb — cần thiết để client ký kênh.
  ///
  /// Thiếu key thì app vẫn chat được qua HTTP, chỉ không có cập nhật tức
  /// thì: tin nhắn của nhân viên hiện sau khi khách bấm gửi lại.
  static String get reverbAppKey => dotenv.env['REVERB_APP_KEY'] ?? '';

  /// Host Reverb (mặc định cùng host với backend).
  static String get reverbHost {
    final explicit = dotenv.env['REVERB_HOST']?.trim();
    if (explicit != null && explicit.isNotEmpty) return explicit;
    final business = Uri.parse(baseUrl);
    return business.host;
  }

  /// Cổng WebSocket của Reverb.
  static int get reverbPort =>
      int.tryParse(dotenv.env['REVERB_PORT'] ?? '') ?? 8080;

  /// Dùng TLS hay không — phụ thuộc vào giao thức của chính backend.
  static bool get reverbSecure => Uri.parse(baseUrl).scheme == 'https';

  /// ============================================================
  /// Default hosts khi .env thiếu biến
  /// ============================================================

  /// - Web (Chrome): `127.0.0.1` (cùng máy với Laravel)
  /// - Android Emulator: `10.0.2.2` (loopback tới máy host)
  /// - iOS Simulator: `127.0.0.1`
  /// - Thiết bị thật: user tự sửa BASE_URL_MOBILE trong .env
  static String _defaultWebHost() => '127.0.0.1';

  static String _defaultMobileHost() {
    // Android Emulator → 10.0.2.2 (đặc biệt của emulator).
    // iOS Simulator / thiết bị thật → 127.0.0.1 (chỉ dùng khi
    // user quên khai báo BASE_URL_MOBILE).
    if (!kIsWeb && Platform.isAndroid) {
      return '10.0.2.2';
    }
    return '127.0.0.1';
  }
}

/// ============================================================
/// AppConfig — backward compat alias cho code cũ
/// ============================================================
///
/// Một số chỗ trong codebase vẫn tham chiếu `AppConfig.apiBaseUrl` /
/// `AppConfig.customerAuthPath` / v.v. từ phiên bản trước khi chuyển
/// sang `ApiConfig`. Để không phải đổi hàng loạt import, các getter
/// cũ được forward sang `ApiConfig`.
/// ============================================================
class AppConfig {
  AppConfig._();

  /// Backend URL đang dùng. Tương đương `ApiConfig.baseUrl`.
  static String get apiBaseUrl => ApiConfig.baseUrl;

  /// Base URL cho endpoint chatbot (chat gateway riêng).
  static String get chatBaseUrl => ApiConfig.chatBaseUrl;

  /// Cho phép override runtime (test, dev). Mặc định là `ApiConfig.baseUrl`.
  static String? _override;
  static String get effectiveBaseUrl => _override ?? ApiConfig.baseUrl;

  /// Customer endpoint prefix (route /api/auth/*)
  static String get customerAuthPath => ApiConfig.customerAuthPath;

  /// Staff endpoint prefix (route /api/staff/auth/*)
  static String get staffAuthPath => ApiConfig.staffAuthPath;

  /// Token lưu trong secure storage
  static String get tokenKey => ApiConfig.tokenKey;
  static String get userKey => ApiConfig.userKey;

  /// Guest token của phiên chat khách vãng lai.
  static String get chatGuestTokenKey => ApiConfig.chatGuestTokenKey;

  /// Guest token của phiên tư vấn trực tiếp.
  static String get liveChatGuestTokenKey => ApiConfig.liveChatGuestTokenKey;

  /// Khóa lưu id phiên tư vấn đang mở.
  static String liveChatSessionKey(int? userId) =>
      ApiConfig.liveChatSessionKey(userId);

  /// Khởi tạo dotenv — forward sang ApiConfig.
  static Future<void> load() => ApiConfig.load();
}
