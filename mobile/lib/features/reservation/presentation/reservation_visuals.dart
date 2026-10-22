import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../domain/reservation_models.dart';

/// ============================================================
/// 🏷 RESERVATION STATUS VISUAL — label + màu + icon
/// ============================================================

class ReservationStatusVisual {
  const ReservationStatusVisual({
    required this.label,
    required this.bg,
    required this.fg,
    required this.icon,
  });

  final String label;
  final Color bg;
  final Color fg;
  final IconData icon;

  static ReservationStatusVisual of(ReservationStatus s) {
    switch (s) {
      case ReservationStatus.pending:
        return const ReservationStatusVisual(
          label: 'Chờ xác nhận',
          bg: Color(0xFFFFF7E6),
          fg: AppColors.warning,
          icon: Icons.schedule_rounded,
        );
      case ReservationStatus.confirmed:
        return const ReservationStatusVisual(
          label: 'Đã xác nhận',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
          icon: Icons.check_circle_rounded,
        );
      case ReservationStatus.arrived:
        return const ReservationStatusVisual(
          label: 'Đã đến',
          bg: Color(0xFFE3F2FD),
          fg: AppColors.info,
          icon: Icons.directions_walk_rounded,
        );
      case ReservationStatus.cancelled:
        return const ReservationStatusVisual(
          label: 'Đã huỷ',
          bg: Color(0xFFFDEEEE),
          fg: AppColors.danger,
          icon: Icons.cancel_rounded,
        );
      case ReservationStatus.completed:
        return const ReservationStatusVisual(
          label: 'Hoàn tất',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
          icon: Icons.celebration_rounded,
        );
      case ReservationStatus.unknown:
        return const ReservationStatusVisual(
          label: '—',
          bg: AppColors.creamSoft,
          fg: AppColors.textMuted,
          icon: Icons.help_outline_rounded,
        );
    }
  }
}

class ReservationStatusPill extends StatelessWidget {
  const ReservationStatusPill({super.key, required this.status});
  final ReservationStatus status;

  @override
  Widget build(BuildContext context) {
    final v = ReservationStatusVisual.of(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: v.bg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: v.fg.withValues(alpha: 0.25)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(v.icon, size: 12, color: v.fg),
          const SizedBox(width: 4),
          Text(
            v.label,
            style: TextStyle(
              color: v.fg,
              fontSize: 11,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}
