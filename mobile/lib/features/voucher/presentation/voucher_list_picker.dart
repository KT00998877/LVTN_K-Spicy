import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/utils/money_vnd.dart';
import '../data/voucher_models.dart';
import '../data/voucher_repository.dart';

/// ============================================================
/// 🎟️ VOUCHER LIST PICKER — bottom sheet
/// ============================================================
///
/// Mở từ checkout screen. Trả về:
///   - null: user đóng sheet không chọn gì
///   - [VoucherPickResult] với code + preview nếu đã chọn / nhập
///
/// UX:
///   - Top: ô nhập mã thủ công + nút "Áp dụng" (uppercase)
///   - Divider có chữ "Hoặc chọn từ danh sách"
///   - Grid chip các voucher đang hiệu lực (từ /api/vouchers?active=1)
///   - Voucher đủ điều kiện (subtotal ≥ min_order_subtotal) → tap được
///   - Voucher không đủ điều kiện → disabled + tooltip lý do
///   - Nếu subtotal = 0 (cart trống), tất cả voucher disabled
///
/// Khác với Vue:
///   - Vue render inline trong checkout, mobile dùng bottom sheet cho
///     tiết kiệm diện tích + gesture quen thuộc (iOS share sheet style).
/// ============================================================

class VoucherPickResult {
  const VoucherPickResult({required this.code, this.preview});
  final String code;
  final VoucherPreview? preview;
}

/// Hiển thị picker dạng modal bottom sheet.
/// [subtotal] là tạm tính giỏ hàng — dùng để check min_order_subtotal
/// và truyền vào /vouchers/preview để biết số tiền giảm thực tế.
class VoucherListPicker {
  static Future<VoucherPickResult?> show(
    BuildContext context, {
    required double subtotal,
    String? initialCode,
  }) {
    return showModalBottomSheet<VoucherPickResult>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.cream,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
      ),
      builder: (ctx) {
        return VoucherListPickerSheet(
          subtotal: subtotal,
          initialCode: initialCode,
        );
      },
    );
  }
}

class VoucherListPickerSheet extends ConsumerStatefulWidget {
  const VoucherListPickerSheet({
    super.key,
    required this.subtotal,
    this.initialCode,
  });

  final double subtotal;
  final String? initialCode;

  @override
  ConsumerState<VoucherListPickerSheet> createState() =>
      _VoucherListPickerSheetState();
}

class _VoucherListPickerSheetState
    extends ConsumerState<VoucherListPickerSheet> {
  final _codeController = TextEditingController();
  final _focus = FocusNode();

  bool _loadingList = true;
  bool _submitting = false;
  String? _listError;
  List<Voucher> _vouchers = const [];

  String? _inputError;

  @override
  void initState() {
    super.initState();
    _codeController.text = (widget.initialCode ?? '').toUpperCase();
    _loadList();
  }

  @override
  void dispose() {
    _codeController.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<void> _loadList() async {
    setState(() {
      _loadingList = true;
      _listError = null;
    });
    try {
      final list = await ref.read(voucherRepositoryProvider).fetchActive();
      if (!mounted) return;
      setState(() {
        _vouchers = list;
        _loadingList = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _listError = e.message;
        _loadingList = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _listError = 'Không tải được danh sách mã. Vui lòng thử lại.';
        _loadingList = false;
      });
    }
  }

  Future<void> _applyManual() async {
    if (_submitting) return;
    final code = _codeController.text.trim().toUpperCase();
    if (code.isEmpty) {
      setState(() => _inputError = 'Nhập mã trước khi áp dụng.');
      return;
    }
    setState(() {
      _submitting = true;
      _inputError = null;
    });
    try {
      final preview = await ref
          .read(voucherRepositoryProvider)
          .preview(code, subtotal: widget.subtotal);
      if (!mounted) return;
      Navigator.of(
        context,
      ).pop(VoucherPickResult(code: preview.code, preview: preview));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _inputError = e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() => _inputError = 'Không kiểm tra được mã. Vui lòng thử lại.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _pickVoucher(Voucher v) async {
    if (_submitting) return;
    if (!v.meetsSubtotal(widget.subtotal)) return;
    setState(() {
      _submitting = true;
      _inputError = null;
    });
    try {
      final preview = await ref
          .read(voucherRepositoryProvider)
          .preview(v.code, subtotal: widget.subtotal);
      if (!mounted) return;
      Navigator.of(
        context,
      ).pop(VoucherPickResult(code: preview.code, preview: preview));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _inputError = e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() => _inputError = 'Không kiểm tra được mã. Vui lòng thử lại.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final viewInsets = MediaQuery.of(context).viewInsets;
    return Padding(
      padding: EdgeInsets.only(bottom: viewInsets.bottom),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.xs,
            AppSpacing.md,
            AppSpacing.md,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _grabHandle(),
              const SizedBox(height: AppSpacing.xs),
              _header(),
              const SizedBox(height: AppSpacing.md),
              _manualInput(),
              if (_inputError != null) ...[
                const SizedBox(height: AppSpacing.xs),
                _errorBanner(_inputError!),
              ],
              const SizedBox(height: AppSpacing.md),
              _divider(),
              const SizedBox(height: AppSpacing.md),
              _listSection(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _grabHandle() => Center(
    child: Container(
      width: 40,
      height: 4,
      decoration: BoxDecoration(
        color: AppColors.border,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
    ),
  );

  Widget _header() => Row(
    children: [
      const Icon(Icons.confirmation_number_rounded, color: AppColors.spicyRed),
      const SizedBox(width: AppSpacing.xs),
      Expanded(
        child: Text(
          'Chọn mã giảm giá',
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w900,
            letterSpacing: -0.4,
          ),
        ),
      ),
      IconButton(
        icon: const Icon(Icons.close_rounded),
        tooltip: 'Đóng',
        onPressed: () => Navigator.of(context).pop(),
      ),
    ],
  );

  Widget _manualInput() => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Expanded(
        child: TextField(
          controller: _codeController,
          focusNode: _focus,
          enabled: !_submitting,
          maxLength: 50,
          textCapitalization: TextCapitalization.characters,
          style: const TextStyle(
            fontWeight: FontWeight.w800,
            letterSpacing: 0.6,
          ),
          inputFormatters: [
            FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9_-]')),
            _UpperCaseFormatter(),
          ],
          decoration: const InputDecoration(
            counterText: '',
            labelText: 'Nhập mã (vd: WELCOME10)',
            prefixIcon: Icon(Icons.local_offer_rounded),
          ),
          onSubmitted: (_) => _applyManual(),
        ),
      ),
      const SizedBox(width: AppSpacing.xs),
      SizedBox(
        height: 56,
        child: ElevatedButton(
          style: ElevatedButton.styleFrom(minimumSize: const Size(0, 52)),
          onPressed: _submitting ? null : _applyManual,
          child: _submitting
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.5,
                    color: Colors.white,
                  ),
                )
              : const Text('Áp dụng'),
        ),
      ),
    ],
  );

  Widget _errorBanner(String message) => Container(
    padding: const EdgeInsets.all(AppSpacing.sm),
    decoration: BoxDecoration(
      color: AppColors.danger.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(AppRadius.md),
      border: Border.all(color: AppColors.danger.withValues(alpha: 0.3)),
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
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    ),
  );

  Widget _divider() => Row(
    children: [
      const Expanded(child: Divider(color: AppColors.divider)),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
        child: Text(
          'Hoặc chọn từ danh sách',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
            color: AppColors.textMuted,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.4,
          ),
        ),
      ),
      const Expanded(child: Divider(color: AppColors.divider)),
    ],
  );

  Widget _listSection() {
    if (_loadingList) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: AppSpacing.lg),
        child: Center(
          child: SizedBox(
            width: 28,
            height: 28,
            child: CircularProgressIndicator(strokeWidth: 3),
          ),
        ),
      );
    }

    if (_listError != null) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Column(
          children: [
            _errorBanner(_listError!),
            const SizedBox(height: AppSpacing.sm),
            OutlinedButton.icon(
              onPressed: _loadList,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('Thử lại'),
            ),
          ],
        ),
      );
    }

    if (_vouchers.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: AppColors.creamSoft,
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: AppColors.border),
        ),
        child: const Row(
          children: [
            Icon(Icons.info_outline_rounded, color: AppColors.textMuted),
            SizedBox(width: 8),
            Expanded(
              child: Text(
                'Hiện chưa có mã khuyến mãi nào.',
                style: TextStyle(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
      );
    }

    return Wrap(
      spacing: AppSpacing.xs,
      runSpacing: AppSpacing.xs,
      children: _vouchers.map((v) => _voucherChip(v)).toList(),
    );
  }

  Widget _voucherChip(Voucher v) {
    final enabled = v.meetsSubtotal(widget.subtotal) && !_submitting;
    final disabledReason = _disabledReasonFor(v);
    return Tooltip(
      message: disabledReason ?? '',
      child: Opacity(
        opacity: enabled ? 1 : 0.55,
        child: InkWell(
          onTap: enabled ? () => _pickVoucher(v) : null,
          borderRadius: BorderRadius.circular(AppRadius.md),
          child: Container(
            constraints: const BoxConstraints(minWidth: 170),
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.xs + 2,
            ),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(
                color: enabled ? AppColors.spicyRed : AppColors.border,
                style: enabled ? BorderStyle.solid : BorderStyle.solid,
                width: enabled ? 1.4 : 1,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: enabled
                            ? AppColors.spicyRed
                            : AppColors.textMuted,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: Text(
                        v.badge,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w900,
                          fontSize: 12,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        v.code,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 12,
                          letterSpacing: 0.4,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  v.name,
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                    height: 1.3,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  _metaLine(v),
                  style: const TextStyle(
                    fontSize: 11,
                    color: AppColors.textMuted,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String? _disabledReasonFor(Voucher v) {
    if (v.isOutOfStock) return 'Mã đã hết lượt sử dụng.';
    if (widget.subtotal <= 0) return 'Giỏ hàng trống — chưa đủ điều kiện.';
    if (widget.subtotal < v.minOrderSubtotal) {
      return 'Đơn tối thiểu ${MoneyVnd.format(v.minOrderSubtotal)} để dùng mã này.';
    }
    return null;
  }

  String _metaLine(Voucher v) {
    final parts = <String>[];
    parts.add('Đơn từ ${MoneyVnd.format(v.minOrderSubtotal)}');
    if (v.usageLimitPerUser != null) {
      parts.add('${v.usageLimitPerUser}/khách');
    } else if (v.usageLimit != null) {
      final remain = v.remainingUses ?? 0;
      parts.add('Còn $remain lượt');
    }
    if (v.endsAt != null) {
      final days = v.endsAt!.difference(DateTime.now()).inDays;
      if (days >= 0 && days <= 7) parts.add('HSD $days ngày');
    }
    return parts.join(' · ');
  }
}

/// ============================================================
/// InputFormatter — tự động uppercase + uppercase khi gõ
/// ============================================================
class _UpperCaseFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    return newValue.copyWith(text: newValue.text.toUpperCase());
  }
}
