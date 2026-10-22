import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/cart_repository.dart';
import '../data/pending_checkout_store.dart';
import '../domain/cart_models.dart';

class CartState {
  CartState({
    this.cart,
    this.isLoading = false,
    this.errorMessage,
    this.pendingCheckout,
    this.isSubmitting = false,
  });
  final Cart? cart;
  final bool isLoading;
  final bool isSubmitting;
  final String? errorMessage;
  final Map<String, dynamic>? pendingCheckout;
  bool get canMutate => !isLoading && !isSubmitting && pendingCheckout == null;
  int get count => cart?.totalQuantity ?? 0;
  bool get isEmpty => cart == null || cart!.isEmpty;
  bool get isNotEmpty => !isEmpty;
  double get subtotal => cart?.subtotal ?? 0;

  CartState copyWith({
    Cart? cart,
    bool clearCart = false,
    bool? isLoading,
    bool? isSubmitting,
    String? errorMessage,
    bool clearError = false,
    Map<String, dynamic>? pendingCheckout,
    bool clearPending = false,
  }) => CartState(
    cart: clearCart ? null : cart ?? this.cart,
    isLoading: isLoading ?? this.isLoading,
    isSubmitting: isSubmitting ?? this.isSubmitting,
    errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    pendingCheckout: clearPending
        ? null
        : pendingCheckout ?? this.pendingCheckout,
  );
}

class CartController extends StateNotifier<CartState> {
  CartController(this._repo, this._store) : super(CartState());
  final CartRepository _repo;
  final PendingCheckoutStore _store;
  int _revision = 0;

  Future<void> refresh() async {
    if (state.isSubmitting || state.isLoading) return;
    final revision = ++_revision;
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final pending = await _store.read();
      if (!mounted || revision != _revision) return;
      state = state.copyWith(pendingCheckout: pending);
      final cart = await _repo.get();
      if (!mounted || revision != _revision) return;
      state = state.copyWith(
        cart: cart,
        pendingCheckout: pending,
        isLoading: false,
      );
    } catch (e, stack) {
      if (kDebugMode) debugPrint('CartController.refresh: $e\n$stack');
      if (mounted && revision == _revision) {
        state = state.copyWith(isLoading: false, errorMessage: _msg(e));
      }
    }
  }

  Future<bool> _mutate(Future<void> Function() operation) async {
    if (!state.canMutate) return false;
    ++_revision;
    state = state.copyWith(isLoading: true, clearError: true);
    var applied = false;
    try {
      final pending = await _store.read();
      if (!mounted) return false;
      if (pending != null) {
        state = state.copyWith(
          pendingCheckout: pending,
          isLoading: false,
          errorMessage: 'Hãy vào thanh toán để kiểm tra kết quả lần đặt trước.',
        );
        return false;
      }
      await operation();
      applied = true;
      if (!mounted) return false;
      state = state.copyWith(clearCart: true);
      final cart = await _repo.get();
      if (!mounted) return false;
      state = state.copyWith(cart: cart, isLoading: false);
      return true;
    } catch (e, stack) {
      if (kDebugMode) {
        debugPrint('CartController.mutate (applied=$applied): $e\n$stack');
      }
      if (!mounted) return false;
      state = state.copyWith(
        isLoading: false,
        errorMessage: applied
            ? 'Đã lưu thay đổi. Chưa tải lại được giỏ hàng. Vui lòng thử tải lại.'
            : _msg(e),
      );
      // A failed read must not invite the customer to repeat a successful POST.
      return applied;
    }
  }

  Future<bool> addItem({
    required int menuItemId,
    int quantity = 1,
    int spicyLevel = 0,
    String? note,
    List<int> toppingIds = const [],
  }) => _mutate(() async {
    await _repo.addItem(
      menuItemId: menuItemId,
      quantity: quantity,
      spicyLevel: spicyLevel,
      note: note,
      toppingIds: toppingIds,
    );
  });

  Future<bool> updateItem({
    required int cartItemId,
    required int quantity,
    required int spicyLevel,
    required String note,
    required List<int> toppingIds,
  }) => _mutate(() async {
    await _repo.updateItem(
      cartItemId: cartItemId,
      quantity: quantity,
      spicyLevel: spicyLevel,
      note: note,
      toppingIds: toppingIds,
    );
  });

  Future<bool> updateQuantity(int id, int quantity) => quantity < 1
      ? removeItem(id)
      : _mutate(() async {
          await _repo.updateItem(cartItemId: id, quantity: quantity);
        });
  Future<bool> removeItem(int id) => _mutate(() => _repo.removeItem(id));
  Future<bool> clear() => _mutate(_repo.clear);
  Future<void> increment(int id) async {
    final item = state.cart?.items.where((i) => i.id == id).firstOrNull;
    if (item != null && item.quantity < 99) {
      await updateQuantity(id, item.quantity + 1);
    }
  }

  Future<void> decrement(int id) async {
    final item = state.cart?.items.where((i) => i.id == id).firstOrNull;
    if (item != null) await updateQuantity(id, item.quantity - 1);
  }

  Future<CheckoutResult?> checkout(Map<String, dynamic> input) async {
    if (state.isLoading || state.isSubmitting) return null;
    state = state.copyWith(isSubmitting: true, clearError: true);
    ++_revision;
    try {
      final saved = state.pendingCheckout ?? await _store.read();
      if (!mounted) return null;
      if (saved == null && state.isEmpty) {
        throw const ApiException(message: 'Giỏ hàng trống. Vui lòng chọn món.');
      }
      final payload =
          saved ??
          {
            ...input,
            'cart_version': state.cart!.version,
            'idempotency_key': const Uuid().v4(),
          };
      await _store.write(payload);
      if (!mounted) return null;
      state = state.copyWith(pendingCheckout: payload);
      final result = await _repo.checkoutPayload(payload);
      // Cleanup uses the original user's store even if they logged out meanwhile.
      await _store.clear();
      if (!mounted) return null;
      state = CartState();
      return result;
    } catch (e) {
      if (!mounted) return null;
      if (e is ApiException && (e.statusCode == 409 || e.statusCode == 422)) {
        await _store.clear();
        if (!mounted) return null;
        state = state.copyWith(clearPending: true, isSubmitting: false);
        await refresh();
        if (!mounted) return null;
      }
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: state.pendingCheckout != null
            ? 'Chưa xác định được kết quả. Bấm kiểm tra lại để nhận kết quả của cùng lần đặt.'
            : _msg(e),
      );
      return null;
    }
  }

  String _msg(Object e) => e is ApiException
      ? (e.errors?.values.expand((v) => v).join('\n') ?? e.message)
      : 'Không thể cập nhật giỏ hàng. Vui lòng thử lại.';
}

final cartControllerProvider = StateNotifierProvider<CartController, CartState>(
  (ref) {
    final session = ref.watch(
      authControllerProvider.select((a) => (a.user?.id, a.token)),
    );
    return CartController(
      ref.watch(cartRepositoryProvider),
      PendingCheckoutStore(session.$1 ?? 0),
    );
  },
);
final cartBadgeCountProvider = Provider<int>(
  (ref) => ref.watch(cartControllerProvider).count,
);
