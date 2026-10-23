import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../domain/loyalty_models.dart';
import '../domain/review_models.dart';

/// ============================================================
/// 👤 ACCOUNT REPOSITORY — dữ liệu cho màn Tài khoản
/// ============================================================
///
/// Nhóm các endpoint đã có sẵn ở backend, KHÔNG đổi contract:
///
///   GET    /api/auth/me            — đã có (auth_repository.dart)
///   PUT    /api/auth/me            — đã có
///   POST   /api/auth/change-password — đã có
///   POST   /api/auth/me/avatar     — MỚI (upload avatar)
///   DELETE /api/auth/me/avatar     — MỚI (xoá avatar)
///   GET    /api/loyalty/tiers      — danh sách 4 hạng
///   GET    /api/loyalty/me         — hạng hiện tại + tiến trình
///   GET    /api/vouchers/loyalty   — sổ cái điểm
///   GET    /api/reviews/me         — đánh giá của tôi
///
/// Mọi lỗi được chuẩn hoá thành [ApiException] để UI hiển thị
/// message tiếng Việt.
///
class AccountRepository {
  AccountRepository(this._dio);
  final Dio _dio;

  // ────────────────── Avatar ────────────────────────────────

  /// Upload ảnh đại diện. [avatar] là file đã chọn từ gallery/camera.
  ///
  /// Multipart: phải bỏ header Content-Type để Dio tự sinh kèm
  /// `boundary`, nếu không Laravel sẽ không parse được file.
  Future<void> uploadAvatar(String avatarPath) async {
    try {
      final form = FormData.fromMap({
        'avatar': await MultipartFile.fromFile(avatarPath),
      });
      await _dio.post<void>(
        '${_auth}me/avatar',
        data: form,
        options: Options(contentType: 'multipart/form-data'),
      );
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  /// Xoá ảnh đại diện, quay về chữ cái đầu.
  Future<void> deleteAvatar() async {
    try {
      await _dio.delete<void>('${_auth}me/avatar');
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  // ────────────────── Loyalty ───────────────────────────────

  /// GET /api/loyalty/tiers — danh sách 4 hạng + quyền lợi.
  Future<List<LoyaltyTier>> fetchTiers() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/loyalty/tiers');
      final data = res.data?['data'];
      if (data is! List) return const [];
      return data
          .whereType<Map>()
          .map((e) => LoyaltyTier.fromJson(Map<String, dynamic>.from(e)))
          .toList(growable: false);
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  /// GET /api/loyalty/me — hạng hiện tại + tiến trình thăng hạng.
  Future<LoyaltyOverview> fetchMyTier() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/loyalty/me');
      final data = res.data?['data'];
      if (data is! Map) return LoyaltyOverview.empty;
      return LoyaltyOverview.fromJson(Map<String, dynamic>.from(data));
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  /// Sổ cái điểm thưởng (lịch sử cộng/dùng điểm).
  Future<({List<LoyaltyEntry> items, PageMeta meta})> fetchPointLedger({
    int page = 1,
    int perPage = 20,
  }) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/vouchers/loyalty',
        queryParameters: {'page': page, 'per_page': perPage},
      );
      return _parseLedger(res.data);
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  ({List<LoyaltyEntry> items, PageMeta meta}) _parseLedger(
    Map<String, dynamic>? body,
  ) {
    final root = body?['data'];
    final data = root is Map ? root : body;
    if (data is! Map) {
      return (items: <LoyaltyEntry>[], meta: PageMeta.empty);
    }
    final rawItems = data['items'];
    final items = rawItems is List
        ? rawItems
            .whereType<Map>()
            .map((e) => LoyaltyEntry.fromJson(Map<String, dynamic>.from(e)))
            .toList(growable: false)
        : <LoyaltyEntry>[];
    return (items: items, meta: PageMeta.fromJson(
      data['meta'] is Map ? Map<String, dynamic>.from(data['meta'] as Map) : null,
    ));
  }

  // ────────────────── Reviews ───────────────────────────────

  /// GET /api/reviews/me — danh sách đánh giá của khách đang đăng nhập.
  Future<({List<MyReview> items, PageMeta meta})> fetchMyReviews({
    int page = 1,
    int perPage = 10,
  }) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/reviews/me',
        queryParameters: {'page': page, 'per_page': perPage},
      );
      final root = res.data?['data'];
      final data = root is Map ? root : res.data;
      if (data is! Map) {
        return (items: <MyReview>[], meta: PageMeta.empty);
      }
      final rawItems = data['items'];
      final items = rawItems is List
          ? rawItems
              .whereType<Map>()
              .map((e) => MyReview.fromJson(Map<String, dynamic>.from(e)))
              .toList(growable: false)
          : <MyReview>[];
      return (
        items: items,
        meta: PageMeta.fromJson(
          data['meta'] is Map
              ? Map<String, dynamic>.from(data['meta'] as Map)
              : null,
        ),
      );
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : apiExceptionFromDio(e);
    }
  }

  static const _auth = '/auth/';
}