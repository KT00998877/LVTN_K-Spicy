import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../domain/home_models.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🍽 MENU DISCOVERY SECTION — WHITE band (home-ui §6)
/// ============================================================
/// Horizontal scroll-snap các món featured. Card nhỏ gọn,
/// CTA "Xem toàn bộ thực đơn" dưới cùng.
/// ============================================================

class MenuDiscoverySection extends ConsumerWidget {
  const MenuDiscoverySection({super.key, required this.onOpenMenu});

  final VoidCallback onOpenMenu;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(homeControllerProvider).featured;

    return SectionFrame(
      tone: SectionTone.white,
      padding: const EdgeInsets.symmetric(vertical: 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHead(
            eyebrow: 'Khám phá thêm',
            title: 'Nhiều món hơn đang chờ bạn',
            subtitle: 'Lướt qua các món được yêu thích gần đây.',
          ),
          const SizedBox(height: 16),
          SizedBox(
            height: 248,
            child: items.isEmpty
                ? const _EmptyDiscovery()
                : ListView.separated(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    itemBuilder: (_, i) => _DiscoveryCard(item: items[i]),
                    separatorBuilder: (_, __) =>
                        const SizedBox(width: 12),
                    itemCount: items.length,
                  ),
          ),
          const SizedBox(height: 20),
          Center(
            child: OutlinedButton.icon(
              onPressed: onOpenMenu,
              icon: const Icon(Icons.menu_book_rounded, size: 18),
              label: const Text('Xem toàn bộ thực đơn'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.spicyRed,
                side: const BorderSide(
                    color: AppColors.spicyRed, width: 1.5),
                minimumSize: const Size.fromHeight(52),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DiscoveryCard extends StatelessWidget {
  const _DiscoveryCard({required this.item});
  final HomeMenuItem item;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 200,
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          onTap: () => context.push('/menu/${item.slug}'),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.lg),
              boxShadow: AppShadow.card,
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                AspectRatio(
                  aspectRatio: 4 / 3,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      BackendImage(
                        imageUrl: item.imageUrl,
                        fit: BoxFit.cover,
                        placeholder: (_, __) =>
                            Container(color: AppColors.creamSoft),
                        errorWidget: (_, __, ___) =>
                            Container(color: AppColors.creamSoft),
                      ),
                      if (item.spicyLevel > 0)
                        Positioned(
                          top: 8,
                          left: 8,
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: AppColors.spicyRed,
                              borderRadius:
                                  BorderRadius.circular(AppRadius.pill),
                            ),
                            child: Text(
                              'Cấp ${item.spicyLevel}',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        item.categoryName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.textMuted,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        item.priceFormatted,
                        style: const TextStyle(
                          color: AppColors.spicyRed,
                          fontWeight: FontWeight.w800,
                          fontSize: 14,
                        ),
                      ),
                    ],
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

class _EmptyDiscovery extends StatelessWidget {
  const _EmptyDiscovery();
  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Text(
        'Chưa có món để gợi ý hôm nay.',
        style: TextStyle(color: AppColors.textSecondary),
      ),
    );
  }
}
