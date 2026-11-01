import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../domain/home_models.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🗂 CATEGORIES SECTION — WHITE STRIP (home-ui §2)
/// ============================================================
/// Horizontal scroll ngang (mobile) với snap, mỗi tile là
/// tên + số món. Tap → chuyển sang tab Menu với filter.
/// ============================================================

class CategoriesSection extends ConsumerWidget {
  const CategoriesSection({super.key, required this.onOpenMenu});

  final void Function(String categorySlug) onOpenMenu;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(homeControllerProvider);
    final cats = state.categories;

    return SectionFrame(
      tone: SectionTone.white,
      padding: const EdgeInsets.symmetric(vertical: 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHead(
            eyebrow: 'Danh mục',
            title: 'Bạn đang thèm món gì?',
            subtitle: 'Khám phá các món ăn đa dạng theo từng danh mục.',
          ),
          const SizedBox(height: 16),
          if (state.isLoading && cats.isEmpty)
            _LoadingSkeletonRow()
          else if (cats.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('Chưa có danh mục nào'),
            )
          else
            SizedBox(
              height: 100,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 4),
                itemBuilder: (_, i) {
                  final c = cats[i];
                  return _CategoryTile(
                    category: c,
                    onTap: () => onOpenMenu(c.slug),
                  );
                },
                separatorBuilder: (_, __) => const SizedBox(width: 12),
                itemCount: cats.length,
              ),
            ),
        ],
      ),
    );
  }
}

class _CategoryTile extends StatelessWidget {
  const _CategoryTile({required this.category, required this.onTap});
  final HomeCategory category;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 132,
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(
                horizontal: 12, vertical: 16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: AppColors.border, width: 1.5),
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  category.name,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    height: 1.25,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${category.itemsCount} món',
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textMuted,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _LoadingSkeletonRow extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 100,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: 5,
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (_, __) => const SkeletonBox(
          height: 100,
          width: 132,
          radius: 16,
        ),
      ),
    );
  }
}
