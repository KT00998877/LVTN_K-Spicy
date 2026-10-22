import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 📞 RESERVATION CTA SECTION — RED BAND (home-ui §8)
/// ============================================================
/// Mobile hiện chưa có flow đặt bàn → CTA hiển thị placeholder
/// snackbar "Sắp ra mắt". Số điện thoại thật từ restaurant info
/// (không bịa) để user có thể gọi đặt bàn ngay.
/// ============================================================

class ReservationCtaSection extends ConsumerWidget {
  const ReservationCtaSection({super.key, required this.onReservationTap});

  final VoidCallback onReservationTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final phone = ref.watch(homeControllerProvider).restaurant?.phone ?? '';
    final hasPhone = phone.isNotEmpty;

    return SectionFrame(
      tone: SectionTone.red,
      padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 16),
      child: Column(
        children: [
          Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: AppColors.goldenYellow,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: const Text(
              'ĐẶT BÀN TRƯỚC',
              style: TextStyle(
                color: AppColors.charcoal,
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.2,
              ),
            ),
          ),
          const SizedBox(height: 14),
          Text(
            'Bàn chỉ dành cho người\nbiết đặt trước.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.displaySmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -1.2,
                  height: 1.05,
                ),
          ),
          const SizedBox(height: 14),
          Text(
            'Hẹn bạn và gia đình một tô mì cay nóng hổi — chúng tôi giữ chỗ, '
            'chuẩn bị bàn và sẵn sàng phục vụ.',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.92),
              fontSize: 15,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 20),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: onReservationTap,
              icon: const Icon(Icons.event_seat_rounded, size: 18),
              label: const Text('Đặt bàn ngay'),
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: AppColors.spicyRed,
                minimumSize: const Size.fromHeight(52),
              ),
            ),
          ),
          if (hasPhone) ...[
            const SizedBox(height: 12),
            Text(
              'Hoặc gọi đặt bàn: $phone',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.85),
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
          const SizedBox(height: 10),
          Text(
            'Đặt bàn trước ít nhất 1 giờ.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.7),
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}
