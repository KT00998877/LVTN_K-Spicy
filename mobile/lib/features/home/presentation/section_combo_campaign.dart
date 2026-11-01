import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/dio_client.dart';
import '../../../shared/widgets/backend_image.dart';
import 'section_frame.dart';

final comboCampaignProvider = FutureProvider<Map<String, dynamic>?>((ref) async {
  final response = await ref.read(dioProvider).publicGet<Map<String, dynamic>>('/home');
  final data = response.data?['data'] as Map?;
  final combo = data?['combo_promotion'] as Map?;
  return combo == null ? null : Map<String, dynamic>.from(combo);
});

/// Mobile layout of the web home page's real combo campaign.
class ComboCampaignSection extends ConsumerWidget {
  const ComboCampaignSection({super.key, required this.onOpenMenu});

  final VoidCallback onOpenMenu;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final combo = ref.watch(comboCampaignProvider).valueOrNull;
    if (combo == null) return const SizedBox.shrink();
    final image = combo['image_url']?.toString();
    final drinkImage = combo['suggested_image_url']?.toString();
    final drinkName = combo['suggested_item_name']?.toString() ?? '';

    return SectionFrame(
      tone: SectionTone.red,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('COMBO MÓN VÀ NƯỚC', style: TextStyle(
            color: AppColors.goldenYellow, fontSize: 12,
            fontWeight: FontWeight.w800, letterSpacing: 1.1,
          )),
          const SizedBox(height: 10),
          Text(combo['title']?.toString() ?? '', style:
            Theme.of(context).textTheme.headlineMedium?.copyWith(
              color: Colors.white, fontWeight: FontWeight.w800,
            )),
          if ((combo['subtitle']?.toString() ?? '').isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(combo['subtitle'].toString(), style: const TextStyle(color: Colors.white, fontSize: 16)),
          ],
          if ((combo['description']?.toString() ?? '').isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(combo['description'].toString(), style: const TextStyle(color: Colors.white)),
          ],
          if (image?.isNotEmpty == true || drinkImage?.isNotEmpty == true) ...[
            const SizedBox(height: 18),
            Row(children: [
              if (image?.isNotEmpty == true) Expanded(
                flex: 3,
                child: _CampaignImage(imageUrl: image!, aspectRatio: 4 / 3),
              ),
              if (image?.isNotEmpty == true && drinkImage?.isNotEmpty == true)
                const SizedBox(width: 10),
              if (drinkImage?.isNotEmpty == true) Expanded(
                flex: 2,
                child: _CampaignImage(imageUrl: drinkImage!, aspectRatio: 1),
              ),
            ]),
          ],
          if (drinkName.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('Kèm $drinkName', style: const TextStyle(color: Colors.white)),
          ],
          const SizedBox(height: 18),
          SizedBox(width: double.infinity, child: ElevatedButton(
            onPressed: onOpenMenu,
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.white, foregroundColor: AppColors.brandDark,
            ),
            child: const Text('Xem combo trong thực đơn'),
          )),
        ],
      ),
    );
  }
}

class _CampaignImage extends StatelessWidget {
  const _CampaignImage({required this.imageUrl, required this.aspectRatio});

  final String imageUrl;
  final double aspectRatio;

  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(AppRadius.lg),
    child: AspectRatio(
      aspectRatio: aspectRatio,
      child: BackendImage(
        imageUrl: imageUrl,
        fit: BoxFit.cover,
        placeholder: (_, __) => const ColoredBox(color: AppColors.spicyRedDark),
        errorWidget: (_, __, ___) => const ColoredBox(color: AppColors.spicyRedDark),
      ),
    ),
  );
}
