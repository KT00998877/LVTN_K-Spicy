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

  /// Khởi tạo dotenv — forward sang ApiConfig.
  static Future<void> load() => ApiConfig.load();
}
