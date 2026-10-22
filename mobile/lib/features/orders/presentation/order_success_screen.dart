import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../domain/order_models.dart';
import 'order_controller.dart';
import 'order_visuals.dart';

/// ============================================================
// 🎉 ORDER SUCCESS — màn ăn mừng ngay sau khi checkout thành công
// ============================================================
// Theo rule restaurant-ui.mdc:
//   - Không phải SaaS celebration page, phải mang cảm giác nhà hàng
//   - Hero gradient spicy red → chili orange
//   - Số món + tổng tiền + mã đơn nổi bật
//   - 2 CTA: "Theo dõi đơn" (primary) + "Tiếp tục chọn món" (secondary)
//   - Confetti nhẹ bằng icon ớt + animation scale-in một lần
//   - Pop OrderSuccess khỏi stack khi user back (không quay lại checkout)
//
// Flow sử dụng (theo mobile cart_controller):
//   1. CheckoutScreen gọi `checkout()` thành công → nhận `CheckoutResult`
//   2. Navigator.pushReplacement tới `/orders/{id}/success` để chặn back về checkout
//   3. OrderSuccessScreen tự fetch OrderDetail để hiển thị mã + tổng tiền
//   4. Sau ~8s nếu user chưa bấm gì, tự động đi tới OrderDetail
// ============================================================

class OrderSuccessScreen extends ConsumerStatefulWidget {
  const OrderSuccessScreen({super.key, required this.orderId});
  final int orderId;

  @override
  ConsumerState<OrderSuccessScreen> createState() => _OrderSuccessScreenState();
}

class _OrderSuccessScreenState extends ConsumerState<OrderSuccessScreen>
    with TickerProviderStateMixin {
  late final AnimationController _enterCtrl;
  late final Animation<double> _scale;
  late final Animation<double> _fade;
  Timer? _autoNav;

  @override
  void initState() {
    super.initState();
    _enterCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    )..forward();
    _scale = CurvedAnimation(parent: _enterCtrl, curve: Curves.easeOutBack);
    _fade = CurvedAnimation(parent: _enterCtrl, curve: Curves.easeOut);

    // Refresh order detail ngay khi vào để có data mới nhất (status, items…)
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref
          .read(orderDetailControllerProvider(widget.orderId).notifier)
          .load();
    });

    // Sau 8s không tương tác → tự chuyển sang OrderDetail
    _autoNav = Timer(const Duration(seconds: 8), _goToDetail);
  }

  @override
  void dispose() {
    _autoNav?.cancel();
    _enterCtrl.dispose();
    super.dispose();
  }

  void _cancelAutoNav() => _autoNav?.cancel();

  void _goToDetail() {
    if (!mounted) return;
    _cancelAutoNav();
    // Replace cả success lẫn checkout để back ra home, không back vào success
    context.go('/orders/${widget.orderId}');
  }

  void _continueShopping() {
    _cancelAutoNav();
    // Pop tới home, không quay lại checkout
    context.go('/home');
  }

  void _copyCode(String code) {
    Clipboard.setData(ClipboardData(text: code));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Đã sao chép mã đơn'),
        duration: Duration(seconds: 2),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(orderDetailControllerProvider(widget.orderId));
    final order = state.order;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _goToDetail();
      },
      child: Scaffold(
        backgroundColor: AppColors.cream,
        body: SafeArea(
          child: FadeTransition(
            opacity: _fade,
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: AppSpacing.lg),

                  // ── Hero celebration ────────────────────────
                  ScaleTransition(
                    scale: _scale,
                    child: _SuccessHero(order: order),
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // ── Receipt card ────────────────────────────
                  _ReceiptCard(
                    order: order,
                    onCopyCode: order != null ? () => _copyCode(order.code) : null,
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // ── Hints ───────────────────────────────────
                  _NextSteps(order: order),
                  const SizedBox(height: AppSpacing.xl),

                  // ── CTAs ────────────────────────────────────
                  ElevatedButton.icon(
                    onPressed: _goToDetail,
                    icon: const Icon(Icons.receipt_long_rounded),
                    label: const Text('Theo dõi đơn hàng'),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedButton.icon(
                    onPressed: _continueShopping,
                    icon: const Icon(Icons.restaurant_menu_rounded),
                    label: const Text('Tiếp tục chọn món'),
                  ),
                  const SizedBox(height: AppSpacing.md),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// ───────────────── Hero ─────────────────
class _SuccessHero extends StatelessWidget {
  const _SuccessHero({required this.order});
  final OrderDetail? order;

  String _typeSubtitle(OrderType? t) {
    switch (t) {
      case OrderType.online:
        return 'Đơn giao tận nơi';
      case OrderType.takeaway:
        return 'Đơn mang đi';
      case OrderType.dineIn:
        return 'Đơn tại bàn';
      default:
        return 'Đơn của bạn';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.xl,
      ),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.spicyRed, AppColors.chiliOrange],
        ),
        borderRadius: BorderRadius.circular(AppRadius.xl),
        boxShadow: AppShadow.floating,
      ),
      child: Column(
        children: [
          // ── Animated checkmark with chili confetti ─────
          SizedBox(
            width: 96,
            height: 96,
            child: Stack(
              alignment: Alignment.center,
              children: [
                // Confetti ớt rơi nhẹ
                const Positioned(
                  top: 0,
                  left: 10,
                  child: _ChiliConfetti(angle: -0.3, delay: 100),
                ),
                const Positioned(
                  top: 0,
                  right: 14,
                  child: _ChiliConfetti(angle: 0.4, delay: 250),
                ),
                const Positioned(
                  bottom: 4,
                  right: 6,
                  child: _ChiliConfetti(angle: -0.5, delay: 400),
                ),
                const Positioned(
                  bottom: 8,
                  left: 4,
                  child: _ChiliConfetti(angle: 0.5, delay: 550),
                ),
                Container(
                  width: 88,
                  height: 88,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                    boxShadow: AppShadow.card,
                  ),
                  child: const Icon(
                    Icons.check_rounded,
                    color: AppColors.spicyRed,
                    size: 56,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            order == null ? 'Đặt món thành công!' : 'Đặt món thành công!',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 22,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            order == null
                ? 'Đang tải thông tin đơn…'
                : _typeSubtitle(order!.orderType),
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.9),
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

class _ChiliConfetti extends StatefulWidget {
  const _ChiliConfetti({required this.angle, required this.delay});
  final double angle;
  final int delay;

  @override
  State<_ChiliConfetti> createState() => _ChiliConfettiState();
}

class _ChiliConfettiState extends State<_ChiliConfetti>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    );
    Future.delayed(Duration(milliseconds: widget.delay), () {
      if (mounted) _c.forward();
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, __) {
        final t = _c.value;
        return Opacity(
          opacity: t < 0.2 ? 0 : (1 - t).clamp(0.0, 1.0),
          child: Transform.translate(
            offset: Offset(
              widget.angle * 30 * t,
              20 * t,
            ),
            child: Transform.rotate(
              angle: widget.angle * 3 * t,
              child: const Text('🌶', style: TextStyle(fontSize: 20)),
            ),
          ),
        );
      },
    );
  }
}

/// ───────────────── Receipt card ─────────────────
class _ReceiptCard extends StatelessWidget {
  const _ReceiptCard({required this.order, required this.onCopyCode});
  final OrderDetail? order;
  final VoidCallback? onCopyCode;

  @override
  Widget build(BuildContext context) {
    if (order == null) {
      return Container(
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: AppColors.border),
        ),
        child: const Row(
          children: [
            SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2.5),
            ),
            SizedBox(width: AppSpacing.sm),
            Text('Đang tải thông tin đơn…'),
          ],
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.card,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          // ── Code + status ────────────────────────────
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              children: [
                Row(
                  children: [
                    const Icon(
                      Icons.confirmation_number_rounded,
                      size: 16,
                      color: AppColors.textSecondary,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      'Mã đơn',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const Spacer(),
                    InkWell(
                      onTap: onCopyCode,
                      borderRadius: BorderRadius.circular(AppRadius.sm),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 6,
                          vertical: 2,
                        ),
                        child: Row(
                          children: [
                            Text(
                              '#${order!.code}',
                              style: const TextStyle(
                                color: AppColors.spicyRed,
                                fontWeight: FontWeight.w800,
                                fontSize: 16,
                                letterSpacing: -0.2,
                              ),
                            ),
                            const SizedBox(width: 4),
                            const Icon(
                              Icons.copy_rounded,
                              size: 14,
                              color: AppColors.spicyRed,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    OrderStatusBadge(status: order!.status),
                    const SizedBox(width: AppSpacing.xs),
                    OrderTypeBadge(type: order!.orderType),
                  ],
                ),
              ],
            ),
          ),

          const Divider(height: 1),

          // ── Items preview ────────────────────────────
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: AppSpacing.sm,
            ),
            child: Column(
              children: [
                for (final item in order!.items.take(3))
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Container(
                          width: 28,
                          height: 28,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: AppColors.spicyRedSoft,
                            borderRadius: BorderRadius.circular(
                              AppRadius.sm,
                            ),
                          ),
                          child: Text(
                            '${item.quantity}x',
                            style: const TextStyle(
                              color: AppColors.spicyRed,
                              fontWeight: FontWeight.w800,
                              fontSize: 11,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            item.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontWeight: FontWeight.w600,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        Text(
                          MoneyVnd.format(item.subtotal),
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: 12,
                            color: AppColors.textPrimary,
                          ),
                        ),
                      ],
                    ),
                  ),
                if (order!.items.length > 3)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      '+ ${order!.items.length - 3} món khác',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
              ],
            ),
          ),

          const Divider(height: 1),

          // ── Total ────────────────────────────────────
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Row(
              children: [
                const Text(
                  'Tổng thanh toán',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 14,
                    color: AppColors.textPrimary,
                  ),
                ),
                const Spacer(),
                Text(
                  MoneyVnd.format(order!.totalAmount),
                  style: const TextStyle(
                    color: AppColors.spicyRed,
                    fontWeight: FontWeight.w900,
                    fontSize: 20,
                    letterSpacing: -0.4,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// ───────────────── Next steps hint ─────────────────
class _NextSteps extends StatelessWidget {
  const _NextSteps({required this.order});
  final OrderDetail? order;

  @override
  Widget build(BuildContext context) {
    final hints = _buildHints(order);
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.goldenYellow.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: AppColors.goldenYellow.withValues(alpha: 0.35),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(
                Icons.notifications_active_rounded,
                size: 16,
                color: AppColors.goldenYellow,
              ),
              SizedBox(width: 6),
              Text(
                'Tiếp theo',
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 13,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          for (final h in hints)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Text(
                h,
                style: const TextStyle(
                  fontSize: 12.5,
                  color: AppColors.textSecondary,
                  height: 1.45,
                ),
              ),
            ),
        ],
      ),
    );
  }

  List<String> _buildHints(OrderDetail? o) {
    if (o == null) {
      return const [
        'Bếp đã nhận đơn và bắt đầu chuẩn bị món.',
        'Bạn có thể theo dõi tiến trình theo thời gian thực.',
      ];
    }
    switch (o.orderType) {
      case OrderType.online:
        return const [
          'Shipper sẽ được điều khiển đến địa chỉ của bạn sau khi món sẵn sàng.',
          'Bấm "Theo dõi đơn" để xem hướng đi của shipper và ETA.',
          'Sau khi nhận hàng, nhớ bấm "Tôi đã nhận hàng" để hoàn tất + nhận điểm thưởng.',
        ];
      case OrderType.takeaway:
        return const [
          'Món sẽ được đóng gói khi bếp hoàn tất.',
          'Bạn đến quán nhận món khi đơn chuyển sang trạng thái "Sẵn sàng".',
          'Có thể xem thời gian dự kiến trong trang chi tiết đơn.',
        ];
      case OrderType.dineIn:
        return const [
          'Món sẽ được phục vụ tại bàn của bạn.',
          'Bạn có thể theo dõi tiến trình trên trang chi tiết đơn.',
        ];
      case OrderType.unknown:
        return const [
          'Bếp đã nhận đơn và bắt đầu chuẩn bị món.',
        ];
    }
  }
}