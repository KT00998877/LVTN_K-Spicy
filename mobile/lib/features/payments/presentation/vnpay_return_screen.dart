import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../../core/api/dio_client.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../../core/utils/money_vnd.dart';
import '../../orders/presentation/order_controller.dart';
import '../data/payment_repository.dart';

/// ============================================================
/// 🎯 VNPAY RETURN SCREEN — landing khi deep link mở app
/// ============================================================
///
/// Được mở khi:
///   1. User thanh toán VNPay xong → backend `returnUrl()` redirect về
///      `micay://payment/return?code=<payment_code>&order_id=<id>`
///      → app_links bắt → router navigate tới `/payment/return?code=...`
///   2. Hoặc được mở thủ công từ `PaymentScreen` nếu user nhấn
///      "Xem kết quả" trong khi vẫn đang ở payment screen.
///
/// Polling `/payments/{code}/status` mỗi 4s tối đa 30 lần giống Vue.
/// IPN là authority quyết định trạng thái cuối cùng — nếu IPN tới
/// sau Return, status sẽ tự cập nhật ở lần poll tiếp theo.
/// ============================================================

class VNPayReturnScreen extends ConsumerStatefulWidget {
  const VNPayReturnScreen({
    super.key,
    required this.paymentCode,
    this.orderId,
  });

  final String paymentCode;
  final int? orderId;

  @override
  ConsumerState<VNPayReturnScreen> createState() =>
      _VNPayReturnScreenState();
}

class _VNPayReturnScreenState extends ConsumerState<VNPayReturnScreen> {
  Map<String, dynamic>? _result;
  String? _error;
  bool _busy = false;
  Timer? _timer;
  int _attempts = 0;
  static const _maxAttempts = 30; // 30 * 4s = 2 phút
  static const _interval = Duration(seconds: 4);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _refresh() async {
    if (_busy || _result != null &&
        _result!['status'] != 'pending' &&
        _result!['status'] != 'expired') {
      return;
    }
    if (widget.paymentCode.isEmpty) {
      setState(() => _error = 'Thiếu mã giao dịch VNPay.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final data = await ref
          .read(paymentRepositoryProvider)
          .status(widget.paymentCode);
      if (!mounted) return;
      setState(() {
        _result = data;
        _busy = false;
      });

      final status = data['status'] as String? ?? 'pending';
      if (['pending', 'expired'].contains(status) && _attempts < _maxAttempts) {
        _attempts++;
        _timer = Timer(_interval, _refresh);
      } else {
        _timer?.cancel();
      }

      // Nếu paid → refresh OrderDetail + OrderList để UI cập nhật
      if (status == 'paid' && widget.orderId != null) {
        ref
            .read(orderDetailControllerProvider(widget.orderId!).notifier)
            .load();
        ref.invalidate(orderListControllerProvider);
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = 'Chưa lấy được kết quả. Vui lòng kiểm tra lại.';
      });
    }
  }

  Future<void> _retry() async {
    final orderId = _result?['order_id'] as int? ?? widget.orderId;
    if (orderId == null) return;
    setState(() => _busy = true);
    try {
      final userId = ref.read(authControllerProvider).user?.id;
      await secureStorage.delete(key: 'vnpay:$userId:$orderId');
      if (!mounted) return;
      setState(() => _busy = false);
      context.go('/orders/$orderId/payment');
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = 'Chưa tạo được giao dịch mới. Hãy quay lại đơn hàng để kiểm tra.';
      });
    }
  }

  void _goToOrder() {
    final orderId = _result?['order_id'] as int? ?? widget.orderId;
    if (orderId == null) {
      context.go('/orders');
      return;
    }
    if (_result?['status'] == 'paid') {
      context.go('/orders/$orderId/success');
    } else {
      context.go('/orders/$orderId');
    }
  }

  String _titleFor(String status) {
    switch (status) {
      case 'paid':
        return 'Thanh toán thành công';
      case 'failed':
        return 'Thanh toán không thành công';
      case 'cancelled':
        return 'Thanh toán đã bị huỷ';
      case 'expired':
        return 'Đã hết thời gian chờ thanh toán';
      case 'refunded':
        return 'Giao dịch đã hoàn tiền';
      default:
        return 'Đang xác minh kết quả thanh toán…';
    }
  }

  ({Color bg, Color fg, IconData icon}) _visualsFor(String status) {
    switch (status) {
      case 'paid':
        return (
          bg: AppColors.success.withValues(alpha: 0.12),
          fg: AppColors.success,
          icon: Icons.check_circle_rounded,
        );
      case 'failed':
      case 'cancelled':
      case 'expired':
        return (
          bg: AppColors.danger.withValues(alpha: 0.10),
          fg: AppColors.danger,
          icon: Icons.cancel_rounded,
        );
      case 'refunded':
        return (
          bg: AppColors.creamSoft,
          fg: AppColors.textSecondary,
          icon: Icons.replay_rounded,
        );
      default:
        return (
          bg: AppColors.warning.withValues(alpha: 0.10),
          fg: AppColors.warning,
          icon: Icons.hourglass_top_rounded,
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = _result?['status'] as String? ?? 'pending';
    final v = _visualsFor(status);
    final canRetry = _result?['can_retry'] == true;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _goToOrder();
      },
      child: Scaffold(
        backgroundColor: AppColors.cream,
        appBar: AppBar(
          title: const Text('Kết quả thanh toán'),
          leading: IconButton(
            icon: const Icon(Icons.close_rounded),
            onPressed: _goToOrder,
            tooltip: 'Đóng',
          ),
        ),
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: AppSpacing.lg),

                // ── Hero status ────────────────────────────
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.lg,
                    vertical: AppSpacing.xl,
                  ),
                  decoration: BoxDecoration(
                    color: v.bg,
                    borderRadius: BorderRadius.circular(AppRadius.xl),
                    border: Border.all(
                      color: v.fg.withValues(alpha: 0.25),
                    ),
                  ),
                  child: Column(
                    children: [
                      Icon(v.icon, size: 64, color: v.fg),
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        _titleFor(status),
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: v.fg,
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.4,
                        ),
                      ),
                      if (_busy) ...[
                        const SizedBox(height: AppSpacing.sm),
                        const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2.5),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),

                // ── Receipt details ────────────────────────
                if (_result != null) _ReceiptCard(result: _result!),

                if (_error != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    decoration: BoxDecoration(
                      color: AppColors.danger.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      border: Border.all(
                        color: AppColors.danger.withValues(alpha: 0.3),
                      ),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.error_outline_rounded,
                            color: AppColors.danger, size: 18),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            _error!,
                            style: const TextStyle(
                              color: AppColors.danger,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],

                const SizedBox(height: AppSpacing.lg),

                // ── Pending hint ───────────────────────────
                if (status == 'pending' || status == 'expired') ...[
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    decoration: BoxDecoration(
                      color: AppColors.goldenYellow.withValues(alpha: 0.10),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      border: Border.all(
                        color: AppColors.goldenYellow.withValues(alpha: 0.35),
                      ),
                    ),
                    child: const Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(
                          Icons.info_outline_rounded,
                          color: AppColors.goldenYellow,
                          size: 18,
                        ),
                        SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Nhà hàng đang chờ xác nhận từ VNPay. Nếu tài khoản đã bị trừ tiền, vui lòng giữ mã giao dịch để đối soát.',
                            style: TextStyle(
                              fontSize: 12.5,
                              color: AppColors.textSecondary,
                              height: 1.45,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                ],

                // ── Actions ───────────────────────────────
                if (canRetry)
                  ElevatedButton.icon(
                    onPressed: _busy ? null : _retry,
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('Thanh toán lại'),
                  ),
                if (canRetry) const SizedBox(height: AppSpacing.sm),
                OutlinedButton.icon(
                  onPressed: _busy ? null : _refresh,
                  icon: const Icon(Icons.search_rounded),
                  label: Text(_busy ? 'Đang kiểm tra…' : 'Kiểm tra lại kết quả'),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextButton.icon(
                  onPressed: _goToOrder,
                  icon: const Icon(Icons.receipt_long_rounded),
                  label: Text(
                    status == 'paid' ? 'Tiếp tục' : 'Quay lại đơn hàng',
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// ───────────────── Receipt card ─────────────────
class _ReceiptCard extends StatelessWidget {
  const _ReceiptCard({required this.result});
  final Map<String, dynamic> result;

  @override
  Widget build(BuildContext context) {
    final orderId = result['order_id'];
    final amount = (result['amount'] as num?)?.toDouble() ?? 0;
    final txnId = result['transaction_id'] as String?;
    final paidAt = result['paid_at'] as String?;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          if (orderId != null)
            _Row(
              icon: Icons.receipt_long_rounded,
              label: 'Đơn hàng',
              value: '#$orderId',
            ),
          if (amount > 0) ...[
            const SizedBox(height: AppSpacing.xs),
            _Row(
              icon: Icons.payments_rounded,
              label: 'Số tiền',
              value: MoneyVnd.format(amount),
              valueColor: AppColors.spicyRed,
              bold: true,
            ),
          ],
          if (txnId != null) ...[
            const SizedBox(height: AppSpacing.xs),
            _Row(
              icon: Icons.tag_rounded,
              label: 'Mã giao dịch',
              value: txnId,
              copyable: true,
            ),
          ],
          if (paidAt != null) ...[
            const SizedBox(height: AppSpacing.xs),
            _Row(
              icon: Icons.event_available_rounded,
              label: 'Thời gian',
              value: paidAt,
            ),
          ],
          const SizedBox(height: AppSpacing.xs),
          _Row(
            icon: Icons.local_offer_rounded,
            label: 'Phương thức',
            value: 'VNPay',
          ),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.icon,
    required this.label,
    required this.value,
    this.valueColor,
    this.bold = false,
    this.copyable = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color? valueColor;
  final bool bold;
  final bool copyable;

  @override
  Widget build(BuildContext context) {
    final valueText = Text(
      value,
      style: TextStyle(
        fontSize: 13,
        fontWeight: bold ? FontWeight.w800 : FontWeight.w600,
        color: valueColor ?? AppColors.textPrimary,
      ),
    );

    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Icon(icon, size: 16, color: AppColors.spicyRed),
        const SizedBox(width: 8),
        SizedBox(
          width: 96,
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
          child: copyable
              ? InkWell(
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: value));
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Đã sao chép'),
                        duration: Duration(seconds: 2),
                      ),
                    );
                  },
                  child: Row(
                    children: [
                      Flexible(child: valueText),
                      const SizedBox(width: 4),
                      const Icon(
                        Icons.copy_rounded,
                        size: 12,
                        color: AppColors.spicyRed,
                      ),
                    ],
                  ),
                )
              : valueText,
        ),
      ],
    );
  }
}