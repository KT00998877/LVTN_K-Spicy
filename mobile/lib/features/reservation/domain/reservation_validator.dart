import '../domain/reservation_models.dart';

/// ============================================================
/// ✅ RESERVATION VALIDATION — phản ánh StoreReservationRequest
/// ============================================================
/// Validate ở client trước khi gửi API để UX tốt hơn.
/// Backend vẫn validate lại (source of truth).
/// ============================================================

class ReservationValidationError {
  ReservationValidationError(this.message);
  final String message;
  @override
  String toString() => message;
}

class ReservationValidator {
  ReservationValidator._();

  static String? validateName(String? raw) {
    if (raw == null || raw.trim().isEmpty) return 'Vui lòng nhập họ và tên.';
    final trimmed = raw.trim();
    final length = trimmed.runes.length;
    if (length < 2) return 'Họ tên phải có ít nhất 2 ký tự.';
    if (length > 100) return 'Họ tên không được vượt quá 100 ký tự.';
    // Cho phép chữ cái, khoảng trắng, gạch ngang, dấu nháy đơn, dấu nháy cong ’
    final regex = RegExp(r"^[\p{L}][\p{L}\p{M} '\u2019\-]*$", unicode: true);
    if (!regex.hasMatch(trimmed)) {
      return 'Họ tên chỉ được chứa chữ, khoảng trắng, dấu gạch nối hoặc dấu nháy; không được chỉ chứa số.';
    }
    return null;
  }

  static String? validatePhone(String? raw) {
    if (raw == null || raw.trim().isEmpty) return 'Vui lòng nhập số điện thoại.';
    final cleaned = raw.replaceAll(RegExp(r'[\s.\-]'), '');
    final normalized = cleaned.replaceFirst(RegExp(r'^\+84'), '0');
    final regex = RegExp(r'^0[35789][0-9]{8}$');
    if (!regex.hasMatch(normalized)) {
      return 'Số điện thoại không đúng định dạng di động Việt Nam.';
    }
    return null;
  }

  static String? validateGuestCount(int? raw, int maxGuests) {
    if (raw == null) return 'Số khách phải là số nguyên.';
    if (raw < 1) return 'Số khách phải từ 1 người trở lên.';
    if (raw > maxGuests) {
      return 'Nhà hàng chỉ hỗ trợ đặt tối đa $maxGuests khách mỗi lượt. Vui lòng liên hệ trực tiếp nếu cần đặt nhóm lớn.';
    }
    return null;
  }

  static String? validateDate(DateTime? date, DateTime now) {
    if (date == null) return 'Vui lòng chọn ngày đặt bàn.';
    final today = DateTime(now.year, now.month, now.day);
    final picked = DateTime(date.year, date.month, date.day);
    if (picked.isBefore(today)) return 'Không thể đặt bàn cho ngày đã qua.';
    return null;
  }

  /// Validate thời gian đặt bàn
  static String? validateTime({
    required DateTime date,
    required String hhmm,
    required ReservationOptions options,
    required DateTime now,
  }) {
    final regex = RegExp(r'^([01]\d|2[0-3]):[0-5]\d$');
    if (!regex.hasMatch(hhmm)) return 'Vui lòng chọn giờ hợp lệ HH:mm.';

    final parts = hhmm.split(':');
    final h = int.parse(parts[0]);
    final m = int.parse(parts[1]);
    final at = DateTime(date.year, date.month, date.day, h, m);

    if (at.isBefore(now)) return 'Thời gian đặt bàn phải lớn hơn hiện tại.';

    final minAdvance = now.add(Duration(minutes: options.minAdvanceMinutes));
    if (at.isBefore(minAdvance)) {
      return 'Vui lòng đặt bàn trước ít nhất ${options.minAdvanceMinutes} phút.';
    }

    if (hhmm.compareTo(options.opensAt) < 0 ||
        hhmm.compareTo(options.closesAt) > 0) {
      return 'Nhà hàng nhận đặt bàn từ ${options.opensAt} đến ${options.closesAt}.';
    }

    return null;
  }

  static String? validateLocation(String? raw) {
    if (raw == null) return 'Vui lòng chọn khu vực.';
    if (raw != 'Tầng 1' && raw != 'Tầng 2') return 'Khu vực đặt bàn không hợp lệ.';
    return null;
  }

  static String? validateNote(String? raw, int maxLength) {
    if (raw == null) return null;
    if (raw.runes.length > maxLength) {
      return 'Ghi chú không được vượt quá $maxLength ký tự.';
    }
    if (raw.contains('<') || raw.contains('>')) {
      return 'Ghi chú không được chứa thẻ HTML hoặc script.';
    }
    return null;
  }
}

/// Helper: lấy thời gian hiện tại của restaurant (theo timezone từ options).
/// Fallback về DateTime.now() local nếu options không trả về server_now.
DateTime reservationNow(ReservationOptions options) {
  if (options.serverNow != null) return options.serverNow!;
  return DateTime.now();
}
