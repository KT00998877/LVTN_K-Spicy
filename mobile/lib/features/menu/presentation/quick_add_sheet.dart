import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../../shared/widgets/food_card.dart';
import '../../cart/presentation/cart_controller.dart';
import '../../cart/domain/cart_models.dart';
import '../../menu/data/menu_repository.dart';
import '../../menu/domain/menu_models.dart';

/// ============================================================
/// 🧂 TOPPINGS PROVIDER — cache danh sách topping
/// ============================================================
final toppingsProvider = FutureProvider<List<Topping>>((ref) async {
  return ref.watch(menuRepositoryProvider).getToppings();
});

/// ============================================================
/// 🪟 QUICK-ADD BOTTOM SHEET — thêm nhanh 1 món vào giỏ
/// ============================================================
/// Mobile-first flow:
///   1. Hiển thị ảnh + tên + giá món
///   2. Cấp độ cay (SpicySelector có sẵn)
///   3. Multi-select topping (nếu có)
///   4. Ghi chú (optional, 1 dòng)
///   5. Số lượng + nút "Thêm vào giỏ"
/// ============================================================

class QuickAddSheet extends ConsumerStatefulWidget {
  const QuickAddSheet({super.key, required this.item, this.cartItem});
  final MenuItem item;
  final CartItem? cartItem;

  static final _openNavigators = <NavigatorState>{};

  static Future<void> show(
    BuildContext context,
    MenuItem item, {
    CartItem? cartItem,
  }) async {
    final navigator = Navigator.of(context);
    if (!_openNavigators.add(navigator)) return;
    try {
      await showModalBottomSheet<void>(
        context: context,
        useSafeArea: true,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (sheetContext) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.viewInsetsOf(sheetContext).bottom,
          ),
          child: QuickAddSheet(item: item, cartItem: cartItem),
        ),
      );
    } finally {
      _openNavigators.remove(navigator);
    }
  }

  @override
  ConsumerState<QuickAddSheet> createState() => _QuickAddSheetState();
}

class _QuickAddSheetState extends ConsumerState<QuickAddSheet> {
  int _qty = 1;
  int _spicyLevel = 0;
  final Set<int> _toppingIds = <int>{};
  final _noteCtrl = TextEditingController();
  bool _isAdding = false;

  @override
  void initState() {
    super.initState();
    _spicyLevel = widget.cartItem?.spicyLevel ?? widget.item.spicyLevel;
    _qty = widget.cartItem?.quantity ?? 1;
    _noteCtrl.text = widget.cartItem?.note ?? '';
    _toppingIds.addAll(widget.cartItem?.toppingIds ?? []);
  }

  @override
  void dispose() {
    _noteCtrl.dispose();
    super.dispose();
  }

  double get _toppingTotal {
    final list = ref.read(toppingsProvider).valueOrNull ?? const <Topping>[];
    return _toppingIds
        .map(
          (id) => list
              .firstWhere(
                (t) => t.id == id,
                orElse: () => Topping(id: id, name: '', price: 0),
              )
              .price,
        )
        .fold<double>(0, (s, p) => s + p);
  }

  double get _unitPrice => widget.item.price + _toppingTotal;

  double get _lineTotal => _unitPrice * _qty;

  Future<void> _confirm() async {
    if (_isAdding ||
        !widget.item.isAvailable ||
        !ref.read(cartControllerProvider).canMutate ||
        !ref.read(toppingsProvider).hasValue) {
      return;
    }
    setState(() => _isAdding = true);
    final controller = ref.read(cartControllerProvider.notifier);
    final ok = widget.cartItem == null
        ? await controller.addItem(
            menuItemId: widget.item.id,
            quantity: _qty,
            spicyLevel: _spicyLevel,
            note: _noteCtrl.text.trim(),
            toppingIds: _toppingIds.toList(),
          )
        : await controller.updateItem(
            cartItemId: widget.cartItem!.id,
            quantity: _qty,
            spicyLevel: _spicyLevel,
            note: _noteCtrl.text.trim(),
            toppingIds: _toppingIds.toList(),
          );
    if (!mounted) return;
    setState(() => _isAdding = false);

    if (ok) {
      final messenger = ScaffoldMessenger.of(context);
      Navigator.of(context).pop();
      messenger.showSnackBar(
        SnackBar(
          content: Text('Đã cập nhật ${widget.item.name} trong giỏ'),
          backgroundColor: AppColors.spicyRed,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ref.read(cartControllerProvider).errorMessage ??
                'Không cập nhật được giỏ hàng',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    ref.watch(toppingsProvider);
    return DraggableScrollableSheet(
      initialChildSize: 0.7,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (_, scrollCtrl) {
        return Container(
          decoration: const BoxDecoration(
            color: AppColors.cream,
            borderRadius: BorderRadius.vertical(
              top: Radius.circular(AppRadius.xl),
            ),
          ),
          child: Column(
            children: [
              // ── Drag handle ────────────────────────────────
              Align(
                alignment: Alignment.centerRight,
                child: IconButton(
                  tooltip: 'Đóng',
                  onPressed: () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ),
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(
                    color: AppColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),

              Expanded(
                child: ListView(
                  controller: scrollCtrl,
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.md,
                    0,
                    AppSpacing.md,
                    AppSpacing.md,
                  ),
                  children: [
                    // ── Item header ────────────────────────────
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.name,
                                style: Theme.of(
                                  context,
                                ).textTheme.headlineSmall,
                              ),
                              if (item.categoryName != null)
                                Text(
                                  item.categoryName!,
                                  style: const TextStyle(
                                    color: AppColors.textSecondary,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        PriceDisplay(
                          amount: item.price,
                          size: PriceSize.medium,
                        ),
                      ],
                    ),
                    if (item.description != null &&
                        item.description!.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        item.description!,
                        style: const TextStyle(
                          color: AppColors.textSecondary,
                          height: 1.5,
                        ),
                      ),
                    ],

                    const SizedBox(height: AppSpacing.md),
                    const Divider(),
                    const SizedBox(height: AppSpacing.sm),

                    // ── Spicy ─────────────────────────────────
                    const _SectionLabel('Cấp độ cay'),
                    const SizedBox(height: AppSpacing.xs),
                    SpicySelector(
                      value: _spicyLevel,
                      onChanged: (v) => setState(() => _spicyLevel = v),
                      maxLevel: 7,
                    ),

                    const SizedBox(height: AppSpacing.md),

                    // ── Toppings ──────────────────────────────
                    const _SectionLabel('Topping thêm'),
                    const SizedBox(height: AppSpacing.xs),
                    _ToppingPicker(
                      selectedIds: _toppingIds,
                      onToggle: (id) {
                        setState(() {
                          if (_toppingIds.contains(id)) {
                            _toppingIds.remove(id);
                          } else {
                            _toppingIds.add(id);
                          }
                        });
                      },
                    ),

                    const SizedBox(height: AppSpacing.md),

                    // ── Note ──────────────────────────────────
                    const _SectionLabel('Ghi chú (tuỳ chọn)'),
                    const SizedBox(height: AppSpacing.xs),
                    TextField(
                      controller: _noteCtrl,
                      maxLines: 2,
                      maxLength: 200,
                      decoration: const InputDecoration(
                        hintText: 'Ít hành, không cay, thêm phô mai...',
                        counterText: '',
                      ),
                    ),

                    const SizedBox(height: AppSpacing.md),

                    // ── Quantity ──────────────────────────────
                    Row(
                      children: [
                        const _SectionLabel('Số lượng'),
                        const Spacer(),
                        QuantityStepper(
                          value: _qty,
                          max: 99,
                          onChanged: (v) => setState(() => _qty = v),
                        ),
                      ],
                    ),

                    const SizedBox(height: AppSpacing.md),
                  ],
                ),
              ),

              // ── Sticky bottom button ───────────────────────
              SafeArea(
                top: false,
                child: Container(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.06),
                        blurRadius: 12,
                        offset: const Offset(0, -4),
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
                              'Tổng',
                              style: TextStyle(
                                color: AppColors.textSecondary,
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 0.4,
                              ),
                            ),
                            Text(
                              MoneyVnd.format(_lineTotal),
                              style: const TextStyle(
                                color: AppColors.spicyRed,
                                fontWeight: FontWeight.w900,
                                fontSize: 18,
                                letterSpacing: -0.4,
                              ),
                            ),
                          ],
                        ),
                      ),
                      SizedBox(
                        height: 52,
                        child: ElevatedButton.icon(
                          onPressed:
                              _isAdding ||
                                  !item.isAvailable ||
                                  !ref.watch(toppingsProvider).hasValue ||
                                  !ref.watch(cartControllerProvider).canMutate
                              ? null
                              : _confirm,
                          icon: _isAdding
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.5,
                                    color: Colors.white,
                                  ),
                                )
                              : const Icon(Icons.add_shopping_cart_rounded),
                          label: Text(
                            widget.cartItem == null
                                ? 'Thêm vào giỏ'
                                : 'Lưu thay đổi',
                          ),
                          style: ElevatedButton.styleFrom(
                            minimumSize: const Size(0, 52),
                            backgroundColor: AppColors.spicyRed,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                              horizontal: AppSpacing.lg,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontSize: 13,
        fontWeight: FontWeight.w800,
        letterSpacing: -0.1,
      ),
    );
  }
}

class _ToppingPicker extends ConsumerWidget {
  const _ToppingPicker({required this.selectedIds, required this.onToggle});

  final Set<int> selectedIds;
  final ValueChanged<int> onToggle;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(toppingsProvider);
    return async.when(
      loading: () => const Padding(
        padding: EdgeInsets.all(AppSpacing.md),
        child: Center(
          child: SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2.5),
          ),
        ),
      ),
      error: (e, _) => Padding(
        padding: const EdgeInsets.all(AppSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Không tải được topping. Vui lòng thử lại.',
              style: TextStyle(color: AppColors.danger, fontSize: 12),
            ),
            TextButton(
              onPressed: () => ref.invalidate(toppingsProvider),
              child: const Text('Thử lại'),
            ),
          ],
        ),
      ),
      data: (toppings) {
        if (toppings.isEmpty) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: AppSpacing.xs),
            child: Text(
              'Món này hiện chưa có topping thêm.',
              style: TextStyle(color: AppColors.textMuted, fontSize: 12),
            ),
          );
        }
        return Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final t in toppings)
              _ToppingChip(
                topping: t,
                selected: selectedIds.contains(t.id),
                onTap: () => onToggle(t.id),
              ),
          ],
        );
      },
    );
  }
}

class _ToppingChip extends StatelessWidget {
  const _ToppingChip({
    required this.topping,
    required this.selected,
    required this.onTap,
  });

  final Topping topping;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? AppColors.spicyRedSoft : Colors.white,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            border: Border.all(
              color: selected ? AppColors.spicyRed : AppColors.border,
              width: selected ? 1.5 : 1,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                selected
                    ? Icons.check_circle_rounded
                    : Icons.add_circle_outline_rounded,
                size: 14,
                color: selected ? AppColors.spicyRed : AppColors.textMuted,
              ),
              const SizedBox(width: 6),
              Text(
                topping.name,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: selected
                      ? AppColors.spicyRedDark
                      : AppColors.textPrimary,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                '+ ${MoneyVnd.format(topping.price)}',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: selected ? AppColors.spicyRed : AppColors.textMuted,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
