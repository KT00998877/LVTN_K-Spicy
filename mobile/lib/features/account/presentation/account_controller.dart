import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/account_repository.dart';
import '../domain/loyalty_models.dart';
import '../domain/review_models.dart';

/// ============================================================
/// 👤 ACCOUNT CONTROLLER — trạng thái cho màn "Tài khoản"
/// ============================================================
///
/// Chia nhỏ thành 4 nhánh độc lập để tab nào cũng tải được mà không
/// chặn các tab khác:
///
///   ProfileController  — sửa tên / SĐT / avatar
///   TierController     — hạng hiện tại + tiến trình + lịch sử điểm
///   ReviewsController  — đánh giá của khách
///   PasswordController — đổi mật khẩu
///
/// Mỗi nhánh giữ `revision` để response trả về muộn không ghi đè dữ liệu
/// mới hơn (race condition khi người dùng bấm nhiều lần liên tiếp).
///

final accountRepositoryProvider = Provider<AccountRepository>(
  (ref) => AccountRepository(ref.watch(dioProvider)),
);

// ────────────────── Profile ────────────────────────────────

class ProfileState {
  const ProfileState({
    this.isSaving = false,
    this.isUploadingAvatar = false,
    this.isDeletingAvatar = false,
    this.message,
    this.error,
    this.fieldErrors = const {},
  });

  final bool isSaving;
  final bool isUploadingAvatar;
  final bool isDeletingAvatar;

  /// Thông báo thành công (đã hiển thị rồi sẽ tự ẩn).
  final String? message;
  final String? error;

  /// Lỗi validation theo field từ Laravel.
  final Map<String, List<String>> fieldErrors;

  bool get isBusy =>
      isSaving || isUploadingAvatar || isDeletingAvatar;

  String? fieldError(String key) {
    final list = fieldErrors[key];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  ProfileState copyWith({
    bool? isSaving,
    bool? isUploadingAvatar,
    bool? isDeletingAvatar,
    String? message,
    String? error,
    Map<String, List<String>>? fieldErrors,
    bool clearMessage = false,
    bool clearError = false,
  }) =>
      ProfileState(
        isSaving: isSaving ?? this.isSaving,
        isUploadingAvatar: isUploadingAvatar ?? this.isUploadingAvatar,
        isDeletingAvatar: isDeletingAvatar ?? this.isDeletingAvatar,
        message: clearMessage ? null : (message ?? this.message),
        error: clearError ? null : (error ?? this.error),
        fieldErrors: fieldErrors ?? this.fieldErrors,
      );
}

class ProfileController extends StateNotifier<ProfileState> {
  ProfileController(this._repo, this._auth) : super(const ProfileState());

  final AccountRepository _repo;
  final AuthController _auth;

  void clearMessage() {
    if (state.message == null && state.error == null) return;
    state = state.copyWith(clearMessage: true, clearError: true);
  }

  /// PUT /api/auth/me
  Future<bool> saveProfile({
    required String name,
    String? fullName,
    String? phone,
  }) async {
    state = state.copyWith(isSaving: true, clearError: true, clearMessage: true);
    try {
      await _auth.updateProfile(
        name: name.trim(),
        fullName: (fullName ?? '').trim().isEmpty ? '' : fullName!.trim(),
        phone: (phone ?? '').trim().isEmpty ? '' : phone!.trim(),
      );
      state = state.copyWith(isSaving: false, message: 'Đã cập nhật thông tin.');
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(
        isSaving: false,
        error: e.message,
        fieldErrors: e.errors ?? const {},
      );
      return false;
    }
  }

  /// POST /api/auth/me/avatar — sau đó nạp lại user để avatar mới hiện ngay.
  Future<bool> uploadAvatar(String filePath) async {
    state = state.copyWith(
      isUploadingAvatar: true,
      clearError: true,
      clearMessage: true,
    );
    try {
      await _repo.uploadAvatar(filePath);
      await _auth.refreshCurrentUser();
      state = state.copyWith(
        isUploadingAvatar: false,
        message: 'Đã cập nhật ảnh đại diện.',
      );
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(isUploadingAvatar: false, error: e.message);
      return false;
    }
  }

  /// DELETE /api/auth/me/avatar
  Future<bool> deleteAvatar() async {
    state = state.copyWith(
      isDeletingAvatar: true,
      clearError: true,
      clearMessage: true,
    );
    try {
      await _repo.deleteAvatar();
      await _auth.refreshCurrentUser();
      state = state.copyWith(
        isDeletingAvatar: false,
        message: 'Đã xoá ảnh đại diện.',
      );
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(isDeletingAvatar: false, error: e.message);
      return false;
    }
  }
}

final profileControllerProvider =
    StateNotifierProvider<ProfileController, ProfileState>(
  (ref) => ProfileController(
    ref.watch(accountRepositoryProvider),
    ref.read(authControllerProvider.notifier),
  ),
);

// ────────────────── Loyalty tier ───────────────────────────

class TierState {
  const TierState({
    this.overview = LoyaltyOverview.empty,
    this.tiers = const [],
    this.ledger = const [],
    this.ledgerMeta = PageMeta.empty,
    this.isLoading = false,
    this.isLoadingLedger = false,
    this.error,
    this.hasLoadedOnce = false,
  });

  final LoyaltyOverview overview;

  /// 4 hạng + quyền lợi (đã sort theo sort_order từ backend).
  final List<LoyaltyTier> tiers;

  /// Sổ cái điểm.
  final List<LoyaltyEntry> ledger;
  final PageMeta ledgerMeta;

  final bool isLoading;
  final bool isLoadingLedger;
  final String? error;
  final bool hasLoadedOnce;

  TierState copyWith({
    LoyaltyOverview? overview,
    List<LoyaltyTier>? tiers,
    List<LoyaltyEntry>? ledger,
    PageMeta? ledgerMeta,
    bool? isLoading,
    bool? isLoadingLedger,
    String? error,
    bool? hasLoadedOnce,
    bool clearError = false,
  }) =>
      TierState(
        overview: overview ?? this.overview,
        tiers: tiers ?? this.tiers,
        ledger: ledger ?? this.ledger,
        ledgerMeta: ledgerMeta ?? this.ledgerMeta,
        isLoading: isLoading ?? this.isLoading,
        isLoadingLedger: isLoadingLedger ?? this.isLoadingLedger,
        error: clearError ? null : (error ?? this.error),
        hasLoadedOnce: hasLoadedOnce ?? this.hasLoadedOnce,
      );
}

class TierController extends StateNotifier<TierState> {
  TierController(this._repo) : super(const TierState());

  final AccountRepository _repo;
  int _revision = 0;

  Future<void> load({bool force = false}) async {
    if (!force && state.hasLoadedOnce) return;
    final revision = ++_revision;
    state = state.copyWith(isLoading: true, clearError: true);

    // `/loyalty/me` là thông tin cá nhân nên bắt buộc; `/loyalty/tiers` là
    // danh mục public nên lỗi riêng nó không nên làm hỏng cả màn hình.
    final results = await Future.wait([
      _repo.fetchMyTier(),
      _repo.fetchTiers().catchError((_) => <LoyaltyTier>[]),
    ]);
    if (!mounted || revision != _revision) return;

    final overview = results[0] as LoyaltyOverview;
    final tiers = results[1] as List<LoyaltyTier>;

    state = state.copyWith(
      overview: overview,
      tiers: tiers,
      isLoading: false,
      hasLoadedOnce: true,
    );
    unawaited(loadLedger(page: 1));
  }

  Future<void> refresh() => load(force: true);

  int _ledgerRevision = 0;

  Future<void> loadLedger({required int page}) async {
    final revision = ++_ledgerRevision;
    state = state.copyWith(isLoadingLedger: true);
    try {
      final result = await _repo.fetchPointLedger(page: page);
      if (!mounted || revision != _ledgerRevision) return;
      state = state.copyWith(
        ledger: result.items,
        ledgerMeta: result.meta,
        isLoadingLedger: false,
      );
    } on ApiException catch (e) {
      if (!mounted || revision != _ledgerRevision) return;
      state = state.copyWith(isLoadingLedger: false, error: e.message);
    }
  }
}

final tierControllerProvider =
    StateNotifierProvider<TierController, TierState>(
  (ref) => TierController(ref.watch(accountRepositoryProvider)),
);

/// Nạp lại hạng khi khách vừa đổi mật khẩu/đăng nhập lại — dùng chung key
/// với `authControllerProvider` để tự refresh khi user đổi.
final tierRefreshProvider = Provider<void>((ref) {
  ref.listen(authControllerProvider, (previous, next) {
    if (previous?.user?.id == next.user?.id && previous?.user != null) {
      ref.read(tierControllerProvider.notifier).load(force: true);
    }
  });
});

// ────────────────── Reviews ────────────────────────────────

class ReviewsState {
  const ReviewsState({
    this.items = const [],
    this.meta = PageMeta.empty,
    this.isLoading = false,
    this.isLoadingMore = false,
    this.error,
    this.hasLoadedOnce = false,
  });

  final List<MyReview> items;
  final PageMeta meta;
  final bool isLoading;
  final bool isLoadingMore;
  final String? error;
  final bool hasLoadedOnce;

  bool get hasMore => meta.hasMore;

  ReviewsState copyWith({
    List<MyReview>? items,
    PageMeta? meta,
    bool? isLoading,
    bool? isLoadingMore,
    String? error,
    bool? hasLoadedOnce,
    bool clearError = false,
  }) =>
      ReviewsState(
        items: items ?? this.items,
        meta: meta ?? this.meta,
        isLoading: isLoading ?? this.isLoading,
        isLoadingMore: isLoadingMore ?? this.isLoadingMore,
        error: clearError ? null : (error ?? this.error),
        hasLoadedOnce: hasLoadedOnce ?? this.hasLoadedOnce,
      );
}

class ReviewsController extends StateNotifier<ReviewsState> {
  ReviewsController(this._repo) : super(const ReviewsState());

  final AccountRepository _repo;
  int _revision = 0;

  Future<void> load({bool force = false}) async {
    if (!force && state.hasLoadedOnce) return;
    final revision = ++_revision;
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final result = await _repo.fetchMyReviews(page: 1);
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        items: result.items,
        meta: result.meta,
        isLoading: false,
        hasLoadedOnce: true,
      );
    } on ApiException catch (e) {
      if (!mounted || revision != _revision) return;
      state = state.copyWith(isLoading: false, error: e.message);
    }
  }

  Future<void> loadMore() async {
    if (!state.hasMore || state.isLoadingMore || state.isLoading) return;
    state = state.copyWith(isLoadingMore: true);
    try {
      final result = await _repo.fetchMyReviews(page: state.meta.currentPage + 1);
      if (!mounted) return;
      state = state.copyWith(
        items: [...state.items, ...result.items],
        meta: result.meta,
        isLoadingMore: false,
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      state = state.copyWith(isLoadingMore: false, error: e.message);
    }
  }

  Future<void> refresh() => load(force: true);
}

final reviewsControllerProvider =
    StateNotifierProvider<ReviewsController, ReviewsState>(
  (ref) => ReviewsController(ref.watch(accountRepositoryProvider)),
);

// ────────────────── Password ───────────────────────────────

class PasswordState {
  const PasswordState({
    this.isSubmitting = false,
    this.error,
    this.done = false,
    this.fieldErrors = const {},
  });

  final bool isSubmitting;
  final String? error;

  /// Đổi mật khẩu thành công → session bị thu hồi, UI chuyển về màn login.
  final bool done;
  final Map<String, List<String>> fieldErrors;

  String? fieldError(String key) {
    final list = fieldErrors[key];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  PasswordState copyWith({
    bool? isSubmitting,
    String? error,
    bool? done,
    Map<String, List<String>>? fieldErrors,
    bool clearError = false,
  }) =>
      PasswordState(
        isSubmitting: isSubmitting ?? this.isSubmitting,
        error: clearError ? null : (error ?? this.error),
        done: done ?? this.done,
        fieldErrors: fieldErrors ?? this.fieldErrors,
      );
}

class PasswordController extends StateNotifier<PasswordState> {
  PasswordController(this._auth) : super(const PasswordState());

  final AuthController _auth;

  Future<bool> submit({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) async {
    if (state.isSubmitting) return false;
    state = state.copyWith(
      isSubmitting: true,
      clearError: true,
      done: false,
    );
    try {
      await _auth.changePassword(
        currentPassword: currentPassword,
        newPassword: newPassword,
        confirmation: confirmation,
      );
      if (mounted) state = state.copyWith(isSubmitting: false, done: true);
      return true;
    } on ApiException catch (e) {
      if (mounted) {
        state = state.copyWith(
          isSubmitting: false,
          error: e.message,
          fieldErrors: e.errors ?? const {},
        );
      }
      return false;
    }
  }
}

final passwordControllerProvider =
    StateNotifierProvider<PasswordController, PasswordState>(
  (ref) => PasswordController(ref.read(authControllerProvider.notifier)),
);