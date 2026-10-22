import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../domain/home_models.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🏆 BEST SELLERS SECTION — CHARCOAL dark band (home-ui §3)
/// ============================================================
/// 1 featured dish lớn + 2 supporting dishes nhỏ.
/// Layout asymmetric, tiết kiệm scroll.
/// ============================================================

class BestSellersSection extends ConsumerWidget {
  const BestSellersSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(homeControllerProvider).bestSellers;

    return SectionFrame(
      tone: SectionTone.charcoal,
      padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHead(
            eyebrow: 'Bán chạy nhất',
            title: 'Được gọi nhiều nhất',
            subtitle:
                'Khách hàng đã chọn những món này nhiều nhất trong thời gian qua.',
            onDark: true,
          ),
          const SizedBox(height: 20),
          if (items.isEmpty)
            _Empty(onDark: true)
          else ...[
            _FeaturedHero(item: items.first),
            const SizedBox(height: 16),
            for (int i = 1; i < items.length && i < 3; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _SupportRow(rank: i + 1, item: items[i]),
              ),
          ],
        ],
      ),
    );
  }
}

class _FeaturedHero extends StatelessWidget {
  const _FeaturedHero({required this.item});
  final HomeMenuItem item;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Material(
        color: AppColors.charcoalSoft,
        child: InkWell(
          onTap: () => context.push('/menu/${item.slug}'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              AspectRatio(
                aspectRatio: 16 / 10,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    BackendImage(
                      imageUrl: item.imageUrl,
                      fit: BoxFit.cover,
                      placeholder: (_, __) =>
                          Container(color: AppColors.charcoalSoft),
                      errorWidget: (_, __, ___) =>
                          Container(color: AppColors.charcoalSoft),
                    ),
                    Positioned(
                      top: 12,
                      left: 12,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 6,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.spicyRed,
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: const Text(
                          '#1 BÁN CHẠY',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 11,
                            fontWeight: FontWeight.w800,
                            letterSpacing: 1,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.categoryName.toUpperCase(),
                      style: const TextStyle(
                        color: AppColors.spicyRed,
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 1.2,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      item.name,
                      style: Theme.of(context).textTheme.headlineSmall
                          ?.copyWith(
                            color: AppColors.textOnDark,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    if (item.description != null &&
                        item.description!.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        item.description!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Color(0xFFB5B5B5),
                          fontSize: 14,
                          height: 1.45,
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Text(
                          item.priceFormatted,
                          style: const TextStyle(
                            color: AppColors.spicyRed,
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            letterSpacing: -0.3,
                          ),
                        ),
                        const Spacer(),
                        if (item.soldCount != null)
                          Text(
                            'Đã bán ${item.soldCount}+',
                            style: const TextStyle(
                              color: AppColors.goldenYellow,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SupportRow extends StatelessWidget {
  const _SupportRow({required this.rank, required this.item});
  final int rank;
  final HomeMenuItem item;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.charcoalSoft,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        onTap: () => context.push('/menu/${item.slug}'),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              SizedBox(
                width: 84,
                height: 84,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  child: BackendImage(
                    imageUrl: item.imageUrl,
                    fit: BoxFit.cover,
                    placeholder: (_, __) =>
                        Container(color: AppColors.charcoal),
                    errorWidget: (_, __, ___) =>
                        Container(color: AppColors.charcoal),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '#$rank · ${item.categoryName}',
                      style: const TextStyle(
                        color: AppColors.spicyRed,
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.8,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      item.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: AppColors.textOnDark,
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      MoneyVnd.format(item.price),
                      style: const TextStyle(
                        color: AppColors.textOnDark,
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
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty({required this.onDark});
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Text(
        'Chưa có dữ liệu bán hàng.',
        style: TextStyle(
          color: onDark
              ? AppColors.textOnDark.withValues(alpha: 0.7)
              : AppColors.textSecondary,
        ),
      ),
    );
  }
}
