import '../../../core/utils/money_vnd.dart';

/// ============================================================
/// 🎟️ VOUCHER MODELS — đồng bộ với backend VoucherController.php
/// ============================================================

class Voucher {
  const Voucher({
    required this.id,
    required this.code,
    required this.name,
    required this.description,
    required this.type,
    required this.value,
    required this.maxDiscountAmount,
    required this.minOrderSubtotal,
    required this.usageLimit,
    required this.usedCount,
    required this.usageLimitPerUser,
    required this.startsAt,
    required this.endsAt,
    required this.isActive,
    required this.badge,
  });

  final int id;
  final String code;
  final String name;
  final String? description;
  final String type; // 'PERCENT' | 'FIXED'
  final double value;
  final double? maxDiscountAmount;
  final double minOrderSubtotal;
  final int? usageLimit;
  final int usedCount;
  final int? usageLimitPerUser;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final bool isActive;
  final String badge;

  /// Voucher đã hết lượt dùng chưa.
  bool get isOutOfStock =>
      usageLimit != null && usedCount >= usageLimit!;

  /// Kiểm tra đơn hiện tại có đủ điều kiện áp dụng không.
  bool meetsSubtotal(double subtotal) =>
      subtotal >= minOrderSubtotal && !isOutOfStock;

  /// Số lượt còn lại (null = không giới hạn).
  int? get remainingUses =>
      usageLimit == null ? null : usageLimit! - usedCount;

  factory Voucher.fromJson(Map<String, dynamic> json) => Voucher(
        id: (json['id'] as num).toInt(),
        code: json['code'] as String,
        name: json['name'] as String,
        description: json['description'] as String?,
        type: json['type'] as String,
        value: (json['value'] as num?)?.toDouble() ?? 0,
        maxDiscountAmount:
            (json['max_discount_amount'] as num?)?.toDouble(),
        minOrderSubtotal:
            (json['min_order_subtotal'] as num?)?.toDouble() ?? 0,
        usageLimit: (json['usage_limit'] as num?)?.toInt(),
        usedCount: (json['used_count'] as num?)?.toInt() ?? 0,
        usageLimitPerUser: (json['usage_limit_per_user'] as num?)?.toInt(),
        startsAt: _parseDate(json['starts_at']),
        endsAt: _parseDate(json['ends_at']),
        isActive: json['is_active'] as bool? ?? false,
        badge: (json['badge'] as String?) ?? '',
      );

  static DateTime? _parseDate(dynamic raw) {
    if (raw == null) return null;
    if (raw is String) return DateTime.tryParse(raw);
    return null;
  }
}

/// ============================================================
/// Preview — kết quả từ POST /api/vouchers/preview
/// ============================================================
class VoucherPreview {
  const VoucherPreview({
    required this.code,
    required this.name,
    required this.type,
    required this.value,
    required this.discount,
    required this.minOrderSubtotal,
  });

  final String code;
  final String name;
  final String type;
  final double value;
  final double discount;
  final double minOrderSubtotal;

  factory VoucherPreview.fromJson(Map<String, dynamic> json) =>
      VoucherPreview(
        code: json['code'] as String,
        name: json['name'] as String,
        type: json['type'] as String,
        value: (json['value'] as num?)?.toDouble() ?? 0,
        discount: (json['discount'] as num?)?.toDouble() ?? 0,
        minOrderSubtotal:
            (json['min_order_subtotal'] as num?)?.toDouble() ?? 0,
      );

  String formatDiscount() => MoneyVnd.format(discount);
}
