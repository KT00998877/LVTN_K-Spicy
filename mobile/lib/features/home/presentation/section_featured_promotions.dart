import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter/services.dart';

import '../../../app/theme.dart';
import '../../../core/api/dio_client.dart';
import '../../../shared/widgets/backend_image.dart';

class FeaturedPromotion {
  const FeaturedPromotion({
    required this.id,
    required this.name,
    required this.message,
    required this.code,
    this.imageUrl,
    this.discountLabel,
    this.ctaText,
  });

  final int id;
  final String name;
  final String message;
  final String code;
  final String? imageUrl;
  final String? discountLabel;
  final String? ctaText;

  factory FeaturedPromotion.fromJson(Map<String, dynamic> json) =>
      FeaturedPromotion(
        id: (json['id'] as num).toInt(),
        name: (json['name'] ?? '').toString(),
        message: (json['message'] ?? '').toString(),
        code: (json['voucher_code'] ?? '').toString(),
        imageUrl: json['banner_image_url']?.toString(),
        discountLabel: json['discount_label']?.toString(),
        ctaText: json['cta_text']?.toString(),
      );
}

final featuredPromotionsProvider = FutureProvider<List<FeaturedPromotion>>((ref) async {
  final response = await ref.read(dioProvider).publicGet<Map<String, dynamic>>(
    '/promotions/featured',
  );
  final data = response.data?['data'] as List? ?? const [];
  return data
      .map((item) => FeaturedPromotion.fromJson(Map<String, dynamic>.from(item as Map)))
      .toList();
});

/// Mobile counterpart of the web home page's PromoBanner.
class FeaturedPromotionsSection extends ConsumerStatefulWidget {
  const FeaturedPromotionsSection({super.key});

  @override
  ConsumerState<FeaturedPromotionsSection> createState() =>
      _FeaturedPromotionsSectionState();
}

class _FeaturedPromotionsSectionState
    extends ConsumerState<FeaturedPromotionsSection> {
  int? _claimingId;
  final Set<int> _claimedIds = {};

  Future<void> _claim(FeaturedPromotion promotion) async {
    if (_claimingId != null || _claimedIds.contains(promotion.id)) return;
    setState(() => _claimingId = promotion.id);
    try {
      final response = await ref.read(dioProvider).post<Map<String, dynamic>>(
        '/promotions/${promotion.id}/claim',
      );
      final data = response.data?['data'] as Map?;
      final code = data?['voucher_code']?.toString() ?? promotion.code;
      await Clipboard.setData(ClipboardData(text: code));
      if (!mounted) return;
      setState(() => _claimedIds.add(promotion.id));
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Đã nhận và sao chép mã $code')),
      );
    } on DioException catch (error) {
      if (!mounted) return;
      final data = error.response?.data;
      final message = data is Map && data['message'] != null
          ? data['message'].toString()
          : 'Không thể nhận ưu đãi. Vui lòng thử lại.';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message)),
      );
    } finally {
      if (mounted) setState(() => _claimingId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final promotions = ref.watch(featuredPromotionsProvider);
    final items = promotions.valueOrNull;
    if (items == null || items.isEmpty) return const SizedBox.shrink();

    return ColoredBox(
      color: AppColors.cream,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'ƯU ĐÃI ĐẶC BIỆT',
              style: Theme.of(context).textTheme.labelLarge?.copyWith(
                color: AppColors.spicyRed,
                letterSpacing: 1.1,
              ),
            ),
            const SizedBox(height: 12),
            for (final promotion in items) ...[
              _PromotionCard(
                promotion: promotion,
                isClaiming: _claimingId == promotion.id,
                isClaimed: _claimedIds.contains(promotion.id),
                onClaim: () => _claim(promotion),
              ),
              const SizedBox(height: 12),
            ],
          ],
        ),
      ),
    );
  }
}

class _PromotionCard extends StatelessWidget {
  const _PromotionCard({
    required this.promotion,
    required this.isClaiming,
    required this.isClaimed,
    required this.onClaim,
  });

  final FeaturedPromotion promotion;
  final bool isClaiming;
  final bool isClaimed;
  final VoidCallback onClaim;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: ColoredBox(
        color: AppColors.brandRed,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (promotion.imageUrl?.isNotEmpty == true)
              SizedBox(
                height: 132,
                child: BackendImage(
                  imageUrl: promotion.imageUrl!,
                  fit: BoxFit.cover,
                  placeholder: (_, __) => const ColoredBox(color: AppColors.brandRed),
                  errorWidget: (_, __, ___) => const ColoredBox(color: AppColors.brandRed),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (promotion.discountLabel?.isNotEmpty == true) ...[
                    Text(
                      promotion.discountLabel!,
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 8),
                  ],
                  Text(
                    promotion.name,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (promotion.message.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text(promotion.message, style: const TextStyle(color: Colors.white)),
                  ],
                  const SizedBox(height: 14),
                  if (promotion.code.isNotEmpty)
                    InkWell(
                      onTap: () async {
                        await Clipboard.setData(ClipboardData(text: promotion.code));
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Đã sao chép mã ưu đãi')),
                          );
                        }
                      },
                      child: Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.white, style: BorderStyle.solid),
                          borderRadius: BorderRadius.circular(AppRadius.sm),
                        ),
                        child: Text(
                          promotion.code,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
                        ),
                      ),
                    ),
                  const SizedBox(height: 14),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: isClaiming || isClaimed ? null : onClaim,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: AppColors.brandDark,
                      ),
                      child: Text(isClaiming
                          ? 'Đang nhận…'
                          : isClaimed
                              ? 'Đã nhận mã'
                              : promotion.ctaText?.isNotEmpty == true
                                  ? promotion.ctaText!
                                  : 'Nhận ưu đãi'),
                    ),
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
