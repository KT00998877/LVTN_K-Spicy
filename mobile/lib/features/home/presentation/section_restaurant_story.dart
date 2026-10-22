import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🏚 RESTAURANT STORY SECTION — CHARCOAL band (home-ui §7)
/// ============================================================
/// Editorial, immersive. Hình ảnh + text "Câu chuyện".
/// Sử dụng 3 món featured đầu tiên làm "gallery" ngang.
/// ============================================================

class RestaurantStorySection extends ConsumerWidget {
  const RestaurantStorySection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final restaurant = ref.watch(homeControllerProvider).restaurant;
    final featured = ref.watch(homeControllerProvider).featured;
    final stats = ref.watch(homeControllerProvider).stats;

    final galleryImages = featured.take(3).toList();

    return SectionFrame(
      tone: SectionTone.charcoal,
      padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHead(
            eyebrow: 'Câu chuyện nhà hàng',
            title: 'Hương vị chuẩn Hàn,\nnóng hổi mỗi ngày.',
            onDark: true,
          ),
          const SizedBox(height: 20),

          // ── 3-up gallery ───────────────────────────────────
          SizedBox(
            height: 140,
            child: Row(
              children: [
                for (int i = 0; i < galleryImages.length; i++) ...[
                  Expanded(
                    flex: i == 1 ? 4 : 3,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                      child: BackendImage(
                        imageUrl: galleryImages[i].imageUrl,
                        fit: BoxFit.cover,
                        placeholder: (_, __) =>
                            Container(color: AppColors.charcoalSoft),
                        errorWidget: (_, __, ___) =>
                            Container(color: AppColors.charcoalSoft),
                      ),
                    ),
                  ),
                  if (i < galleryImages.length - 1)
                    const SizedBox(width: 12),
                ],
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ── Description ────────────────────────────────────
          if (restaurant != null)
            Text(
              restaurant.description,
              style: TextStyle(
                color: AppColors.textOnDark.withValues(alpha: 0.85),
                fontSize: 15,
                height: 1.65,
              ),
            ),
          const SizedBox(height: 20),

          // ── Stats inline ───────────────────────────────────
          if (stats != null)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.charcoalSoft,
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
              child: Row(
                children: [
                  _StoryStat(value: '${stats.spicyLevels}', label: 'Cấp cay'),
                  _StoryDivider(),
                  _StoryStat(
                      value: '${stats.menuItems}+', label: 'Món'),
                  _StoryDivider(),
                  _StoryStat(
                      value: '${stats.tables}', label: 'Bàn'),
                ],
              ),
            ),
          const SizedBox(height: 16),

          // ── Address + open hours ──────────────────────────
          if (restaurant != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.place_rounded,
                        color: AppColors.spicyRed, size: 18),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        restaurant.address,
                        style: const TextStyle(
                          color: AppColors.textOnDark,
                          fontSize: 14,
                          height: 1.5,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    const Icon(Icons.access_time_rounded,
                        color: AppColors.spicyRed, size: 18),
                    const SizedBox(width: 8),
                    Text(
                      'Mở cửa: ${restaurant.openHours}',
                      style: const TextStyle(
                        color: AppColors.textOnDark,
                        fontSize: 14,
                      ),
                    ),
                  ],
                ),
              ],
            ),
        ],
      ),
    );
  }
}

class _StoryStat extends StatelessWidget {
  const _StoryStat({required this.value, required this.label});
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        children: [
          Text(
            value,
            style: const TextStyle(
              color: AppColors.spicyRed,
              fontSize: 22,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: TextStyle(
              color: AppColors.textOnDark.withValues(alpha: 0.7),
              fontSize: 11,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.8,
            ),
          ),
        ],
      ),
    );
  }
}

class _StoryDivider extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Container(
        width: 1,
        height: 36,
        color: AppColors.textOnDark.withValues(alpha: 0.15),
      );
}
