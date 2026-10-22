import '../../../core/api/api_exception.dart';
import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../../shared/widgets/food_card.dart';
import '../../cart/presentation/cart_controller.dart';
import '../data/menu_repository.dart';
import '../domain/menu_models.dart';

/// ============================================================
/// 🍜 MENU DETAIL — chi tiết 1 món
/// ============================================================
/// Có thể nhận [MenuItem] qua constructor (khi navigate từ list)
/// hoặc tự fetch theo slug nếu cần.
/// ============================================================

class MenuDetailScreen extends ConsumerStatefulWidget {
  const MenuDetailScreen({super.key, required this.item});

  final MenuItem item;

  @override
  ConsumerState<MenuDetailScreen> createState() => _MenuDetailScreenState();
}

class _MenuDetailScreenState extends ConsumerState<MenuDetailScreen> {
  final _note = TextEditingController();
  int _qty = 1;
  int _spicyLevel = 0;
  final Set<int> _selectedToppings = <int>{};
  List<Topping> _toppings = [];
  bool _isLoadingToppings = true;
  bool _isAdding = false;

  @override
  void initState() {
    super.initState();
    _spicyLevel = widget.item.spicyLevel;
    _loadToppings();
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadToppings() async {
    try {
      final list = await ref.read(menuRepositoryProvider).getToppings();
      if (!mounted) return;
      setState(() {
        _toppings = list;
        _isLoadingToppings = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _isLoadingToppings = false);
    }
  }

  double get _toppingPrice => _toppings
      .where((t) => _selectedToppings.contains(t.id))
      .fold<double>(0, (s, t) => s + t.price);

  double get _lineTotal => (widget.item.price + _toppingPrice) * _qty;

  Future<void> _addToCart() async {
    if (_isAdding || !widget.item.isAvailable) return;
    setState(() => _isAdding = true);
    final ok = await ref
        .read(cartControllerProvider.notifier)
        .addItem(
          menuItemId: widget.item.id,
          quantity: _qty,
          spicyLevel: _spicyLevel,
          toppingIds: _selectedToppings.toList(),
          note: _note.text.trim(),
        );
    if (!mounted) return;
    setState(() => _isAdding = false);
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Đã thêm "${widget.item.name}" vào giỏ'),
          backgroundColor: AppColors.spicyRed,
        ),
      );
      Navigator.of(context).pop();
    } else {
      final err = ref.read(cartControllerProvider).errorMessage ?? 'Có lỗi';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            expandedHeight: 280,
            pinned: true,
            backgroundColor: AppColors.cream,
            foregroundColor: AppColors.textPrimary,
            flexibleSpace: FlexibleSpaceBar(
              background: BackendImage(
                imageUrl: item.imageUrl,
                fit: BoxFit.cover,
                placeholder: (_, __) => Container(color: AppColors.creamSoft),
                errorWidget: (_, __, ___) =>
                    Container(color: AppColors.creamSoft),
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          item.name,
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                      ),
                      PriceDisplay(amount: item.price, size: PriceSize.large),
                    ],
                  ),
                  if (item.categoryName != null) ...[
                    const SizedBox(height: AppSpacing.xxs),
                    Row(
                      children: [
                        Text(
                          item.categoryName!,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        if (item.preparationMinutes != null) ...[
                          const Text(' · '),
                          Text(
                            '${item.preparationMinutes} phút',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ],
                    ),
                  ],
                  if (item.description != null) ...[
                    const SizedBox(height: AppSpacing.md),
                    Text(
                      item.description!,
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  TextFormField(
                    controller: _note,
                    maxLength: 200,
                    decoration: const InputDecoration(
                      labelText: 'Ghi chú món (tùy chọn)',
                    ),
                  ),
                  Text(
                    'Cấp độ cay',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  SpicySelector(
                    value: _spicyLevel,
                    onChanged: (v) => setState(() => _spicyLevel = v),
                    maxLevel: 7,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text(
                    'Topping thêm',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  if (_isLoadingToppings)
                    const Padding(
                      padding: EdgeInsets.all(AppSpacing.md),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (_toppings.isEmpty)
                    Text(
                      'Không có topping',
                      style: Theme.of(context).textTheme.bodySmall,
                    )
                  else
                    Wrap(
                      spacing: AppSpacing.xs,
                      runSpacing: AppSpacing.xs,
                      children: _toppings.map((t) {
                        final selected = _selectedToppings.contains(t.id);
                        return FilterChip(
                          label: Text(
                            '${t.name} · ${t.price.toStringAsFixed(0)}đ',
                          ),
                          selected: selected,
                          onSelected: (s) => setState(() {
                            if (s) {
                              _selectedToppings.add(t.id);
                            } else {
                              _selectedToppings.remove(t.id);
                            }
                          }),
                          selectedColor: AppColors.spicyRedSoft,
                          checkmarkColor: AppColors.spicyRed,
                        );
                      }).toList(),
                    ),
                  const SizedBox(height: AppSpacing.xxl),
                ],
              ),
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
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
              QuantityStepper(
                value: _qty,
                max: 99,
                onChanged: (v) => setState(() => _qty = v),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: ElevatedButton.icon(
                  onPressed:
                      _isAdding ||
                          !item.isAvailable ||
                          !ref.watch(cartControllerProvider).canMutate
                      ? null
                      : _addToCart,
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
                    'Thêm · ${_lineTotal.toStringAsFixed(0).replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]}.')} đ',
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

final menuDetailProvider = FutureProvider.autoDispose.family<MenuItem, String>(
  (ref, slug) => ref.watch(menuRepositoryProvider).getBySlug(slug),
);

class MenuDetailLoader extends ConsumerWidget {
  const MenuDetailLoader({super.key, required this.slug});
  final String slug;
  @override
  Widget build(BuildContext context, WidgetRef ref) => ref
      .watch(menuDetailProvider(slug))
      .when(
        data: (item) => MenuDetailScreen(item: item),
        loading: () => Scaffold(
          appBar: AppBar(),
          body: const Center(child: CircularProgressIndicator()),
        ),
        error: (error, _) => Scaffold(
          appBar: AppBar(),
          body: EmptyState(
            icon: '!',
            title: 'Không tải được món',
            subtitle: error is ApiException
                ? error.message
                : 'Vui lòng thử lại.',
            actionLabel: 'Thử lại',
            onAction: () => ref.invalidate(menuDetailProvider(slug)),
          ),
        ),
      );
}
