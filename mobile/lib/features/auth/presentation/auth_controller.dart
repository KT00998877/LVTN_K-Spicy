import 'dart:convert';
import '../../../core/api/api_exception.dart';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/dio_client.dart';
import '../../../core/config.dart';
import '../data/auth_repository.dart';
import '../domain/app_user.dart';

/// ============================================================
/// 🧠 AUTH CONTROLLER — Riverpod state cho authentication
/// ============================================================
/// State machine:
///   unknown  → chưa biết (đang đọc token từ secure storage)
///   unauth   → chưa đăng nhập
///   auth     → đã đăng nhập (kèm AppUser + token)
/// ============================================================

enum AuthStatus { unknown, unauthenticated, authenticated }

class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.token,
    this.isStaff = false,
    this.isLoading = false,
    this.error,
  });
  final bool isLoading;
  final ApiException? error;
  bool get isAuthenticated => status == AuthStatus.authenticated;
  final AuthStatus status;
  final AppUser? user;
  final String? token;

  /// True nếu đăng nhập bằng staff endpoint (Shipper/Manager…)
  final bool isStaff;

  AuthState copyWith({
    AuthStatus? status,
    AppUser? user,
    String? token,
    bool? isStaff,
    bool clearUser = false,
    bool clearToken = false,
  }) {
    return AuthState(
      status: status ?? this.status,
      user: clearUser ? null : (user ?? this.user),
      token: clearToken ? null : (token ?? this.token),
      isStaff: isStaff ?? this.isStaff,
    );
  }

  static const AuthState unknown = AuthState(status: AuthStatus.unknown);
}

// ── Provider cho repository ─────────────────────────────────
final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(ref.watch(dioProvider));
});

// ── Auth state notifier ─────────────────────────────────────
/// AuthController — state machine đăng nhập.
///
/// [bootstrap] cho phép test bỏ qua việc đọc secure storage lúc khởi tạo
/// (mặc định `true` — app luôn cần bootstrap).
class AuthController extends StateNotifier<AuthState> {
  AuthController(this._repo, {bool bootstrap = true})
      : super(AuthState.unknown) {
    if (bootstrap) _bootstrap();
  }

  final AuthRepository _repo;

  void onSessionExpired() {
    if (state.status == AuthStatus.authenticated) expireSession();
  }

  /// Đọc token từ secure storage khi app khởi động
  Future<void> _bootstrap() async {
    try {
      final token = await secureStorage.read(key: AppConfig.tokenKey);
      if (token == null || token.isEmpty) {
        if (mounted) {
          state = const AuthState(status: AuthStatus.unauthenticated);
        }
        return;
      }
      final userJson = await secureStorage.read(key: AppConfig.userKey);
      final cached = userJson == null
          ? null
          : AppUser.fromJson(jsonDecode(userJson));
      final isStaff = cached?.role.isStaff ?? false;
      final user = isStaff
          ? await _repo.fetchStaffMe()
          : await _repo.fetchCustomerMe();
      if (mounted) {
        await _persistAndApply(
          AuthResult(token: token, user: user),
          isStaff: isStaff,
        );
      }
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await expireSession();
      } else if (mounted) {
        state = AuthState(status: AuthStatus.unknown, error: e);
      }
    } catch (_) {
      if (mounted) {
        state = const AuthState(
          status: AuthStatus.unknown,
          error: ApiException(
            message: 'Không thể khôi phục phiên. Vui lòng thử lại.',
          ),
        );
      }
    }
  }

  Future<void> retrySession() async {
    if (state.isLoading) return;
    state = const AuthState(status: AuthStatus.unknown, isLoading: true);
    await _bootstrap();
  }

  Future<void> expireSession() async {
    await secureStorage.delete(key: AppConfig.tokenKey);
    await secureStorage.delete(key: AppConfig.userKey);
    if (mounted) state = const AuthState(status: AuthStatus.unauthenticated);
  }

  Future<void> _run(Future<void> Function() action) async {
    if (state.isLoading) return;
    final previous = state;
    state = AuthState(
      status: previous.status,
      user: previous.user,
      token: previous.token,
      isStaff: previous.isStaff,
      isLoading: true,
    );
    try {
      await action();
      if (mounted) {
        state = AuthState(
          status: state.status,
          user: state.user,
          token: state.token,
          isStaff: state.isStaff,
        );
      }
    } catch (e) {
      final error = e is ApiException
          ? e
          : const ApiException(
              message: 'Không thể hoàn tất. Vui lòng thử lại.',
            );
      if (mounted) {
        state = AuthState(
          status: previous.status,
          user: previous.user,
          token: previous.token,
          isStaff: previous.isStaff,
          error: error,
        );
      }
      throw error;
    }
  }

  // ── Customer login / register ────────────────────────────
  Future<void> loginCustomer({
    required String email,
    required String password,
  }) => _run(() async {
    final result = await _repo.loginCustomer(email: email, password: password);
    await _persistAndApply(result, isStaff: result.user.role.isStaff);
  });

  Future<void> registerCustomer({
    required String name,
    required String passwordConfirmation,
    required String email,
    required String password,
    String? phone,
  }) => _run(() async {
    final result = await _repo.registerCustomer(
      name: name,
      passwordConfirmation: passwordConfirmation,
      email: email,
      password: password,
      phone: phone,
    );
    await _persistAndApply(result, isStaff: result.user.role.isStaff);
  });

  // ── Staff login ──────────────────────────────────────────
  Future<void> loginStaff({required String email, required String password}) =>
      _run(() async {
        final result = await _repo.loginStaff(email: email, password: password);
        await _persistAndApply(result, isStaff: result.user.role.isStaff);
      });

  // ── Logout ───────────────────────────────────────────────
  Future<void> logout() async {
    try {
      await _run(() async {
        await _repo.logout(isStaff: state.isStaff);
        await expireSession();
      });
    } on ApiException {
      // Keep the session and expose the error so logout can be retried.
    }
  }

  /// PUT /api/auth/me — cập nhật thông tin cá nhân rồi đồng bộ lại state.
  Future<void> updateProfile({
    String? name,
    String? fullName,
    String? phone,
  }) =>
      _run(() async {
        final user = await _repo.updateProfile(
          name: name,
          fullName: fullName,
          phone: phone,
        );
        await _persistAndApply(AuthResult(token: state.token!, user: user),
            isStaff: state.isStaff);
      });

  /// POST /api/auth/change-password.
  ///
  /// Cố ý KHÔNG gọi lại `_persistAndApply` với token cũ: backend đã thu hồi
  /// các token cũ sau khi đổi mật khẩu, nên session hiện tại phải bị đăng xuất
  /// để khách đăng nhập lại bằng mật khẩu mới.
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) async {
    await _run(() async {
      await _repo.changePassword(
        currentPassword: currentPassword,
        newPassword: newPassword,
        confirmation: confirmation,
      );
    });
    await expireSession();
  }

  /// Nạp lại user từ server (dùng sau khi upload/xoá avatar).
  ///
  /// Đặc biệt tránh dùng `_run` vì nó không cho phép ghi đè `user` khi
  /// `isLoading` — ở đây cần cập nhật user tức thì.
  Future<void> refreshCurrentUser() async {
    try {
      final user =
          state.isStaff ? await _repo.fetchStaffMe() : await _repo.fetchCustomerMe();
      if (!mounted) return;
      await secureStorage.write(
        key: AppConfig.userKey,
        value: jsonEncode(user.toJson()),
      );
      if (!mounted) return;
      state = AuthState(
        status: state.status,
        token: state.token,
        user: user,
        isStaff: state.isStaff,
        error: state.error,
      );
    } on ApiException catch (e) {
      if (mounted) {
        state = AuthState(
          status: state.status,
          token: state.token,
          user: state.user,
          isStaff: state.isStaff,
          error: e,
        );
      }
      rethrow;
    }
  }

  // ── Internal ─────────────────────────────────────────────

  /// Ép trạng thái đã đăng nhập — chỉ dùng cho test.
  @visibleForTesting
  void debugSetAuthenticated({AppUser? user, String token = 'test-token'}) {
    state = AuthState(
      status: AuthStatus.authenticated,
      token: token,
      user:
          user ??
          AppUser(
            id: 1,
            name: 'Chị Lan',
            email: 'lan@example.com',
            role: UserRole.customer,
          ),
      isStaff: false,
    );
  }

  Future<void> _persistAndApply(
    AuthResult result, {
    required bool isStaff,
  }) async {
    await secureStorage.write(key: AppConfig.tokenKey, value: result.token);
    await secureStorage.write(
      key: AppConfig.userKey,
      value: jsonEncode(result.user.toJson()),
    );
    if (!mounted) return;
    state = AuthState(
      status: AuthStatus.authenticated,
      token: result.token,
      user: result.user,
      isStaff: isStaff,
    );
  }
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(
  (ref) {
    final controller = AuthController(ref.watch(authRepositoryProvider));
    ref.listen(sessionExpiredProvider, (_, __) {
      controller.onSessionExpired();
    });
    return controller;
  },
);

/// Convenience: lấy user hiện tại (throw nếu chưa đăng nhập)
final currentUserProvider = Provider<AppUser>((ref) {
  final auth = ref.watch(authControllerProvider);
  if (auth.user == null) {
    throw StateError('Chưa đăng nhập');
  }
  return auth.user!;
});
