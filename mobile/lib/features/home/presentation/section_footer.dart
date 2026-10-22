import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import 'home_controller.dart';

/// ============================================================
/// 🦶 FOOTER — CREAM/charcoal mix (home-ui §9)
/// ============================================================
/// Thông tin liên hệ + social + chuyển tiếp tới social.
/// ============================================================

class FooterHomeSection extends ConsumerWidget {
  const FooterHomeSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final restaurant = ref.watch(homeControllerProvider).restaurant;

    return Container(
      color: AppColors.charcoal,
      padding: const EdgeInsets.fromLTRB(16, 32, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Brand
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: AppColors.spicyRed,
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                ),
                alignment: Alignment.center,
                child: const Text('🍜', style: TextStyle(fontSize: 18)),
              ),
              const SizedBox(width: 10),
              Text(
                restaurant?.name.split('-').first.trim() ?? 'Mì Cay',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 18,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          if (restaurant != null)
            Text(
              restaurant.slogan,
              style: TextStyle(
                color: AppColors.textOnDark.withValues(alpha: 0.85),
                fontSize: 14,
                height: 1.5,
              ),
            ),
          const SizedBox(height: 20),

          // Contact block
          if (restaurant != null) ...[
            _FooterRow(
              icon: Icons.place_rounded,
              text: restaurant.address,
            ),
            const SizedBox(height: 8),
            _FooterRow(
              icon: Icons.phone_rounded,
              text: restaurant.phone,
            ),
            const SizedBox(height: 8),
            _FooterRow(
              icon: Icons.access_time_rounded,
              text: 'Mở cửa: ${restaurant.openHours}',
            ),
          ],
          const SizedBox(height: 20),

          // Social
          Row(
            children: [
              if (restaurant?.social.facebook.isNotEmpty ?? false)
                _SocialChip(
                  icon: Icons.facebook_rounded,
                  url: restaurant!.social.facebook,
                ),
              if (restaurant?.social.instagram.isNotEmpty ?? false)
                _SocialChip(
                  icon: Icons.camera_alt_outlined,
                  url: restaurant!.social.instagram,
                ),
              if (restaurant?.social.tiktok.isNotEmpty ?? false)
                _SocialChip(
                  icon: Icons.music_note_rounded,
                  url: restaurant!.social.tiktok,
                ),
            ],
          ),
          const SizedBox(height: 24),
          const Divider(color: Color(0x33FFFFFF)),
          const SizedBox(height: 12),
          Text(
            '© 2026 ${restaurant?.name ?? 'Mì Cay Hàn Quốc'} · All rights reserved.',
            style: TextStyle(
              color: AppColors.textOnDark.withValues(alpha: 0.5),
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _FooterRow extends StatelessWidget {
  const _FooterRow({required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: AppColors.spicyRed, size: 18),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(
              color: AppColors.textOnDark,
              fontSize: 13,
              height: 1.5,
            ),
          ),
        ),
      ],
    );
  }
}

class _SocialChip extends StatelessWidget {
  const _SocialChip({required this.icon, required this.url});
  final IconData icon;
  final String url;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 10),
      child: Material(
        color: AppColors.charcoalSoft,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: () {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text('Mở: $url')),
            );
          },
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Icon(icon, color: Colors.white, size: 20),
          ),
        ),
      ),
    );
  }
}
