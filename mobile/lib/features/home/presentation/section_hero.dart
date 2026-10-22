import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../domain/home_models.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🦸 HERO SECTION — CREAM + RED HERO (home-ui §1)
/// ============================================================
/// - Bold oversized headline với accent đỏ
/// - Trust strip nhỏ gọn
/// - 2 CTA: "Khám phá thực đơn" (primary) + "Đặt bàn" (ghost)
/// - Ảnh món ăn nổi bật + giá "Từ X" floating
/// ============================================================

class HeroSection extends ConsumerWidget {
  const HeroSection({super.key, required this.onReservationTap, required this.onOpenMenu});

  /// Callback khi user bấm "Đặt bàn" (placeholder sẽ show snackbar).
  final VoidCallback onReservationTap;

  /// Mở tab Menu kèm filter (chưa có filter trong Hero).
  final VoidCallback onOpenMenu;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(homeControllerProvider);

    return SectionFrame(
      tone: SectionTone.cream,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      child: Column(
        children: [
          const SizedBox(height: 8),
          // Eyebrow
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              'K-SPICY · MÌ CAY CHUẨN VỊ HÀN',
              style: TextStyle(
                color: AppColors.spicyRed,
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.4,
              ),
            ),
          ),
          const SizedBox(height: 12),

          // ── HEADLINE ───────────────────────────────────────
          Align(
            alignment: Alignment.centerLeft,
            child: RichText(
              text: TextSpan(
                style: Theme.of(context).textTheme.displaySmall?.copyWith(
                      color: AppColors.textPrimary,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -1.2,
                      height: 1.05,
                    ),
                children: const [
                  TextSpan(text: 'Đánh thức\n'),
                  TextSpan(text: 'vị giác', style: TextStyle(color: AppColors.spicyRed)),
                  TextSpan(text: ' của bạn.'),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),

          // ── SUBTITLE ───────────────────────────────────────
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              'Nước dùng đậm đà nấu 12 tiếng, sợi mì dai ngon, '
              'topping tươi mỗi ngày. Tự chọn cấp độ cay — '
              'từ nhẹ nhàng đến cực hạn.',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                    height: 1.55,
                  ),
            ),
          ),
          const SizedBox(height: 20),

          // ── CTAs ───────────────────────────────────────────
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: onOpenMenu,
                  icon: const Icon(Icons.restaurant_menu_rounded, size: 18),
                  label: const Text('Khám phá thực đơn'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onReservationTap,
                  icon: const Icon(Icons.event_seat_rounded, size: 18),
                  label: const Text('Đặt bàn'),
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
          const SizedBox(height: 20),

          // ── TRUST STRIP ────────────────────────────────────
          if (state.stats != null) _TrustStrip(stats: state.stats!),

          const SizedBox(height: 24),

          // ── HERO FOOD VISUAL ───────────────────────────────
          _HeroVisual(
            imageUrl: '/storage/brand/hero.jpg',
            fallbackName:
                state.restaurant?.name.split('-').first.trim() ?? 'Mì Cay',
            minPriceLabel: _minPriceLabel(state.featured),
          ),
        ],
      ),
    );
  }

  String? _minPriceLabel(List<HomeMenuItem> items) {
    if (items.isEmpty) return null;
    final min = items
        .map((e) => e.price)
        .reduce((a, b) => a < b ? a : b);
    final formatted = items
        .firstWhere(
          (e) => e.price == min,
          orElse: () => items.first,
        )
        .priceFormatted;
    return formatted;
  }
}

class _TrustStrip extends StatelessWidget {
  const _TrustStrip({required this.stats});
  final HomeStats stats;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: AppColors.spicyRedSoft,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(
            color: AppColors.spicyRed.withValues(alpha: 0.15)),
      ),
      child: Row(
        children: [
          _TrustCell(value: '${stats.spicyLevels}', label: 'Cấp độ cay'),
          _TrustDivider(),
          _TrustCell(
            value: '${(stats.happyCustomers / 1000).toStringAsFixed(1)}K+',
            label: 'Thực khách',
          ),
          _TrustDivider(),
          _TrustCell(
            value: '${stats.menuItems}+',
            label: 'Món đặc trưng',
          ),
        ],
      ),
    );
  }
}

class _TrustCell extends StatelessWidget {
  const _TrustCell({required this.value, required this.label});
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        children: [
          Text(
            value,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  letterSpacing: -0.5,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
          ),
          const SizedBox(height: 2),
          Text(
            label.toUpperCase(),
            style: const TextStyle(
              fontSize: 10,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.2,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

class _TrustDivider extends StatelessWidget {
  @override
  Widget build(BuildContext context) =>
      Container(width: 1, height: 28, color: AppColors.spicyRed.withValues(alpha: 0.2));
}

class _HeroVisual extends StatelessWidget {
  const _HeroVisual({
    required this.imageUrl,
    required this.fallbackName,
    required this.minPriceLabel,
  });

  final String? imageUrl;
  final String fallbackName;
  final String? minPriceLabel;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(AppRadius.xl),
          child: AspectRatio(
            aspectRatio: 4 / 3,
            child: imageUrl != null && imageUrl!.isNotEmpty
                ? BackendImage(
                    imageUrl: imageUrl!,
                    fit: BoxFit.cover,
                    placeholder: (_, __) => Container(color: AppColors.creamSoft),
                    errorWidget: (_, __, ___) => _HeroFallback(name: fallbackName),
                  )
                : _HeroFallback(name: fallbackName),
          ),
        ),
        // Editorial caption top-left
        Positioned(
          top: 12,
          left: 12,
          child: Container(
            padding: const EdgeInsets.fromLTRB(8, 6, 12, 6),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(AppRadius.pill),
              boxShadow: AppShadow.card,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  decoration: BoxDecoration(
                    color: AppColors.spicyRed,
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                  child: const Text(
                    'N°01',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                const Text(
                  'Không gian Mì Cay',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
        // Floating price tag bottom-right
        if (minPriceLabel != null)
          Positioned(
            bottom: 12,
            right: 12,
            child: Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.spicyRed,
                borderRadius: BorderRadius.circular(AppRadius.pill),
                boxShadow: AppShadow.floating,
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'Từ ',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 0.4,
                    ),
                  ),
                  Text(
                    minPriceLabel!,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _HeroFallback extends StatelessWidget {
  const _HeroFallback({required this.name});
  final String name;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.creamSoft, AppColors.creamDark],
        ),
      ),
      alignment: Alignment.center,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Text('🍜', style: TextStyle(fontSize: 64)),
          const SizedBox(height: 8),
          Text(
            name.toUpperCase(),
            style: const TextStyle(
              color: AppColors.spicyRed,
              fontWeight: FontWeight.w900,
              fontSize: 20,
              letterSpacing: -0.5,
            ),
          ),
        ],
      ),
    );
  }
}
