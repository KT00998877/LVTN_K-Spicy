import '../../menu/presentation/quick_add_sheet.dart';
import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:micay_app/app/theme.dart';
import 'package:micay_app/features/cart/domain/cart_models.dart';
import 'package:micay_app/features/cart/presentation/cart_controller.dart';
import 'package:micay_app/features/menu/domain/menu_models.dart';
import 'package:micay_app/shared/widgets/common_widgets.dart';
import 'package:micay_app/shared/widgets/food_card.dart';

/// ============================================================
/// 🛒 CART SCREEN — giỏ hàng
/// ============================================================
class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(cartControllerProvider.notifier).refresh();
    });
  }

  Future<void> _confirmClear() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Xoá toàn bộ giỏ hàng?'),
        content: const Text('Bạn sẽ mất tất cả món đã chọn.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Huỷ'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Xoá', style: TextStyle(color: AppColors.danger)),
          ),
        ],
      ),
    );
    if (mounted && ok == true) {
      await ref.read(cartControllerProvider.notifier).clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(cartControllerProvider);
    ref.listen(cartControllerProvider.select((s) => s.errorMessage), (
      _,
      message,
    ) {
      if (message != null) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(message)));
      }
    });

    return Scaffold(
      appBar: AppBar(
        title: const Text('Giỏ hàng'),
        actions: [
          if (state.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.delete_outline_rounded),
              tooltip: 'Xoá tất cả',
              onPressed: !state.canMutate ? null : _confirmClear,
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(cartControllerProvider.notifier).refresh(),
        child: _buildBody(state),
      ),
      bottomNavigationBar: state.isNotEmpty
          ? SafeArea(
              child: Container(
                padding: const EdgeInsets.all(AppSpacing.md),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  boxShadow: [
                    BoxShadow(
                      color: Color(0x1A1A1A1A),
                      blurRadius: 16,
                      offset: Offset(0, -4),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            'Tạm tính',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          PriceDisplay(
                            amount: state.subtotal,
                            size: PriceSize.large,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        minimumSize: const Size(0, 52),
                      ),
                      onPressed: () => context.push('/checkout'),
                      icon: const Icon(Icons.arrow_forward_rounded),
                      label: const Text('Đặt món'),
                    ),
                  ],
                ),
              ),
            )
          : null,
    );
  }

  Widget _buildBody(CartState state) {
    if (state.isLoading && state.cart == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.pendingCheckout != null) {
      return EmptyState(
        icon: '🧾',
        title: 'Kiểm tra lần đặt trước',
        subtitle:
            state.errorMessage ?? 'Yêu cầu đặt món đang chờ xác định kết quả.',
        actionLabel: 'Kiểm tra kết quả',
        onAction: () => context.push('/checkout'),
      );
    }
    if (state.errorMessage != null && state.cart == null) {
      return EmptyState(
        icon: '!',
        title: 'Không tải được giỏ hàng',
        subtitle: state.errorMessage,
        actionLabel: 'Thử lại',
        onAction: () => ref.read(cartControllerProvider.notifier).refresh(),
      );
    }
    if (state.isEmpty) {
      return EmptyState(
        icon: '🛒',
        title: 'Giỏ hàng trống',
        subtitle: 'Hãy chọn món bạn thích nhé!',
        actionLabel: 'Khám phá menu',
        onAction: () => context.go('/home'),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.all(AppSpacing.md),
      itemCount: state.cart!.items.length,
      separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
      itemBuilder: (_, i) {
        final item = state.cart!.items[i];
        return _CartItemTile(item: item);
      },
    );
  }
}

class _CartItemTile extends ConsumerWidget {
  const _CartItemTile({required this.item});
  final CartItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: AppShadow.card,
      ),
      padding: const EdgeInsets.all(AppSpacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.md),
            child: BackendImage(
              imageUrl: item.menuItemImageUrl,
              width: 76,
              height: 76,
              fit: BoxFit.cover,
              placeholder: (_, __) =>
                  Container(color: AppColors.creamSoft, width: 76, height: 76),
              errorWidget: (_, __, ___) =>
                  Container(color: AppColors.creamSoft, width: 76, height: 76),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.menuItemName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Text(
                      'Cấp ${item.spicyLevel}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
                if (item.toppings.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    '+ ${item.toppings.map((t) => t.name).join(', ')}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
                const SizedBox(height: AppSpacing.xs),
                Wrap(
                  spacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    PriceDisplay(amount: item.subtotal, size: PriceSize.small),

                    IconButton(
                      tooltip: 'Sửa món',
                      icon: const Icon(Icons.edit_outlined),
                      onPressed: !ref.watch(cartControllerProvider).canMutate
                          ? null
                          : () => QuickAddSheet.show(
                              context,
                              MenuItem(
                                id: item.menuItemId,
                                name: item.menuItemName,
                                slug: '',
                                imageUrl: item.menuItemImageUrl,
                                price: item.unitPrice,
                              ),
                              cartItem: item,
                            ),
                    ),
                    QuantityStepper(
                      value: item.quantity,
                      max: 99,
                      onChanged: (v) {
                        if (v == 0) {
                          ref
                              .read(cartControllerProvider.notifier)
                              .removeItem(item.id);
                        } else {
                          ref
                              .read(cartControllerProvider.notifier)
                              .updateQuantity(item.id, v);
                        }
                      },
                      min: 0,
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
