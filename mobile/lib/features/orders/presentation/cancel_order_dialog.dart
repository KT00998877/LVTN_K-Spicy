import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../data/order_repository.dart';
import '../domain/order_models.dart';
import 'order_controller.dart';

/// ============================================================
/// ❌ CANCEL ORDER DIALOG — xác nhận + lý do huỷ
/// ============================================================
///
/// Mở từ OrderDetail khi `order.canCancel` (chỉ PENDING, ONLINE/TAKEAWAY).
///
/// Flow:
///   1. User chọn lý do nhanh (3 quick options) HOẶC gõ custom
///   2. Bấm "Xác nhận huỷ" → gọi POST /api/orders/me/{id}/cancel
///   3. Backend xử lý (refund nếu có PREPAID đã thanh toán simulated)
///   4. UI refresh OrderDetail → status = CANCELLED, banner "Đã huỷ"
///   5. Invalidate OrderList để cập nhật danh sách
///
/// Khác Vue: Vue không có cancel từ customer. Đây là bổ sung mới.
/// ============================================================

class CancelOrderDialog extends ConsumerStatefulWidget {
  const CancelOrderDialog({super.key, required this.order});
  final OrderDetail order;

  /// Helper mở dialog từ OrderDetail.
  static Future<bool> show(BuildContext context, OrderDetail order) async {
    final result = await showDialog<bool>(
      context: context,
      builder: (_) => CancelOrderDialog(order: order),
    );
    return result ?? false;
  }

  @override
  ConsumerState<CancelOrderDialog> createState() => _CancelOrderDialogState();
}

class _CancelOrderDialogState extends ConsumerState<CancelOrderDialog> {
  String? _selectedReason;
  final _customReason = TextEditingController();
  bool _busy = false;
  String? _error;

  static const _quickReasons = <_ReasonOption>[
    _ReasonOption(
      code: 'changed_mind',
      label: 'Đổi ý, không muốn đặt nữa',
      detail: 'Tôi muốn huỷ vì thay đổi nhu cầu.',
    ),
    _ReasonOption(
      code: 'long_wait',
      label: 'Chờ lâu quá',
      detail: 'Thời gian chờ nhà hàng xác nhận quá lâu.',
    ),
    _ReasonOption(
      code: 'wrong_info',
      label: 'Nhập sai thông tin',
      detail: 'Tôi nhập sai địa chỉ / món / số điện thoại.',
    ),
    _ReasonOption(
      code: 'found_elsewhere',
      label: 'Đặt chỗ khác rồi',
      detail: 'Tôi đã đặt ở quán khác.',
    ),
  ];

  @override
  void dispose() {
    _customReason.dispose();
    super.dispose();
  }

  String _composeReason() {
    if (_selectedReason == 'other') {
      return _customReason.text.trim();
    }
    final option = _quickReasons.firstWhere(
      (o) => o.code == _selectedReason,
      orElse: () => const _ReasonOption(
        code: '',
        label: '',
        detail: '',
      ),
    );
    return option.detail;
  }

  bool get _canSubmit {
    if (_busy) return false;
    if (_selectedReason == null) return false;
    if (_selectedReason == 'other') {
      return _customReason.text.trim().length >= 3;
    }
    return true;
  }

  Future<void> _submit() async {
    if (!_canSubmit) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final reason = _composeReason();
    try {
      await ref
          .read(orderRepositoryProvider)
          .cancel(widget.order.id, reason);
      if (!mounted) return;
      // Refresh OrderDetail + OrderList
      ref
          .read(orderDetailControllerProvider(widget.order.id).notifier)
          .load();
      ref.invalidate(orderListControllerProvider);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = 'Không huỷ được đơn. Vui lòng thử lại.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: AppColors.cream,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      insetPadding: const EdgeInsets.all(AppSpacing.md),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(),
              const SizedBox(height: AppSpacing.sm),
              _refundNote(),
              const SizedBox(height: AppSpacing.md),
              Text(
                'Vui lòng chọn lý do:',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
              ),
              const SizedBox(height: AppSpacing.xs),
              ..._quickReasons.map(_reasonTile),
              _otherTile(),
              if (_error != null) ...[
                const SizedBox(height: AppSpacing.sm),
                _errorBanner(_error!),
              ],
              const SizedBox(height: AppSpacing.md),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () => Navigator.of(context).pop(false),
                      child: const Text('Đóng'),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.danger,
                        foregroundColor: Colors.white,
                        disabledBackgroundColor:
                            AppColors.danger.withValues(alpha: 0.4),
                      ),
                      onPressed: _canSubmit ? _submit : null,
                      child: _busy
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: Colors.white,
                              ),
                            )
                          : const Text('Xác nhận huỷ'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header() => Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: AppColors.danger.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: const Icon(
              Icons.cancel_outlined,
              color: AppColors.danger,
              size: 22,
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Huỷ đơn #${widget.order.code}?',
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'Đơn sẽ được huỷ ngay và không thể khôi phục.',
                  style: TextStyle(
                    fontSize: 12,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ],
      );

  Widget _refundNote() {
    final isPrepaid = widget.order.paymentMethod == 'PREPAID';
    if (!isPrepaid) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: AppColors.warning.withValues(alpha: 0.35),
        ),
      ),
      child: const Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.payments_outlined,
            size: 18,
            color: AppColors.warning,
          ),
          SizedBox(width: 8),
          Expanded(
            child: Text(
              'Đơn đã thanh toán VNPay. Tiền sẽ được hoàn lại khi huỷ. '
              'Vui lòng giữ biên lai nếu cần đối soát.',
              style: TextStyle(
                fontSize: 12,
                color: AppColors.textSecondary,
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _reasonTile(_ReasonOption option) => InkWell(
        onTap: _busy
            ? null
            : () => setState(() => _selectedReason = option.code),
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: Container(
          margin: const EdgeInsets.only(bottom: AppSpacing.xs),
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: AppSpacing.xs + 2,
          ),
          decoration: BoxDecoration(
            color: _selectedReason == option.code
                ? AppColors.danger.withValues(alpha: 0.10)
                : Colors.white,
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(
              color: _selectedReason == option.code
                  ? AppColors.danger
                  : AppColors.border,
              width: _selectedReason == option.code ? 1.4 : 1,
            ),
          ),
          child: Row(
            children: [
              Icon(
                _selectedReason == option.code
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_off_rounded,
                size: 18,
                color: _selectedReason == option.code
                    ? AppColors.danger
                    : AppColors.textMuted,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  option.label,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      );

  Widget _otherTile() {
    final selected = _selectedReason == 'other';
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: selected
            ? AppColors.danger.withValues(alpha: 0.10)
            : Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: selected ? AppColors.danger : AppColors.border,
          width: selected ? 1.4 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            onTap: _busy
                ? null
                : () => setState(() => _selectedReason = 'other'),
            child: Row(
              children: [
                Icon(
                  selected
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_off_rounded,
                  size: 18,
                  color: selected ? AppColors.danger : AppColors.textMuted,
                ),
                const SizedBox(width: 8),
                const Text(
                  'Lý do khác',
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          if (selected) ...[
            const SizedBox(height: AppSpacing.xs),
            TextField(
              controller: _customReason,
              enabled: !_busy,
              maxLength: 500,
              maxLines: 2,
              decoration: const InputDecoration(
                counterText: '',
                hintText: 'Mô tả lý do (tối thiểu 3 ký tự)',
                isDense: true,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _errorBanner(String message) => Container(
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
            const Icon(
              Icons.error_outline_rounded,
              color: AppColors.danger,
              size: 18,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                message,
                style: const TextStyle(
                  color: AppColors.danger,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
      );
}

class _ReasonOption {
  const _ReasonOption({
    required this.code,
    required this.label,
    required this.detail,
  });
  final String code;
  final String label;
  final String detail;
}
