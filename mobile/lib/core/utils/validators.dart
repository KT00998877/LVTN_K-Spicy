/// ============================================================
/// ✅ VALIDATORS — cho form đăng ký, đặt hàng
/// ============================================================
class Validators {
  Validators._();

  /// Số điện thoại VN: 10 số, bắt đầu bằng 0
  /// VD: 0912345678 (đúng) | 123456 (sai)
  static String? vietnamPhone(String? value) {
    if (value == null || value.trim().isEmpty) {
      return 'Vui lòng nhập số điện thoại';
    }
    final trimmed = value.trim();
    if (!RegExp(r'^(0[0-9]{9}|\+84[0-9]{9})$').hasMatch(trimmed)) {
      return 'Nhập số điện thoại dạng 0912345678 hoặc +84912345678';
    }
    return null;
  }

  /// Địa chỉ giao hàng: tối thiểu 10 ký tự (theo rule backend)
  static String? deliveryAddress(String? value) {
    if (value == null || value.trim().isEmpty) {
      return 'Vui lòng nhập địa chỉ giao hàng';
    }
    if (value.trim().length < 10) {
      return 'Địa chỉ phải có ít nhất 10 ký tự';
    }
    if (value.length > 500) {
      return 'Địa chỉ tối đa 500 ký tự';
    }
    return null;
  }

  /// Tên người nhận
  static String? recipientName(String? value) {
    if (value == null || value.trim().isEmpty) {
      return 'Vui lòng nhập tên người nhận';
    }
    if (value.trim().length > 100) {
      return 'Tên tối đa 100 ký tự';
    }
    return null;
  }

  /// Mật khẩu
  static String? password(String? value) {
    if (value == null || value.isEmpty) {
      return 'Vui lòng nhập mật khẩu';
    }
    if (value.length < 6) {
      return 'Mật khẩu tối thiểu 6 ký tự';
    }
    return null;
  }

  /// Mã voucher
  static String? voucherCode(String? value) {
    if (value == null || value.trim().isEmpty) return null;
    if (value.trim().length > 50) return 'Mã tối đa 50 ký tự';
    return null;
  }
}
