import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:micay_app/app/theme.dart';
import 'package:micay_app/features/home/presentation/home_controller.dart';
import 'package:micay_app/features/menu/data/menu_repository.dart';
import 'package:micay_app/features/menu/domain/menu_category.dart';
import 'package:micay_app/features/menu/domain/menu_models.dart';
import 'package:micay_app/features/menu/presentation/quick_add_sheet.dart';
import 'package:micay_app/shared/widgets/common_widgets.dart';
import 'package:micay_app/shared/widgets/food_card.dart';

/// ============================================================
/// 🍜 MENU SCREEN — danh sách món có filter category + search
/// ============================================================
/// v2:
///   - Category lấy động từ /api/home/categories (re-use HomeState cache)
///   - Search debounce 300ms (gõ phát là tự load, không cần Enter)
///   - Fallback: nếu Home chưa load thì gọi trực tiếp repo
/// ============================================================

class MenuScreen extends ConsumerStatefulWidget {
  const MenuScreen({
    super.key,
    this.initialCategorySlug,
    this.initialSpicyLevel,
  });

  final String? initialCategorySlug;
  final int? initialSpicyLevel;

  @override
  ConsumerState<MenuScreen> createState() => _MenuScreenState();
}

class _MenuScreenState extends ConsumerState<MenuScreen> {
  final _searchCtrl = TextEditingController();
  String? _categorySlug; // null = tất cả
  int? _spicyLevel;
  List<MenuItem> _items = [];
  bool _isLoading = true;
  String? _errorMessage;

  /// Debounce 300ms cho search realtime.
  Timer? _debounce;

  /// "Tất cả" + danh sách category từ API.
  List<MenuCategory> _categories = const [];

  @override
  void initState() {
    super.initState();
    _categorySlug = widget.initialCategorySlug;
    _spicyLevel = widget.initialSpicyLevel;

    // Lắng nghe thay đổi search ngay khi gõ
    _searchCtrl.addListener(_onSearchChanged);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _loadCategories();
      _fetch();
    });
  }

  void _onSearchChanged() {
    // Cancel timer cũ
    _debounce?.cancel();
    // Schedule lại sau 300ms
    _debounce = Timer(const Duration(milliseconds: 300), () {
      if (!mounted) return;
      _fetch();
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchCtrl.removeListener(_onSearchChanged);
    _searchCtrl.dispose();
    super.dispose();
  }

  /// Lấy categories từ HomeState (cache 60s).
  /// Nếu Home chưa load thì trigger load Home rồi mới lấy.
  Future<void> _loadCategories() async {
    final homeState = ref.read(homeControllerProvider);
    if (homeState.categories.isEmpty) {
      // Trigger load Home (cache-friendly)
      await ref.read(homeControllerProvider.notifier).load();
    }
    if (!mounted) return;
    final cats = ref.read(homeControllerProvider).categories;
    setState(() {
      _categories = cats
          .map(
            (hc) => MenuCategory(
              id: hc.id,
              name: hc.name,
              slug: hc.slug,
              description: hc.description,
              itemsCount: hc.itemsCount,
            ),
          )
          .toList();
    });
  }

  Future<void> _fetch() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final items = await ref
          .read(menuRepositoryProvider)
          .list(
            categorySlug: _categorySlug,
            search: _searchCtrl.text.trim(),
            spicyLevel: _spicyLevel,
          );
      if (!mounted) return;
      setState(() {
        _items = items;
        _isLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _errorMessage = e.toString().replaceFirst('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  void _goToDetail(MenuItem item) {
    context.push('/menu/${item.slug}', extra: item);
  }

  void _quickAdd(MenuItem item) {
    QuickAddSheet.show(context, item);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // ── Thanh search ────────────────────────────────
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.xs,
            AppSpacing.md,
            AppSpacing.sm,
          ),
          child: TextField(
            controller: _searchCtrl,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: 'Tìm món...',
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: ValueListenableBuilder<TextEditingValue>(
                valueListenable: _searchCtrl,
                builder: (_, value, __) {
                  if (value.text.isEmpty) return const SizedBox.shrink();
                  return IconButton(
                    icon: const Icon(Icons.close_rounded),
                    tooltip: 'Xoá',
                    onPressed: () {
                      _searchCtrl.clear();
                      _fetch();
                    },
                  );
                },
              ),
              contentPadding: const EdgeInsets.symmetric(
                vertical: 0,
                horizontal: AppSpacing.md,
              ),
            ),
          ),
        ),

        // ── Category tabs ──────────────────────────────
        SizedBox(height: 44, child: _buildCategoryTabs()),
        const SizedBox(height: AppSpacing.xs),

        // ── Danh sách món ──────────────────────────────
        Expanded(child: _buildList()),
      ],
    );
  }

  Widget _buildCategoryTabs() {
    return ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
      itemCount: _categories.length + 1,
      separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.xs),
      itemBuilder: (_, i) {
        if (i == 0) {
          return _CategoryChip(
            label: 'Tất cả',
            emoji: '🍽',
            selected: _categorySlug == null,
            onTap: () {
              setState(() => _categorySlug = null);
              _fetch();
            },
          );
        }
        final c = _categories[i - 1];
        return _CategoryChip(
          label: c.name,
          emoji: c.emojiFor(),
          selected: _categorySlug == c.slug,
          onTap: () {
            setState(() => _categorySlug = c.slug);
            _fetch();
          },
        );
      },
    );
  }

  Widget _buildList() {
    if (_isLoading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_errorMessage != null) {
      return EmptyState(
        icon: '😢',
        title: 'Đã có lỗi',
        subtitle: _errorMessage,
        actionLabel: 'Thử lại',
        onAction: _fetch,
      );
    }
    if (_items.isEmpty) {
      return const EmptyState(
        icon: '🍜',
        title: 'Không tìm thấy món nào',
        subtitle: 'Thử đổi danh mục hoặc từ khoá khác',
      );
    }
    return RefreshIndicator(
      onRefresh: _fetch,
      child: GridView.builder(
        padding: const EdgeInsets.all(AppSpacing.md),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: AppSpacing.md,
          crossAxisSpacing: AppSpacing.md,
          childAspectRatio: 0.68,
        ),
        itemCount: _items.length,
        itemBuilder: (_, i) {
          final item = _items[i];
          return FoodCard(
            item: item,
            onTap: () => _goToDetail(item),
            onAddToCart: () => _quickAdd(item),
          );
        },
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({
    required this.label,
    required this.emoji,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final String emoji;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: 0,
        ),
        decoration: BoxDecoration(
          color: selected ? AppColors.spicyRed : Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.pill),
          border: Border.all(
            color: selected ? AppColors.spicyRed : AppColors.border,
          ),
        ),
        alignment: Alignment.center,
        child: Row(
          children: [
            Text(emoji, style: const TextStyle(fontSize: 16)),
            const SizedBox(width: 4),
            Text(
              label,
              style: TextStyle(
                color: selected ? Colors.white : AppColors.textPrimary,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
