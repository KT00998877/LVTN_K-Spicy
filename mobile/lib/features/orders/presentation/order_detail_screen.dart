import 'package:go_router/go_router.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../domain/order_models.dart';
import 'cancel_order_dialog.dart';
import 'order_controller.dart';
import 'order_visuals.dart';

/// ============================================================
/// 📄 ORDER DETAIL — chi tiết đơn + action buttons
/// ============================================================
/// Sections (theo rule nhà hàng + UX mobile):
///   1. Hero: mã đơn + status lớn + ETA / đếm giờ
///   2. Action: Thanh toán VNPay / "Tôi đã nhận hàng"
///   3. Timeline: các mốc (đặt → xác nhận → nấu → giao → xong)
///   4. Món ăn: danh sách items + topping
///   5. Thanh toán: subtotal / discount / ship / total
///   6. Giao hàng: tên, SĐT, địa chỉ, shipper
///   7. Mã đơn + placed/completed time
/// ============================================================

class OrderDetailScreen extends ConsumerWidget {
  const OrderDetailScreen({super.key, required this.orderId});
  final int orderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(orderDetailControllerProvider(orderId));

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        leading: BackButton(
          onPressed: () {
            if (context.canPop()) {
              context.pop();
            } else {
              context.go('/home');
            }
          },
        ),
        title: Text(state.order != null ? '#${state.order!.code}' : 'Đơn hàng'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: () => ref
                .read(orderDetailControllerProvider(orderId).notifier)
                .load(),
          ),
        ],
      ),
      body: _buildBody(context, ref, state),
    );
  }

  Widget _buildBody(
    BuildContext context,
    WidgetRef ref,
    OrderDetailState state,
  ) {
    if (state.isLoading && state.order == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.errorMessage != null && state.order == null) {
      return EmptyState(
        icon: '😢',
        title: 'Không tải được đơn',
        subtitle: state.errorMessage,
        actionLabel: 'Thử lại',
        onAction: () =>
            ref.read(orderDetailControllerProvider(orderId).notifier).load(),
      );
    }
    final order = state.order;
    if (order == null) {
      return const EmptyState(icon: '📦', title: 'Không tìm thấy đơn');
    }

    return RefreshIndicator(
      color: AppColors.spicyRed,
      onRefresh: () =>
          ref.read(orderDetailControllerProvider(orderId).notifier).load(),
      child: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          // ── Hero ───────────────────────────────────────
          _Hero(order: order),

          // ── Action buttons ────────────────────────────
          if (order.canPay ||
              order.canConfirmReceipt ||
              order.canCancel ||
              order.canTrackShipper) ...[
            const SizedBox(height: AppSpacing.md),
            _ActionBar(order: order),
          ],

          const SizedBox(height: AppSpacing.md),

          // ── Cancelled banner ──────────────────────────
          if (order.status == OrderStatus.cancelled) ...[
            _CancelledBanner(order: order),
            const SizedBox(height: AppSpacing.md),
          ],

          // ── Timeline ──────────────────────────────────
          _SectionTitle(icon: Icons.timeline_rounded, text: 'Tiến trình'),
          const SizedBox(height: AppSpacing.xs),
          _Timeline(order: order),

          const SizedBox(height: AppSpacing.md),

          // ── Items ─────────────────────────────────────
          _SectionTitle(
            icon: Icons.restaurant_menu_rounded,
            text: 'Món đã đặt (${order.items.length})',
          ),
          const SizedBox(height: AppSpacing.xs),
          _ItemsCard(order: order),

          const SizedBox(height: AppSpacing.md),

          // ── Payment summary ───────────────────────────
          _SectionTitle(icon: Icons.receipt_long_rounded, text: 'Thanh toán'),
          const SizedBox(height: AppSpacing.xs),
          _PaymentSummary(order: order),

          // ── Delivery (chỉ ONLINE) ─────────────────────
          if (order.isOnline) ...[
            const SizedBox(height: AppSpacing.md),
            _SectionTitle(
              icon: Icons.delivery_dining_rounded,
              text: 'Thông tin giao hàng',
            ),
            const SizedBox(height: AppSpacing.xs),
            _DeliveryCard(order: order),
          ],

          const SizedBox(height: AppSpacing.md),

          // ── Order meta ────────────────────────────────
          _SectionTitle(icon: Icons.info_outline_rounded, text: 'Chi tiết'),
          const SizedBox(height: AppSpacing.xs),
          _MetaCard(order: order),

          const SizedBox(height: AppSpacing.lg),
        ],
      ),
    );
  }
}

/// ───────────────── Hero ─────────────────
class _Hero extends StatelessWidget {
  const _Hero({required this.order});
  final OrderDetail order;

  String? _etaText() {
    if (order.status == OrderStatus.delivering && order.shipperName != null) {
      return 'Shipper ${order.shipperName} đang giao';
    }
    if (order.status == OrderStatus.preparing) {
      return 'Bếp đang chuẩn bị món';
    }
    if (order.status == OrderStatus.confirmed) {
      return 'Đơn đã được xác nhận, chờ vào bếp';
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final v = OrderStatusVisual.of(order.status);
    final eta = _etaText();

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [v.fg, v.fg.withValues(alpha: 0.85)],
        ),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(v.icon, color: Colors.white, size: 28),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  v.label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 20,
                    letterSpacing: -0.2,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Mã đơn: ${order.code}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.9),
              fontSize: 12,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.6,
            ),
          ),
          if (eta != null) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.2),
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
              child: Text(
                eta,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// ───────────────── Action bar ─────────────────
class _ActionBar extends ConsumerWidget {
  const _ActionBar({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ctrl = ref.read(orderDetailControllerProvider(order.id).notifier);
    final busy = ref.watch(orderDetailControllerProvider(order.id)).isLoading;
    final buttons = <Widget>[];

    if (order.canPay) {
      buttons.add(
        _primaryAction(
          icon: Icons.payment_rounded,
          label: 'Thanh toán VNPay',
          color: AppColors.spicyRed,
          enabled: !busy,
          onPressed: () => context.push('/orders/${order.id}/payment'),
        ),
      );
    }

    if (order.canConfirmReceipt) {
      buttons.add(
        _primaryAction(
          icon: Icons.check_circle_rounded,
          label: 'Tôi đã nhận hàng',
          color: AppColors.success,
          enabled: !busy,
          onPressed: () => _confirmReceipt(context, ref, order, ctrl),
        ),
      );
    }

    if (order.canTrackShipper) {
      buttons.add(
        _primaryAction(
          icon: Icons.map_rounded,
          label: 'Theo dõi shipper',
          color: AppColors.charcoal,
          enabled: !busy,
          onPressed: () => context.push('/orders/${order.id}/route'),
        ),
      );
    }

    if (order.canCancel) {
      buttons.add(
        _primaryAction(
          icon: Icons.cancel_outlined,
          label: 'Huỷ đơn',
          color: AppColors.danger,
          enabled: !busy,
          onPressed: () async {
            final ok = await CancelOrderDialog.show(context, order);
            if (ok == true && context.mounted) {
              ScaffoldMessenger.of(
                context,
              ).showSnackBar(const SnackBar(content: Text('Đã huỷ đơn hàng.')));
            }
          },
        ),
      );
    }

    if (buttons.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < buttons.length; i++) ...[
          if (i > 0) const SizedBox(height: AppSpacing.xs),
          buttons[i],
        ],
      ],
    );
  }

  Widget _primaryAction({
    required IconData icon,
    required String label,
    required Color color,
    required bool enabled,
    required VoidCallback onPressed,
  }) => ElevatedButton.icon(
    style: ElevatedButton.styleFrom(
      backgroundColor: color,
      foregroundColor: Colors.white,
      minimumSize: const Size.fromHeight(52),
    ),
    icon: Icon(icon),
    label: Text(label),
    onPressed: enabled ? onPressed : null,
  );

  Future<void> _confirmReceipt(
    BuildContext context,
    WidgetRef ref,
    OrderDetail order,
    OrderDetailController ctrl,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Xác nhận đã nhận hàng?'),
        content: const Text(
          'Sau khi xác nhận, đơn sẽ hoàn tất và điểm thưởng (nếu có) sẽ được cộng vào tài khoản.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Huỷ'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.success),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Xác nhận'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    final result = await ctrl.confirmReceipt();
    if (!context.mounted) return;
    if (result == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Xác nhận thất bại')));
      return;
    }

    ref.invalidate(orderListControllerProvider);
    // Hiển thị dialog kết quả
    await showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.celebration_rounded, color: AppColors.success),
            SizedBox(width: 8),
            Text('Hoàn tất!'),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Cảm ơn bạn đã xác nhận đơn hàng.'),
            if (result.pointsAwarded > 0) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(AppSpacing.sm),
                decoration: BoxDecoration(
                  color: AppColors.goldenYellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
                child: Row(
                  children: [
                    const Icon(
                      Icons.workspace_premium_rounded,
                      color: AppColors.goldenYellow,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Bạn nhận được +${result.pointsAwarded} điểm! '
                        'Tổng: ${result.loyaltyPoints} điểm.',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
        actions: [
          ElevatedButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Đóng'),
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Timeline ─────────────────
class _Timeline extends StatelessWidget {
  const _Timeline({required this.order});
  final OrderDetail order;

  static const _milestones = <_Milestone>[
    _Milestone(OrderStatus.pending, 'Đơn đã đặt', Icons.receipt_long_rounded),
    _Milestone(
      OrderStatus.confirmed,
      'Đã xác nhận',
      Icons.check_circle_rounded,
    ),
    _Milestone(
      OrderStatus.preparing,
      'Đang nấu',
      Icons.local_fire_department_rounded,
    ),
    _Milestone(OrderStatus.ready, 'Sẵn sàng', Icons.done_all_rounded),
    _Milestone(
      OrderStatus.delivering,
      'Đang giao',
      Icons.delivery_dining_rounded,
    ),
    _Milestone(OrderStatus.delivered, 'Đã giao', Icons.mark_email_read_rounded),
    _Milestone(OrderStatus.completed, 'Hoàn tất', Icons.celebration_rounded),
  ];

  int _stepIndex(OrderStatus s) {
    switch (s) {
      case OrderStatus.pending:
      case OrderStatus.cancelled:
      case OrderStatus.unknown:
        return 0;
      case OrderStatus.confirmed:
        return 1;
      case OrderStatus.preparing:
      case OrderStatus.readyNotifyShipper:
        return 2;
      case OrderStatus.ready:
        return 3;
      case OrderStatus.delivering:
        return 4;
      case OrderStatus.delivered:
        return 5;
      case OrderStatus.served:
      case OrderStatus.completed:
        return 6;
    }
  }

  @override
  Widget build(BuildContext context) {
    final isCancelled = order.status == OrderStatus.cancelled;
    final current = _stepIndex(order.status);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          for (int i = 0; i < _milestones.length; i++) ...[
            _TimelineRow(
              milestone: _milestones[i],
              isPast: !isCancelled && i <= current,
              isCurrent: !isCancelled && i == current,
              isCancelledFlow: isCancelled,
              event: _matchingEvent(_milestones[i].status),
            ),
            if (i < _milestones.length - 1)
              Container(
                margin: const EdgeInsets.only(left: 16),
                height: 14,
                width: 2,
                color: (!isCancelled && i < current)
                    ? AppColors.success
                    : AppColors.border,
              ),
          ],
        ],
      ),
    );
  }

  OrderEvent? _matchingEvent(OrderStatus s) {
    for (final e in order.events) {
      if (e.status == s) return e;
    }
    return null;
  }
}

class _Milestone {
  const _Milestone(this.status, this.label, this.icon);
  final OrderStatus status;
  final String label;
  final IconData icon;
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({
    required this.milestone,
    required this.isPast,
    required this.isCurrent,
    required this.isCancelledFlow,
    required this.event,
  });

  final _Milestone milestone;
  final bool isPast;
  final bool isCurrent;
  final bool isCancelledFlow;
  final OrderEvent? event;

  @override
  Widget build(BuildContext context) {
    final color = isCancelledFlow
        ? AppColors.textMuted
        : (isPast ? AppColors.success : AppColors.border);
    final textColor = (isPast || isCurrent) && !isCancelledFlow
        ? AppColors.textPrimary
        : AppColors.textMuted;
    final timeText = event?.createdAt != null
        ? DateFormat('HH:mm · dd/MM').format(event!.createdAt!)
        : null;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: isPast || isCurrent ? color : Colors.white,
            shape: BoxShape.circle,
            border: Border.all(color: color, width: 2),
          ),
          alignment: Alignment.center,
          child: Icon(
            milestone.icon,
            size: 16,
            color: (isPast || isCurrent) ? Colors.white : color,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                milestone.label,
                style: TextStyle(
                  color: textColor,
                  fontWeight: isCurrent ? FontWeight.w800 : FontWeight.w600,
                  fontSize: 14,
                ),
              ),
              if (timeText != null)
                Text(
                  timeText,
                  style: const TextStyle(
                    color: AppColors.textMuted,
                    fontSize: 11,
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// ───────────────── Items card ─────────────────
class _ItemsCard extends StatelessWidget {
  const _ItemsCard({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          for (int i = 0; i < order.items.length; i++) ...[
            _ItemRow(item: order.items[i]),
            if (i < order.items.length - 1)
              const Divider(height: 1, indent: 16, endIndent: 16),
          ],
        ],
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({required this.item});
  final OrderItem item;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppColors.spicyRedSoft,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            alignment: Alignment.center,
            child: Text(
              '${item.quantity}x',
              style: const TextStyle(
                color: AppColors.spicyRed,
                fontWeight: FontWeight.w800,
                fontSize: 13,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.name,
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 14,
                  ),
                ),
                if (item.spicyLevel != null && item.spicyLevel! > 0) ...[
                  const SizedBox(height: 2),
                  Text(
                    'Cấp ${item.spicyLevel}',
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppColors.spicyRed,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
                if (item.toppings.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    '+ ${item.toppings.map((t) => t.name).join(', ')}',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
                if (item.note != null && item.note!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Ghi chú: ${item.note}',
                    style: const TextStyle(
                      fontSize: 11,
                      fontStyle: FontStyle.italic,
                      color: AppColors.textMuted,
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            MoneyVnd.format(item.subtotal),
            style: const TextStyle(
              color: AppColors.spicyRed,
              fontWeight: FontWeight.w800,
              fontSize: 14,
            ),
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Payment summary ─────────────────
class _PaymentSummary extends StatelessWidget {
  const _PaymentSummary({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          _Row(label: 'Tạm tính', value: MoneyVnd.format(order.subtotal)),
          if (order.discount > 0) ...[
            const SizedBox(height: 6),
            _Row(
              label: 'Giảm giá',
              value: '- ${MoneyVnd.format(order.discount)}',
              color: AppColors.success,
            ),
          ],
          if (order.shippingFee != null && order.shippingFee! > 0) ...[
            const SizedBox(height: 6),
            _Row(label: 'Phí giao', value: MoneyVnd.format(order.shippingFee!)),
          ],
          if ((order.pointsRedeemed ?? 0) > 0) ...[
            const SizedBox(height: 6),
            _Row(
              label: 'Điểm đã dùng',
              value: '${order.pointsRedeemed} điểm',
              color: AppColors.goldenYellow,
            ),
          ],
          if ((order.pointsAwarded ?? 0) > 0) ...[
            const SizedBox(height: 6),
            _Row(
              label: 'Điểm nhận được',
              value: '+${order.pointsAwarded} điểm',
              color: AppColors.goldenYellow,
            ),
          ],
          const SizedBox(height: 10),
          const Divider(height: 1),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'Tổng cộng',
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
              ),
              Text(
                MoneyVnd.format(order.totalAmount),
                style: const TextStyle(
                  color: AppColors.spicyRed,
                  fontWeight: FontWeight.w900,
                  fontSize: 20,
                  letterSpacing: -0.4,
                ),
              ),
            ],
          ),
          if (order.payment != null) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                const Icon(
                  Icons.receipt_outlined,
                  size: 14,
                  color: AppColors.textMuted,
                ),
                const SizedBox(width: 4),
                Text(
                  'Hoá đơn ${order.payment!.invoiceCode}',
                  style: const TextStyle(
                    fontSize: 12,
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const Spacer(),
                InvoiceStatusBadge(status: order.payment!.status),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value, this.color});
  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: AppColors.textSecondary)),
        Text(
          value,
          style: TextStyle(
            color: color ?? AppColors.textPrimary,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

/// ───────────────── Delivery card ─────────────────
class _DeliveryCard extends ConsumerWidget {
  const _DeliveryCard({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (order.recipientName != null)
            _InfoRow(
              icon: Icons.person_outline_rounded,
              label: 'Người nhận',
              value:
                  '${order.recipientName}'
                  '${order.recipientPhone != null ? ' · ${order.recipientPhone}' : ''}',
            ),
          if (order.deliveryAddress != null) ...[
            const SizedBox(height: 8),
            _InfoRow(
              icon: Icons.place_rounded,
              label: 'Địa chỉ',
              value: order.deliveryAddress!,
            ),
          ],
          if (order.paymentMethod != null) ...[
            const SizedBox(height: 8),
            _InfoRow(
              icon: Icons.payment_outlined,
              label: 'Thanh toán',
              value: order.paymentMethod == 'COD'
                  ? 'Tiền mặt khi nhận'
                  : 'Đã thanh toán trước',
            ),
          ],
          if (order.shipperName != null) ...[
            const SizedBox(height: 8),
            _InfoRow(
              icon: Icons.delivery_dining_rounded,
              label: 'Shipper',
              value: order.shipperName!,
            ),
          ],
          if (order.canTrackShipper) ...[
            const SizedBox(height: AppSpacing.sm),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: () => context.push('/orders/${order.id}/route'),
                icon: const Icon(Icons.map_rounded),
                label: const Text('Xem đường đi trên bản đồ'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({
    required this.icon,
    required this.label,
    required this.value,
  });
  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: AppColors.spicyRed),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 11,
                  color: AppColors.textMuted,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.5,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// ───────────────── Cancelled banner ─────────────────
class _CancelledBanner extends StatelessWidget {
  const _CancelledBanner({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(
          color: AppColors.danger.withValues(alpha: 0.35),
          width: 1.4,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.cancel_rounded, color: AppColors.danger, size: 24),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Đơn hàng đã được huỷ',
                  style: TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 15,
                    color: AppColors.danger,
                    letterSpacing: -0.2,
                  ),
                ),
                if (order.cancelledAt != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    'Huỷ lúc ${DateFormat('HH:mm dd/MM/yyyy').format(order.cancelledAt!.toLocal())}',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
                if (order.cancellationReason != null &&
                    order.cancellationReason!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Lý do: ${order.cancellationReason}',
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: AppColors.textSecondary,
                      fontStyle: FontStyle.italic,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Meta card ─────────────────
class _MetaCard extends StatelessWidget {
  const _MetaCard({required this.order});
  final OrderDetail order;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          _MetaRow('Mã đơn', order.code),
          const SizedBox(height: 6),
          _MetaRow('Loại đơn', _typeLabel(order.orderType)),
          if (order.note != null && order.note!.isNotEmpty) ...[
            const SizedBox(height: 6),
            _MetaRow('Ghi chú', order.note!),
          ],
          if (order.placedAt != null) ...[
            const SizedBox(height: 6),
            _MetaRow('Đặt lúc', formatDateTime(order.placedAt)),
          ],
          if (order.completedAt != null) ...[
            const SizedBox(height: 6),
            _MetaRow('Hoàn tất', formatDateTime(order.completedAt)),
          ],
        ],
      ),
    );
  }

  String _typeLabel(OrderType t) {
    switch (t) {
      case OrderType.online:
        return 'Giao tận nơi';
      case OrderType.takeaway:
        return 'Mang đi';
      case OrderType.dineIn:
        return 'Tại bàn';
      case OrderType.unknown:
        return '—';
    }
  }
}

class _MetaRow extends StatelessWidget {
  const _MetaRow(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 88,
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 12,
              color: AppColors.textMuted,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: const TextStyle(
              fontSize: 12,
              color: AppColors.textPrimary,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
      ],
    );
  }
}

/// ───────────────── Section title ─────────────────
class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: Row(
        children: [
          Icon(icon, size: 16, color: AppColors.spicyRed),
          const SizedBox(width: 6),
          Text(
            text,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.1,
              color: AppColors.textPrimary,
            ),
          ),
        ],
      ),
    );
  }
}
