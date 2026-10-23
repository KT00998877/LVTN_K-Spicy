import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../../../core/config.dart';
import '../domain/app_user.dart';

/// ============================================================
/// 🔐 AUTH REPOSITORY — giao tiếp với Laravel
/// ============================================================
/// Lưu ý quan trọng:
///   - Customer login  → POST /api/auth/login
///   - Staff login     → POST /api/staff/auth/login
///   - Customer me     → GET  /api/auth/me
///   - Staff me        → GET  /api/staff/auth/me
/// Mỗi loại trả về structure hơi khác nhau, đã handle bên dưới.
/// ============================================================

class AuthResult {
  AuthResult({required this.token, required this.user});
  final String token;
  final AppUser user;
}

class AuthRepository {
  AuthRepository(this._dio);
  final Dio _dio;

  // ────────────────── Customer ─────────────────────────────
  Future<AuthResult> loginCustomer({
    required String email,
    required String password,
  }) async {
    try {
      final res = await _dio.publicPost<Map<String, dynamic>>(
        '${AppConfig.customerAuthPath}/login',
        data: {'identifier': email.trim(), 'password': password},
      );
      return _parseAuthResponse(res.data!, isStaff: false);
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  Future<AuthResult> registerCustomer({
    required String name,
    required String passwordConfirmation,
    required String email,
    required String password,
    String? phone,
  }) async {
    try {
      final res = await _dio.publicPost<Map<String, dynamic>>(
        '${AppConfig.customerAuthPath}/register',
        data: {
          'name': name,
          'email': email,
          'password': password,
          'password_confirmation': passwordConfirmation,
          if (phone != null && phone.isNotEmpty) 'phone': phone,
        },
      );
      return _parseAuthResponse(res.data!, isStaff: false);
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  Future<AppUser> fetchCustomerMe() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '${AppConfig.customerAuthPath}/me',
      );
      return _parseUser(_root(res.data!));
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  // ────────────────── Profile ──────────────────────────────
  /// PUT /api/auth/me — cập nhật họ tên / tên đầy đủ / SĐT.
  /// Email và password phải đổi qua endpoint riêng (bảo mật).
  Future<AppUser> updateProfile({
    String? name,
    String? fullName,
    String? phone,
  }) async {
    try {
      final res = await _dio.put<Map<String, dynamic>>(
        '${AppConfig.customerAuthPath}/me',
        data: {
          if (name != null) 'name': name,
          if (fullName != null) 'full_name': fullName,
          if (phone != null) 'phone': phone,
        },
      );
      return _parseUser(_root(res.data!));
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  // ────────────────── Password ────────────────────────────
  /// POST /api/auth/change-password
  ///
  /// Backend siết luật: ≥8 ký tự, có chữ hoa + chữ thường + số + ký hiệu,
  /// và khác mật khẩu hiện tại. Lỗi trả về nằm trong [ApiException.errors].
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) async {
    try {
      await _dio.post<void>(
        '${AppConfig.customerAuthPath}/change-password',
        data: {
          'current_password': currentPassword,
          'password': newPassword,
          'password_confirmation': confirmation,
        },
      );
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  // ────────────────── Staff (Shipper/Manager/...) ───────────
  Future<AuthResult> loginStaff({
    required String email,
    required String password,
  }) async {
    try {
      final res = await _dio.publicPost<Map<String, dynamic>>(
        '${AppConfig.staffAuthPath}/login',
        data: {'identifier': email.trim(), 'password': password},
      );
      return _parseAuthResponse(res.data!, isStaff: true);
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  Future<AppUser> fetchStaffMe() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '${AppConfig.staffAuthPath}/me',
      );
      return _parseUser(_root(res.data!));
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  // ────────────────── Logout ───────────────────────────────
  Future<void> logout({required bool isStaff}) async {
    try {
      final path = isStaff
          ? '${AppConfig.staffAuthPath}/logout'
          : '${AppConfig.customerAuthPath}/logout';
      await _dio.post(path);
    } on DioException catch (e) {
      if (e.response?.statusCode != 401) {
        throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
      }
    }
  }

  // ────────────────── Helpers ──────────────────────────────
  AuthResult _parseAuthResponse(
    Map<String, dynamic> data, {
    required bool isStaff,
  }) {
    final root = _root(data);
    final token = (root['token'] ?? root['access_token'] ?? '').toString();
    if (token.isEmpty) {
      throw const ApiException(
        message: 'Máy chủ không trả về token đăng nhập.',
      );
    }
    return AuthResult(token: token, user: _parseUser(root));
  }

  Map<String, dynamic> _root(Map<String, dynamic> data) =>
      data['data'] is Map ? Map<String, dynamic>.from(data['data']) : data;

  AppUser _parseUser(Map<String, dynamic> root) {
    final user = Map<String, dynamic>.from(root['user'] ?? root);
    if (root['roles'] != null) user['roles'] = root['roles'];
    if (root['primary_role'] != null) user['roles'] = [root['primary_role']];
    return AppUser.fromJson(user);
  }
}
