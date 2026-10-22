import 'package:flutter/material.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../data/voucher_models.dart';
import 'voucher_list_picker.dart';

/// ============================================================
/// 🎟️ VOUCHER PICKER FIELD — ô gắn voucher vào CheckoutScreen
/// ============================================================
///
/// 2 trạng thái:
///   - Empty: ô nhập placeholder "Chọn hoặc nhập mã" → tap mở picker
///   - Applied: badge xanh + tên voucher + số tiền giảm + nút "Bỏ"
///
/// Component này KHÔNG tự gọi /checkout/quote. Parent (CheckoutScreen)
/// sẽ nghe [onChanged] → cập nhật voucher_code → trigger quote.
/// ============================================================

class VoucherPickerField extends StatelessWidget {
  const VoucherPickerField({
    super.key,
    required this.appliedCode,
    required this.preview,
    required this.subtotal,
    required this.onChanged,
    this.errorText,
    this.enabled = true,
  });

  /// Mã voucher đang áp dụng (null = chưa có)
  final String? appliedCode;

  /// Preview gần nhất (null nếu user chỉ mới nhập chưa preview xong).
  final VoucherPreview? preview;

  /// Tạm tính giỏ hàng — truyền xuống picker để check min_order_subtotal.
  final double subtotal;

  /// Callback khi user chọn/bỏ voucher (code = null nếu bỏ).
  final ValueChanged<VoucherPreview?> onChanged;

  /// Lỗi từ quote (vd "Mã không hợp lệ"). Hiển thị dưới field.
  final String? errorText;

  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final hasApplied = appliedCode != null && appliedCode!.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (hasApplied) _appliedView(context) else _emptyView(context),
        if (errorText != null && errorText!.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            errorText!,
            style: const TextStyle(
              color: AppColors.danger,
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ],
    );
  }

  Widget _emptyView(BuildContext context) => InkWell(
    onTap: enabled ? () => _open(context) : null,
    borderRadius: BorderRadius.circular(AppRadius.md),
    child: Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm + 2,
      ),
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.local_offer_outlined,
            color: AppColors.spicyRed,
            size: 20,
          ),
          const SizedBox(width: AppSpacing.xs),
          const Expanded(
            child: Text(
              'Chọn hoặc nhập mã giảm giá',
              style: TextStyle(
                color: AppColors.textSecondary,
                fontWeight: FontWeight.w600,
                fontSize: 14,
              ),
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: 4,
            ),
            decoration: BoxDecoration(
              color: AppColors.spicyRed,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: const Text(
              'Mở',
              style: TextStyle(
                color: Colors.white,
                fontSize: 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    ),
  );

  Widget _appliedView(BuildContext context) => Container(
    padding: const EdgeInsets.all(AppSpacing.sm),
    decoration: BoxDecoration(
      color: AppColors.success.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(AppRadius.md),
      border: Border.all(color: AppColors.success.withValues(alpha: 0.35)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        const Icon(
          Icons.check_circle_rounded,
          color: AppColors.success,
          size: 22,
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 2,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.success,
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                    ),
                    child: Text(
                      appliedCode!,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 12,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  if (preview != null)
                    Text(
                      '−${MoneyVnd.format(preview!.discount)}',
                      style: const TextStyle(
                        color: AppColors.success,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                ],
              ),
              if (preview != null) ...[
                const SizedBox(height: 2),
                Text(
                  preview!.name,
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        IconButton(
          tooltip: 'Bỏ mã',
          onPressed: enabled ? () => onChanged(null) : null,
          icon: const Icon(Icons.close_rounded, size: 18),
          color: AppColors.textMuted,
          visualDensity: VisualDensity.compact,
        ),
      ],
    ),
  );

  Future<void> _open(BuildContext context) async {
    final result = await VoucherListPicker.show(
      context,
      subtotal: subtotal,
      initialCode: appliedCode,
    );
    if (context.mounted && result?.preview != null) onChanged(result!.preview);
  }
}
