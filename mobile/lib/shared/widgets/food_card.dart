import 'backend_image.dart';
import 'package:flutter/material.dart';

import '../../app/theme.dart';
import '../../core/utils/money_vnd.dart';
import '../../features/menu/domain/menu_models.dart';

/// ============================================================
/// 🖼 FOOD CARD — card món ăn theo rule restaurant-ui.mdc
/// ============================================================
/// - Ảnh chiếm phần lớn card (≈ 60%)
/// - Không stack quá nhiều badge
/// - Primary action (Thêm vào giỏ) nổi bật
/// - Không bo rounded-3xl, dùng radius lg (16)
/// ============================================================

class FoodCard extends StatelessWidget {
  const FoodCard({
    super.key,
    required this.item,
    this.onTap,
    this.onAddToCart,
  });

  final MenuItem item;
  final VoidCallback? onTap;
  final VoidCallback? onAddToCart;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          boxShadow: AppShadow.card,
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── Ảnh ──────────────────────────────────────
            AspectRatio(
              aspectRatio: 4 / 3,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  BackendImage(
                    imageUrl: item.imageUrl,
                    fit: BoxFit.cover,
                    placeholder: (_, __) => Container(
                      color: AppColors.creamSoft,
                      alignment: Alignment.center,
                      child: const Icon(
                        Icons.restaurant_rounded,
                        color: AppColors.textMuted,
                        size: 40,
                      ),
                    ),
                    errorWidget: (_, __, ___) => Container(
                      color: AppColors.creamSoft,
                      alignment: Alignment.center,
                      child: const Icon(
                        Icons.broken_image_outlined,
                        color: AppColors.textMuted,
                      ),
                    ),
                  ),
                  if (item.spicyLevel > 0)
                    Positioned(
                      top: AppSpacing.xs,
                      left: AppSpacing.xs,
                      child: _SpicyBadge(level: item.spicyLevel),
                    ),
                ],
              ),
            ),

            // ── Thông tin ────────────────────────────────
            Padding(
              padding: const EdgeInsets.all(AppSpacing.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  if (item.categoryName != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      item.categoryName!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                  const SizedBox(height: AppSpacing.xs),
                  Row(
                    children: [
                      Expanded(
                        child: PriceDisplay(
                          amount: item.price,
                          size: PriceSize.medium,
                        ),
                      ),
                      if (onAddToCart != null)
                        _AddButton(onPressed: onAddToCart!),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SpicyBadge extends StatelessWidget {
  const _SpicyBadge({required this.level});
  final int level;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.xs,
        vertical: AppSpacing.xxs,
      ),
      decoration: BoxDecoration(
        color: AppColors.spicyRed,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.local_fire_department_rounded,
              color: Colors.white, size: 14),
          const SizedBox(width: 2),
          Text(
            'Cấp $level',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _AddButton extends StatelessWidget {
  const _AddButton({required this.onPressed});
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.spicyRed,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        onTap: onPressed,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: const Padding(
          padding: EdgeInsets.all(AppSpacing.xs),
          child: Icon(Icons.add_rounded, color: Colors.white, size: 20),
        ),
      ),
    );
  }
}

/// ============================================================
/// 💰 PRICE DISPLAY — định dạng giá thống nhất
/// ============================================================
enum PriceSize { small, medium, large }

class PriceDisplay extends StatelessWidget {
  const PriceDisplay({
    super.key,
    required this.amount,
    this.size = PriceSize.medium,
    this.color,
  });

  final double amount;
  final PriceSize size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final style = switch (size) {
      PriceSize.small => Theme.of(context).textTheme.titleSmall?.copyWith(
            color: color ?? AppColors.spicyRed,
            fontWeight: FontWeight.w700,
          ),
      PriceSize.medium => Theme.of(context).textTheme.titleMedium?.copyWith(
            color: color ?? AppColors.spicyRed,
            fontWeight: FontWeight.w800,
          ),
      PriceSize.large => Theme.of(context).textTheme.headlineSmall?.copyWith(
            color: color ?? AppColors.spicyRed,
            fontWeight: FontWeight.w800,
          ),
    };
    return Text(MoneyVnd.format(amount), style: style);
  }
}
