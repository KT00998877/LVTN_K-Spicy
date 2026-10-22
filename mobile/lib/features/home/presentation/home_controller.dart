import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/home_repository.dart';
import '../domain/home_models.dart';

/// ============================================================
/// 🏠 HOME STATE NOTIFIER — tải song song các payload home
/// ============================================================
/// Mục tiêu: 1 lần fetch chính (khi vào tab Trang chủ),
/// cache trong RAM để các section khác đọc lại không tốn thêm
/// request. Pull-to-refresh sẽ gọi [refresh] để bypass cache.
/// ============================================================

class HomeState {
  HomeState({
    this.restaurant,
    this.stats,
    this.categories = const [],
    this.featured = const [],
    this.bestSellers = const [],
    this.isLoading = false,
    this.errorMessage,
    this.lastFetchedAt,
  });

  final RestaurantInfo? restaurant;
  final HomeStats? stats;
  final List<HomeCategory> categories;
  final List<HomeMenuItem> featured;
  final List<HomeMenuItem> bestSellers;
  final bool isLoading;
  final String? errorMessage;
  final DateTime? lastFetchedAt;

  bool get isReady => restaurant != null && stats != null;

  HomeState copyWith({
    RestaurantInfo? restaurant,
    HomeStats? stats,
    List<HomeCategory>? categories,
    List<HomeMenuItem>? featured,
    List<HomeMenuItem>? bestSellers,
    bool? isLoading,
    String? errorMessage,
    DateTime? lastFetchedAt,
    bool clearError = false,
  }) {
    return HomeState(
      restaurant: restaurant ?? this.restaurant,
      stats: stats ?? this.stats,
      categories: categories ?? this.categories,
      featured: featured ?? this.featured,
      bestSellers: bestSellers ?? this.bestSellers,
      isLoading: isLoading ?? this.isLoading,
      errorMessage:
          clearError ? null : (errorMessage ?? this.errorMessage),
      lastFetchedAt: lastFetchedAt ?? this.lastFetchedAt,
    );
  }
}

class HomeController extends StateNotifier<HomeState> {
  HomeController(this._repo) : super(HomeState());

  final HomeRepository _repo;

  /// Cache 60s (giống web store)
  static const _cacheMs = 60_000;

  Future<void> load({bool force = false}) async {
    final age = state.lastFetchedAt;
    if (!force &&
        state.isReady &&
        age != null &&
        DateTime.now().difference(age).inMilliseconds < _cacheMs) {
      return;
    }
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      // Song song tất cả endpoint
      final results = await Future.wait([
        _repo.getRestaurant(),
        _repo.getStats(),
        _repo.getCategories(),
        _repo.getFeatured(),
        _repo.getBestSellers(),
      ]);

      state = state.copyWith(
        restaurant: results[0] as RestaurantInfo,
        stats: results[1] as HomeStats,
        categories: results[2] as List<HomeCategory>,
        featured: results[3] as List<HomeMenuItem>,
        bestSellers: results[4] as List<HomeMenuItem>,
        isLoading: false,
        lastFetchedAt: DateTime.now(),
      );
    } catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: e.toString().replaceFirst('Exception: ', ''),
      );
    }
  }

  Future<void> refresh() => load(force: true);
}

final homeControllerProvider =
    StateNotifierProvider<HomeController, HomeState>((ref) {
  return HomeController(ref.watch(homeRepositoryProvider));
});
