// =============================================================
// 📅 RESERVATION MODELS — phản ánh response từ /api/reservations
// Đồng bộ với ReservationController.php + config/reservations.php
// =============================================================

/// Cấu hình reservation (load 1 lần từ /reservations/options)
class ReservationOptions {
  ReservationOptions({
    required this.timezone,
    required this.opensAt,
    required this.closesAt,
    required this.minAdvanceMinutes,
    required this.maxGuests,
    required this.maxNoteLength,
    required this.serverNow,
  });

  final String timezone;          // "Asia/Ho_Chi_Minh"
  final String opensAt;           // "10:00"
  final String closesAt;          // "22:00"
  final int minAdvanceMinutes;    // 60
  final int maxGuests;            // 20
  final int maxNoteLength;        // 1000
  final DateTime? serverNow;      // ISO8601

  factory ReservationOptions.fromJson(Map<String, dynamic> json) {
    DateTime? parseNow(String? s) {
      if (s == null || s.isEmpty) return null;
      try {
        return DateTime.parse(s);
      } catch (_) {
        return null;
      }
    }

    return ReservationOptions(
      timezone: (json['timezone'] ?? 'Asia/Ho_Chi_Minh').toString(),
      opensAt: (json['opens_at'] ?? '10:00').toString(),
      closesAt: (json['closes_at'] ?? '22:00').toString(),
      minAdvanceMinutes: (json['min_advance_minutes'] as num?)?.toInt() ?? 60,
      maxGuests: (json['max_guests'] as num?)?.toInt() ?? 20,
      maxNoteLength: (json['max_note_length'] as num?)?.toInt() ?? 1000,
      serverNow: parseNow(json['server_now']?.toString()),
    );
  }

  /// Parse "HH:mm" thành DateTime hôm nay
  static DateTime? _parseHHmm(String hhmm) {
    final parts = hhmm.split(':');
    if (parts.length != 2) return null;
    final h = int.tryParse(parts[0]);
    final m = int.tryParse(parts[1]);
    if (h == null || m == null) return null;
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day, h, m);
  }

  Duration? get openWindow {
    final o = _parseHHmm(opensAt);
    final c = _parseHHmm(closesAt);
    if (o == null || c == null) return null;
    return c.difference(o);
  }

  /// Mốc mở/đóng trong ngày (dùng để validate client-side)
  DateTime? get opensAtToday => _parseHHmm(opensAt);
  DateTime? get closesAtToday => _parseHHmm(closesAt);
}

/// Trạng thái reservation (đồng bộ backend)
enum ReservationStatus { pending, confirmed, arrived, cancelled, completed, unknown }

ReservationStatus parseReservationStatus(String? raw) {
  switch ((raw ?? '').toUpperCase()) {
    case 'PENDING':
      return ReservationStatus.pending;
    case 'CONFIRMED':
      return ReservationStatus.confirmed;
    case 'ARRIVED':
      return ReservationStatus.arrived;
    case 'CANCELLED':
      return ReservationStatus.cancelled;
    case 'COMPLETED':
      return ReservationStatus.completed;
    default:
      return ReservationStatus.unknown;
  }
}

/// Form đặt bàn (input cho API POST /reservations)
class ReservationForm {
  ReservationForm({
    required this.customerName,
    required this.customerPhone,
    required this.guestCount,
    required this.reservedDate,
    required this.reservedTime,
    this.location = 'Tầng 1',
    this.note = '',
    this.requestKey,
  });

  final String customerName;
  final String customerPhone;
  final int guestCount;
  final DateTime reservedDate;       // YYYY-MM-DD
  final String reservedTime;         // HH:mm
  final String location;            // "Tầng 1" | "Tầng 2"
  final String note;
  final String? requestKey;         // UUID v4 — dùng cho idempotent retry

  Map<String, dynamic> toJson() {
    return {
      'customer_name': customerName.trim(),
      'customer_phone': customerPhone.trim(),
      'guest_count': guestCount,
      'reserved_date':
          '${reservedDate.year.toString().padLeft(4, '0')}-${reservedDate.month.toString().padLeft(2, '0')}-${reservedDate.day.toString().padLeft(2, '0')}',
      'reserved_time': reservedTime,
      'location': location,
      if (note.trim().isNotEmpty) 'note': note.trim(),
      'request_key': requestKey,
    };
  }
}

/// Receipt trả về từ POST /reservations
class ReservationReceipt {
  ReservationReceipt({
    required this.id,
    required this.customerName,
    required this.guestCount,
    required this.reservedDate,
    required this.reservedTime,
    required this.status,
    this.location = 'Tầng 1',
  });

  final int id;
  final String customerName;
  final int guestCount;
  final DateTime reservedDate;
  final String reservedTime;
  final ReservationStatus status;
  final String location;

  factory ReservationReceipt.fromJson(Map<String, dynamic> json) {
    DateTime parseDate(String? s) {
      if (s == null) return DateTime.now();
      try {
        return DateTime.parse(s);
      } catch (_) {
        return DateTime.now();
      }
    }

    return ReservationReceipt(
      id: (json['id'] as num).toInt(),
      customerName: (json['customer_name'] ?? '').toString(),
      guestCount: (json['guest_count'] as num?)?.toInt() ?? 1,
      reservedDate: parseDate(json['reserved_date']?.toString()),
      reservedTime: (json['reserved_time'] ?? '').toString(),
      status: parseReservationStatus(json['status']?.toString()),
      location: (json['location'] ?? 'Tầng 1').toString(),
    );
  }
}
