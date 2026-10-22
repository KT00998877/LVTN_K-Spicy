import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🔥 COMBO PROMO SECTION — RED BAND (home-ui §4)
/// ============================================================
/// Năng lượng cao, 1 CTA mạnh. Hình ảnh món to, đứng độc lập
/// (không bị nhét trong card trắng).
/// Lấy featured[1] (hoặc bất kỳ featured nào) làm "Combo đặc biệt".
/// ============================================================

class ComboPromoSection extends ConsumerWidget {
  const ComboPromoSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final featured = ref.watch(homeControllerProvider).featured;
    if (featured.isEmpty) return const SizedBox.shrink();
    final item = featured.length > 1 ? featured[1] : featured.first;

    return SectionFrame(
      tone: SectionTone.red,
      padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: const Text(
              'KHUYẾN MÃI HÔM NAY',
              style: TextStyle(
                color: AppColors.spicyRed,
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.2,
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            'Combo\ncực cay\ncực đã.',
            style: Theme.of(context).textTheme.displaySmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -1.4,
                  height: 1.0,
                ),
          ),
          const SizedBox(height: 16),
          // ── Promo food image (lớn, không có card trắng) ──
          Stack(
            alignment: Alignment.bottomCenter,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(AppRadius.lg),
                child: AspectRatio(
                  aspectRatio: 4 / 3,
                  child: BackendImage(
                    imageUrl: item.imageUrl,
                    fit: BoxFit.cover,
                    placeholder: (_, __) => Container(color: AppColors.spicyRedDark),
                    errorWidget: (_, __, ___) => Container(
                      color: AppColors.spicyRedDark,
                      alignment: Alignment.center,
                      child: const Text('🍜',
                          style: TextStyle(fontSize: 80)),
                    ),
                  ),
                ),
              ),
              // Floating price chip
              Positioned(
                bottom: 12,
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    boxShadow: AppShadow.floating,
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        item.priceFormatted,
                        style: const TextStyle(
                          color: AppColors.spicyRed,
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                      const Text(
                        '  · chỉ hôm nay',
                        style: TextStyle(
                          color: AppColors.textSecondary,
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          Text(
            '${item.name} — ${item.categoryName}. Thưởng thức khi còn nóng hổi.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.92),
              fontSize: 15,
              height: 1.55,
            ),
          ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: () => context.push('/menu/${item.slug}'),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.white,
              foregroundColor: AppColors.spicyRed,
              minimumSize: const Size.fromHeight(52),
            ),
            child: const Text('Thử combo ngay'),
          ),
        ],
      ),
    );
  }
}
