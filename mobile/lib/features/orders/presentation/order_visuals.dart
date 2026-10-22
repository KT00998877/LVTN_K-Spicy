import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../domain/order_models.dart';

/// ============================================================
/// 🏷 ORDER STATUS VISUAL — label + màu + icon cho mọi status
/// ============================================================
/// Dùng chung giữa Orders List, Order Detail, Order Card.
/// ============================================================

class OrderStatusVisual {
  const OrderStatusVisual({
    required this.label,
    required this.bg,
    required this.fg,
    required this.icon,
  });

  final String label;
  final Color bg;
  final Color fg;
  final IconData icon;

  static OrderStatusVisual of(OrderStatus s) {
    switch (s) {
      case OrderStatus.pending:
        return const OrderStatusVisual(
          label: 'Chờ xác nhận',
          bg: Color(0xFFFFF7E6),
          fg: AppColors.warning,
          icon: Icons.schedule_rounded,
        );
      case OrderStatus.confirmed:
        return const OrderStatusVisual(
          label: 'Đã xác nhận',
          bg: Color(0xFFE3F2FD),
          fg: AppColors.info,
          icon: Icons.check_circle_outline_rounded,
        );
      case OrderStatus.preparing:
        return const OrderStatusVisual(
          label: 'Đang nấu',
          bg: Color(0xFFFFF1F0),
          fg: AppColors.spicyRed,
          icon: Icons.local_fire_department_rounded,
        );
      case OrderStatus.ready:
      case OrderStatus.readyNotifyShipper:
        return const OrderStatusVisual(
          label: 'Sẵn sàng',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
          icon: Icons.done_all_rounded,
        );
      case OrderStatus.delivering:
        return const OrderStatusVisual(
          label: 'Đang giao',
          bg: Color(0xFFE3F2FD),
          fg: AppColors.info,
          icon: Icons.delivery_dining_rounded,
        );
      case OrderStatus.delivered:
        return const OrderStatusVisual(
          label: 'Chờ bạn xác nhận',
          bg: Color(0xFFFFF7E6),
          fg: AppColors.warning,
          icon: Icons.mark_email_unread_rounded,
        );
      case OrderStatus.served:
        return const OrderStatusVisual(
          label: 'Đã phục vụ',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
          icon: Icons.restaurant_rounded,
        );
      case OrderStatus.completed:
        return const OrderStatusVisual(
          label: 'Hoàn tất',
          bg: Color(0xFFE6F7EE),
          fg: AppColors.success,
          icon: Icons.celebration_rounded,
        );
      case OrderStatus.cancelled:
        return const OrderStatusVisual(
          label: 'Đã huỷ',
          bg: Color(0xFFFDEEEE),
          fg: AppColors.danger,
          icon: Icons.cancel_rounded,
        );
      case OrderStatus.unknown:
        return const OrderStatusVisual(
          label: 'Không rõ',
          bg: AppColors.creamSoft,
          fg: AppColors.textSecondary,
          icon: Icons.help_outline_rounded,
        );
    }
  }
}

/// ============================================================
/// 🏷 ORDER STATUS BADGE — chip nhỏ gọn
/// ============================================================
class OrderStatusBadge extends StatelessWidget {
  const OrderStatusBadge({
    super.key,
    required this.status,
    this.compact = false,
  });
  final OrderStatus status;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final v = OrderStatusVisual.of(status);
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 10,
        vertical: compact ? 3 : 5,
      ),
      decoration: BoxDecoration(
        color: v.bg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: v.fg.withValues(alpha: 0.25)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(v.icon, color: v.fg, size: compact ? 11 : 13),
          const SizedBox(width: 4),
          Text(
            v.label,
            style: TextStyle(
              color: v.fg,
              fontSize: compact ? 10 : 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

/// ============================================================
/// 💳 INVOICE STATUS BADGE
/// ============================================================
class InvoiceStatusBadge extends StatelessWidget {
  const InvoiceStatusBadge({super.key, required this.status});
  final InvoiceStatus status;

  @override
  Widget build(BuildContext context) {
    late String label;
    late Color fg;
    switch (status) {
      case InvoiceStatus.unpaid:
        label = 'Chưa thanh toán';
        fg = AppColors.warning;
        break;
      case InvoiceStatus.pending:
        label = 'Đang xử lý';
        fg = AppColors.info;
        break;
      case InvoiceStatus.paid:
        label = 'Đã thanh toán';
        fg = AppColors.success;
        break;
      case InvoiceStatus.failed:
        label = 'Thanh toán lỗi';
        fg = AppColors.danger;
        break;
      case InvoiceStatus.refunded:
        label = 'Đã hoàn tiền';
        fg = AppColors.textSecondary;
        break;
      case InvoiceStatus.partial:
        label = 'Thanh toán một phần';
        fg = AppColors.warning;
        break;
      case InvoiceStatus.unknown:
        label = '—';
        fg = AppColors.textMuted;
        break;
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: fg.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        label,
        style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.w700),
      ),
    );
  }
}

/// ============================================================
/// 🏷 ORDER TYPE BADGE — giao tận nơi / mang đi / tại bàn
/// ============================================================
class OrderTypeBadge extends StatelessWidget {
  const OrderTypeBadge({super.key, required this.type});
  final OrderType type;

  @override
  Widget build(BuildContext context) {
    late String label;
    late IconData icon;
    switch (type) {
      case OrderType.online:
        label = 'Giao tận nơi';
        icon = Icons.delivery_dining_rounded;
        break;
      case OrderType.takeaway:
        label = 'Mang đi';
        icon = Icons.shopping_bag_rounded;
        break;
      case OrderType.dineIn:
        label = 'Tại bàn';
        icon = Icons.restaurant_rounded;
        break;
      case OrderType.unknown:
        label = '—';
        icon = Icons.help_outline_rounded;
        break;
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 13, color: AppColors.textSecondary),
        const SizedBox(width: 4),
        Text(
          label,
          style: const TextStyle(
            color: AppColors.textSecondary,
            fontSize: 11,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

/// ============================================================
/// ⏱ TIME FORMATTER
/// ============================================================
String formatRelativeTime(DateTime? dt) {
  if (dt == null) return '—';
  final diff = DateTime.now().difference(dt);
  if (diff.inMinutes < 1) return 'Vừa xong';
  if (diff.inMinutes < 60) return '${diff.inMinutes} phút trước';
  if (diff.inHours < 24) return '${diff.inHours} giờ trước';
  if (diff.inDays < 7) return '${diff.inDays} ngày trước';
  return '${dt.day}/${dt.month}/${dt.year}';
}

String formatDateTime(DateTime? dt) {
  if (dt == null) return '—';
  String pad(int v) => v.toString().padLeft(2, '0');
  return '${pad(dt.hour)}:${pad(dt.minute)} · ${pad(dt.day)}/${pad(dt.month)}/${dt.year}';
}
