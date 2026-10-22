import 'package:intl/intl.dart';

/// ============================================================
/// 💰 MONEY VND — format + parse tiền Việt Nam đồng
/// ============================================================
class MoneyVnd {
  MoneyVnd._();

  /// Format số → "120.000 đ"
  /// - Làm tròn đến đơn vị đồng (không lẻ)
  /// - Phân cách hàng nghìn bằng dấu chấm
  static String format(num? amount) {
    if (amount == null) return '0 đ';
    final formatter = NumberFormat('#,##0', 'vi_VN');
    return '${formatter.format(amount.round())} đ';
  }

  /// Format không có " đ" ở cuối → "120.000"
  static String formatNumber(num? amount) {
    if (amount == null) return '0';
    final formatter = NumberFormat('#,##0', 'vi_VN');
    return formatter.format(amount.round());
  }

  /// Parse chuỗi "120.000" hoặc "120000" → 120000
  static num? parse(String? text) {
    if (text == null || text.trim().isEmpty) return null;
    final cleaned = text.replaceAll(RegExp(r'[^\d]'), '');
    return num.tryParse(cleaned);
  }
}
