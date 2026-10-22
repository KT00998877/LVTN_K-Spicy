import '../../menu/presentation/quick_add_sheet.dart';
import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../../shared/widgets/food_card.dart';
import '../../menu/domain/menu_models.dart';
import '../domain/cart_models.dart';
import 'cart_controller.dart';

/// ============================================================
/// 🛒 CART TAB — phiên bản mobile-first dùng cho bottom nav
/// ============================================================
/// Sections:
///   1. Header: title + "Xoá tất cả"
///   2. List cart items (kéo dọc)
///   3. Voucher line (placeholder)
///   4. Sticky bottom: subtotal + nút "Đặt món"
/// ============================================================

class CartTabScreen extends ConsumerStatefulWidget {
  const CartTabScreen({super.key});

  @override
  ConsumerState<CartTabScreen> createState() => _CartTabScreenState();
}

class _CartTabScreenState extends ConsumerState<CartTabScreen> {
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
      backgroundColor: AppColors.cream,
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
        color: AppColors.spicyRed,
        onRefresh: () => ref.read(cartControllerProvider.notifier).refresh(),
        child: _buildBody(state),
      ),
      bottomNavigationBar: state.isNotEmpty
          ? _CartBottomBar(subtotal: state.subtotal)
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
        onAction: () {
          // Switch sang tab Menu (index 1) thông qua parent shell.
          // Ở đây ta dùng context.go để đưa về home, parent
          // shell sẽ xử lý.
          context.go('/home');
        },
      );
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
      ),
      children: [
        for (int i = 0; i < state.cart!.items.length; i++) ...[
          _CartItemTile(item: state.cart!.items[i]),
          if (i < state.cart!.items.length - 1)
            const SizedBox(height: AppSpacing.sm),
        ],
        const SizedBox(height: AppSpacing.md),
        const _VoucherHint(),
      ],
    );
  }
}

/// ───────────────── Item tile ─────────────────
class _CartItemTile extends ConsumerWidget {
  const _CartItemTile({required this.item});
  final CartItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unitPrice = item.unitPrice + item.toppingPrice;
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: AppShadow.card,
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Image ──────────────────────────────────────
          SizedBox(
            width: 88,
            height: 88,
            child: BackendImage(
              imageUrl: item.menuItemImageUrl,
              fit: BoxFit.cover,
              placeholder: (_, __) => Container(color: AppColors.creamSoft),
              errorWidget: (_, __, ___) =>
                  Container(color: AppColors.creamSoft),
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.sm,
                AppSpacing.sm,
                AppSpacing.xs,
                AppSpacing.sm,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.menuItemName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 14,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      Text(
                        'Cấp ${item.spicyLevel}',
                        style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.textSecondary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        MoneyVnd.format(unitPrice),
                        style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.textMuted,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  if (item.toppings.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      '+ ${item.toppings.map((t) => t.name).join(', ')}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                  if (item.note != null && item.note!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      '📝 ${item.note!}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 10,
                        fontStyle: FontStyle.italic,
                        color: AppColors.textMuted,
                      ),
                    ),
                  ],
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      PriceDisplay(
                        amount: item.subtotal,
                        size: PriceSize.small,
                      ),

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
                        min: 0,
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
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Voucher hint ─────────────────
class _VoucherHint extends StatelessWidget {
  const _VoucherHint();
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.goldenYellow.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: AppColors.goldenYellow.withValues(alpha: 0.4),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.local_offer_rounded,
            color: AppColors.goldenYellow,
            size: 18,
          ),
          const SizedBox(width: 8),
          const Expanded(
            child: Text(
              'Nhập mã giảm giá ở bước Đặt món',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          const Icon(
            Icons.chevron_right_rounded,
            color: AppColors.textMuted,
            size: 18,
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Bottom bar ─────────────────
class _CartBottomBar extends StatelessWidget {
  const _CartBottomBar({required this.subtotal});
  final double subtotal;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
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
                  const Text(
                    'Tạm tính',
                    style: TextStyle(
                      color: AppColors.textSecondary,
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.4,
                    ),
                  ),
                  PriceDisplay(amount: subtotal, size: PriceSize.large),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(minimumSize: const Size(0, 52)),
              onPressed: () => context.push('/checkout'),
              icon: const Icon(Icons.arrow_forward_rounded),
              label: const Text('Đặt món'),
            ),
          ],
        ),
      ),
    );
  }
}
