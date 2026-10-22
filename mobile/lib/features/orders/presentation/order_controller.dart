import '../../../core/api/api_exception.dart';
import '../../auth/presentation/auth_controller.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/order_repository.dart';
import '../domain/order_models.dart';

/// ============================================================
/// 📦 ORDER STATE NOTIFIER
/// ============================================================
/// Quản lý: danh sách đơn + filter status, và 1 cache cho
/// OrderDetail hiện tại (dùng khi user vừa checkout xong).
/// ============================================================

class OrderListState {
  OrderListState({
    this.items = const [],
    this.meta,
    this.isLoading = false,
    this.isLoadingMore = false,
    this.errorMessage,
    this.activeFilter,
    this.hasLoadedOnce = false,
  });

  final List<OrderSummary> items;
  final OrderListMeta? meta;
  final bool isLoading;
  final bool isLoadingMore;
  final String? errorMessage;
  final OrderStatus? activeFilter;
  final bool hasLoadedOnce;

  bool get hasMore => meta != null && meta!.currentPage < meta!.lastPage;

  OrderListState copyWith({
    List<OrderSummary>? items,
    OrderListMeta? meta,
    bool? isLoading,
    bool? isLoadingMore,
    String? errorMessage,
    OrderStatus? activeFilter,
    bool? hasLoadedOnce,
    bool clearFilter = false,
    bool clearError = false,
  }) {
    return OrderListState(
      items: items ?? this.items,
      meta: meta ?? this.meta,
      isLoading: isLoading ?? this.isLoading,
      isLoadingMore: isLoadingMore ?? this.isLoadingMore,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      activeFilter: clearFilter ? null : (activeFilter ?? this.activeFilter),
      hasLoadedOnce: hasLoadedOnce ?? this.hasLoadedOnce,
    );
  }
}

class OrderListController extends StateNotifier<OrderListState> {
  OrderListController(this._repo) : super(OrderListState()) {
    load();
  }

  final OrderRepository _repo;
  int _revision = 0;

  Future<void> load({OrderStatus? filter, bool force = false}) async {
    if (!force && state.hasLoadedOnce && state.activeFilter == filter) {
      return;
    }
    final revision = ++_revision;
    state = state.copyWith(
      isLoading: true,
      activeFilter: filter,
      clearError: true,
      clearFilter: filter == null,
    );
    try {
      final result = await _repo.list(status: filter, page: 1);
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        items: result.items,
        meta: result.meta,
        isLoading: false,
        hasLoadedOnce: true,
      );
    } catch (e) {
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        isLoading: false,
        errorMessage: e is ApiException
            ? e.message
            : 'Không tải được đơn hàng. Vui lòng thử lại.',
      );
    }
  }

  Future<void> refresh() => load(filter: state.activeFilter, force: true);

  Future<void> loadMore() async {
    if (state.isLoading ||
        state.isLoadingMore ||
        !state.hasMore ||
        state.meta == null)
      return;
    final revision = _revision;
    state = state.copyWith(isLoadingMore: true);
    try {
      final result = await _repo.list(
        status: state.activeFilter,
        page: state.meta!.currentPage + 1,
      );
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        items: [...state.items, ...result.items],
        meta: result.meta,
        isLoadingMore: false,
      );
    } catch (e) {
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        isLoadingMore: false,
        errorMessage: e is ApiException
            ? e.message
            : 'Không tải được đơn hàng. Vui lòng thử lại.',
      );
    }
  }
}

final orderListControllerProvider =
    StateNotifierProvider<OrderListController, OrderListState>((ref) {
      ref.watch(authControllerProvider.select((a) => (a.user?.id, a.token)));
      return OrderListController(ref.watch(orderRepositoryProvider));
    });

/// ============================================================
/// 📄 ORDER DETAIL CONTROLLER — state cho OrderDetailScreen
/// ============================================================

class OrderDetailState {
  OrderDetailState({this.order, this.isLoading = false, this.errorMessage});

  final OrderDetail? order;
  final bool isLoading;
  final String? errorMessage;

  OrderDetailState copyWith({
    OrderDetail? order,
    bool? isLoading,
    String? errorMessage,
    bool clearError = false,
  }) => OrderDetailState(
    order: order ?? this.order,
    isLoading: isLoading ?? this.isLoading,
    errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
  );
}

class OrderDetailController extends StateNotifier<OrderDetailState> {
  OrderDetailController(this._repo, this._orderId)
    : super(OrderDetailState(isLoading: true)) {
    load();
  }

  final OrderRepository _repo;
  final int _orderId;

  Future<void> load() async {
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final order = await _repo.getById(_orderId);
      if (!mounted) return;
      state = state.copyWith(order: order, isLoading: false);
    } catch (e) {
      if (!mounted) return;
      state = state.copyWith(
        isLoading: false,
        errorMessage: e is ApiException
            ? e.message
            : 'Không tải được đơn hàng. Vui lòng thử lại.',
      );
    }
  }

  Future<ConfirmReceiptResult?> confirmReceipt() async {
    if (state.isLoading) return null;
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final result = await _repo.confirmReceipt(_orderId);
      if (!mounted) return null;
      state = state.copyWith(order: result.order, isLoading: false);
      return result;
    } catch (e) {
      if (!mounted) return null;
      state = state.copyWith(
        isLoading: false,
        errorMessage: e is ApiException
            ? e.message
            : 'Không tải được đơn hàng. Vui lòng thử lại.',
      );
      return null;
    }
  }
}

/// Family provider — mỗi orderId có 1 controller riêng
final orderDetailControllerProvider =
    StateNotifierProvider.family<OrderDetailController, OrderDetailState, int>((
      ref,
      orderId,
    ) {
      ref.watch(authControllerProvider.select((a) => (a.user?.id, a.token)));
      return OrderDetailController(ref.watch(orderRepositoryProvider), orderId);
    });
