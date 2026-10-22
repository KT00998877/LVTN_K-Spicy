import 'package:flutter/material.dart';

import '../../app/theme.dart';

/// ============================================================
/// 🌶 SPICY SELECTOR — segmented control cho cấp độ cay
/// ============================================================
/// Theo rule restaurant-ui.mdc: KHÔNG dùng HTML select,
/// Segmented control.
/// ============================================================

class SpicySelector extends StatelessWidget {
  const SpicySelector({
    super.key,
    required this.value,
    required this.onChanged,
    this.maxLevel = 5,
  });

  /// 0 = không cay, 1..5
  final int value;
  final ValueChanged<int> onChanged;
  final int maxLevel;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: List.generate(maxLevel + 1, (i) {
          final level = i;
          final selected = value == level;
          return Padding(
            padding: const EdgeInsets.only(right: AppSpacing.xs),
            child: GestureDetector(
              onTap: () => onChanged(level),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.sm,
                  vertical: AppSpacing.xs,
                ),
                decoration: BoxDecoration(
                  color: selected ? AppColors.spicyRed : Colors.white,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                  border: Border.all(
                    color: selected ? AppColors.spicyRed : AppColors.border,
                    width: 1.5,
                  ),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      level == 0 ? 'Không cay' : 'Cấp $level',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: selected ? Colors.white : AppColors.textPrimary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        }),
      ),
    );
  }
}

/// ============================================================
/// 🔢 QUANTITY STEPPER — tăng/giảm số lượng
/// ============================================================
class QuantityStepper extends StatelessWidget {
  const QuantityStepper({
    super.key,
    required this.value,
    required this.onChanged,
    this.min = 1,
    this.max = 99,
  });

  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;

  void _change(int delta) {
    final next = (value + delta).clamp(min, max);
    if (next != value) onChanged(next);
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _btn(context, Icons.remove_rounded, () => _change(-1),
              enabled: value > min),
          SizedBox(
            width: 40,
            child: Text(
              '$value',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ),
          _btn(context, Icons.add_rounded, () => _change(1),
              enabled: value < max),
        ],
      ),
    );
  }

  Widget _btn(BuildContext context, IconData icon, VoidCallback onTap,
      {required bool enabled}) {
    return Material(
      color: Colors.transparent,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: enabled ? onTap : null,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xs),
          child: Icon(
            icon,
            color: enabled ? AppColors.spicyRed : AppColors.textMuted,
            size: 20,
          ),
        ),
      ),
    );
  }
}

/// ============================================================
/// 🏷 STATUS BADGE — nhãn trạng thái đơn hàng
/// ============================================================
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status});

  /// Order status code (PENDING, CONFIRMED, PREPARING, READY, DELIVERING,
  /// DELIVERED, COMPLETED, CANCELLED)
  final String status;

  @override
  Widget build(BuildContext context) {
    final cfg = _config(status);
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.xs,
        vertical: AppSpacing.xxs,
      ),
      decoration: BoxDecoration(
        color: cfg.bg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: cfg.fg.withValues(alpha: 0.3)),
      ),
      child: Text(
        cfg.label,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: cfg.fg,
        ),
      ),
    );
  }

  static _BadgeConfig _config(String status) {
    return switch (status.toUpperCase()) {
      'PENDING' => const _BadgeConfig(
          label: 'Chờ xác nhận',
          bg: Color(0xFFFFF7E6),
          fg: AppColors.warning,
        ),
      'CONFIRMED' => const _BadgeConfig(
          label: 'Đã xác nhận',
          bg: Color(0xFFE3F2FD),
          fg: AppColors.info,
        ),
      'PREPARING' => const _BadgeConfig(
          label: 'Đang nấu',
          bg: Color(0xFFFFF1F0),
          fg: AppColors.spicyRed,
        ),
      'READY' || 'READY_NOTIFY_SHIPPER' => const _BadgeConfig(
          label: 'Sẵn sàng',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
        ),
      'DELIVERING' => const _BadgeConfig(
          label: 'Đang giao',
          bg: Color(0xFFE3F2FD),
          fg: AppColors.info,
        ),
      'DELIVERED' => const _BadgeConfig(
          label: 'Chờ khách xác nhận',
          bg: Color(0xFFFFF7E6),
          fg: AppColors.warning,
        ),
      'COMPLETED' => const _BadgeConfig(
          label: 'Hoàn tất',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
        ),
      'CANCELLED' => const _BadgeConfig(
          label: 'Đã hủy',
          bg: Color(0xFFFDEEEE),
          fg: AppColors.danger,
        ),
      _ => _BadgeConfig(
          label: status,
          bg: AppColors.creamSoft,
          fg: AppColors.textSecondary,
        ),
    };
  }
}

class _BadgeConfig {
  const _BadgeConfig({required this.label, required this.bg, required this.fg});
  final String label;
  final Color bg;
  final Color fg;
}

/// ============================================================
// 📭 EMPTY STATE — màn rỗng dùng cho cart, orders, v.v.
// ============================================================
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.actionLabel,
    this.onAction,
  });

  final String icon;
  final String title;
  final String? subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(icon, style: const TextStyle(fontSize: 64)),
            const SizedBox(height: AppSpacing.md),
            Text(
              title,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge,
            ),
            if (subtitle != null) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(
                subtitle!,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppSpacing.lg),
              ElevatedButton(
                onPressed: onAction,
                child: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
