import 'dart:async' show unawaited;

import '../../../core/api/api_exception.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../orders/domain/order_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/shipper_repository.dart';
import '../domain/shipper_models.dart';

/// ============================================================
/// 🧠 SHIPPER CONTROLLER — Riverpod state cho trang shipper
/// ============================================================
/// Quản lý:
///   - list đơn (đồng bộ từ backend, lọc theo 3 tab ở UI)
///   - stats cá nhân (đếm đơn + COD)
///   - busyId: id đơn đang được xử lý (pickup/arrived/complete/issue)
///   - auto-refresh định kỳ 30s (mềm, không ép UI reload)
/// ============================================================

enum ShipperTab { nearby, assigned, delivering, awaiting, completed }

class ShipperHomeState {
  ShipperHomeState({
    this.orders = const [],
    this.nearbyJobs = const [],
    this.nearbyLoaded = false,
    this.stats,
    this.isLoading = false,
    this.busyId,
    this.errorMessage,
    this.lastRefreshedAt,
    this.hasLoadedOnce = false,
  });

  final List<DeliveryOrder> orders;
  final List<NearbyJob> nearbyJobs;
  final bool nearbyLoaded;
  final ShipperStats? stats;
  final bool isLoading;

  /// ID đơn đang được xử lý (disable nút khi action pending)
  final int? busyId;
  final String? errorMessage;
  final DateTime? lastRefreshedAt;
  final bool hasLoadedOnce;

  /// Đơn theo tab
  List<DeliveryOrder> get deliveringOrders =>
      orders.where((o) => o.status == OrderStatus.delivering).toList();

  List<DeliveryOrder> get assignedOrders => orders
      .where(
        (o) => [
          OrderStatus.confirmed,
          OrderStatus.preparing,
          OrderStatus.ready,
          OrderStatus.readyNotifyShipper,
        ].contains(o.status),
      )
      .toList();

  List<DeliveryOrder> get awaitingOrders =>
      orders.where((o) => o.status == OrderStatus.delivered).toList();

  List<DeliveryOrder> get completedOrders =>
      orders.where((o) => o.status == OrderStatus.completed).toList();

  ShipperHomeState copyWith({
    List<DeliveryOrder>? orders,
    List<NearbyJob>? nearbyJobs,
    bool? nearbyLoaded,
    ShipperStats? stats,
    bool? isLoading,
    int? busyId,
    String? errorMessage,
    DateTime? lastRefreshedAt,
    bool? hasLoadedOnce,
    bool clearBusy = false,
    bool clearError = false,
  }) {
    return ShipperHomeState(
      orders: orders ?? this.orders,
      nearbyJobs: nearbyJobs ?? this.nearbyJobs,
      nearbyLoaded: nearbyLoaded ?? this.nearbyLoaded,
      stats: stats ?? this.stats,
      isLoading: isLoading ?? this.isLoading,
      busyId: clearBusy ? null : (busyId ?? this.busyId),
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      lastRefreshedAt: lastRefreshedAt ?? this.lastRefreshedAt,
      hasLoadedOnce: hasLoadedOnce ?? this.hasLoadedOnce,
    );
  }
}

class ShipperHomeController extends StateNotifier<ShipperHomeState> {
  ShipperHomeController(this._repo) : super(ShipperHomeState()) {
    refresh();
  }

  final ShipperRepository _repo;
  int _revision = 0;

  /// Public alias cho `state` để widget có thể đọc current state mà
  /// không phải dùng API protected `state` của StateNotifier.
  ShipperHomeState get currentState => state;

  /// Refresh cả list + stats.
  /// `quiet=true`: không hiển thị loading spinner (dùng cho auto-refresh).
  Future<void> refresh({bool quiet = false}) async {
    final revision = ++_revision;
    if (!quiet) {
      state = state.copyWith(isLoading: true, clearError: true);
    }
    bool current() => mounted && revision == _revision;

    Future<void> loadOrders() async {
      try {
        final orders = await _repo.fetchDeliveries();
        if (!current()) return;
        state = state.copyWith(
          orders: orders,
          isLoading: false,
          hasLoadedOnce: true,
          lastRefreshedAt: DateTime.now(),
        );
      } catch (e) {
        if (!current()) return;
        state = state.copyWith(
          isLoading: false,
          hasLoadedOnce: true,
          errorMessage: e is ApiException
              ? e.message
              : 'Không tải được đơn giao hàng. Vui lòng thử lại.',
        );
      }
    }

    Future<void> loadStats() async {
      try {
        final stats = await _repo.fetchStats();
        if (current()) {
          state = state.copyWith(stats: stats);
        }
      } catch (e) {
        if (current()) {
          state = state.copyWith(
            errorMessage: e is ApiException
                ? e.message
                : 'Không tải được thống kê.',
          );
        }
      }
    }

    Future<void> loadNearby() async {
      try {
        final nearby = await _repo.fetchNearbyJobs();
        if (current()) {
          state = state.copyWith(nearbyJobs: nearby, nearbyLoaded: true);
        }
      } catch (e) {
        if (current()) {
          state = state.copyWith(
            nearbyLoaded: true,
            errorMessage: e is ApiException
                ? e.message
                : 'Không tải được đơn gần bạn.',
          );
        }
      }
    }

    unawaited(loadStats());
    unawaited(loadNearby());
    await loadOrders();
  }

  Future<bool> accept(int orderId) async {
    if (state.busyId != null) return false;
    state = state.copyWith(busyId: orderId, clearError: true);
    try {
      await _repo.accept(orderId);
      if (!mounted) return false;
      state = state.copyWith(clearBusy: true);
      await refresh(quiet: true);
      return true;
    } catch (e) {
      if (!mounted) return false;
      state = state.copyWith(
        clearBusy: true,
        errorMessage: e is ApiException
            ? e.message
            : 'Không nhận được đơn. Vui lòng thử lại.',
      );
      return false;
    }
  }

  /// Sau khi 1 action thành công (pickup/arrived/complete/issue), backend trả về
  /// DeliveryOrder mới nhất → cập nhật state trực tiếp, không cần refresh toàn list.
  void _applyUpdatedOrder(DeliveryOrder updated) {
    final next = [
      for (final o in state.orders)
        if (o.id == updated.id) updated else o,
    ];
    state = state.copyWith(orders: next);
  }

  /// Hành động pickup/arrived/complete — trả về true nếu thành công.
  Future<bool> _runAction(
    Future<DeliveryOrder> Function() action,
    int orderId,
  ) async {
    if (state.busyId != null) return false;
    state = state.copyWith(busyId: orderId, clearError: true);
    try {
      final updated = await action();
      if (!mounted) return false;
      _applyUpdatedOrder(updated);
      state = state.copyWith(clearBusy: true);
      return true;
    } catch (e) {
      if (!mounted) return false;
      final msg = e is ApiException
          ? e.message
          : 'Không thể cập nhật đơn. Vui lòng thử lại.';
      state = state.copyWith(errorMessage: msg, clearBusy: true);
      return false;
    }
  }

  Future<bool> pickup(int orderId) =>
      _runAction(() => _repo.pickup(orderId), orderId);

  Future<bool> arrived(int orderId) =>
      _runAction(() => _repo.arrived(orderId), orderId);

  Future<bool> complete(int orderId) =>
      _runAction(() => _repo.complete(orderId), orderId);

  Future<bool> reportIssue(int orderId, String reason) =>
      _runAction(() => _repo.reportIssue(orderId, reason), orderId);
}

final shipperHomeControllerProvider =
    StateNotifierProvider<ShipperHomeController, ShipperHomeState>((ref) {
      ref.watch(authControllerProvider.select((a) => (a.user?.id, a.token)));
      return ShipperHomeController(ref.watch(shipperRepositoryProvider));
    });
