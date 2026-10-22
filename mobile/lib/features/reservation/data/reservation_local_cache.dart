import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../domain/reservation_models.dart';

/// ============================================================
/// 💾 RESERVATION LOCAL CACHE — lưu booking receipts gần nhất
/// ============================================================
/// Backend không có endpoint "my reservations" cho customer.
/// Nên ta cache receipt mới nhất vào SharedPreferences để user
/// có thể xem lại "đơn vừa đặt" ngay trong session.
/// ============================================================

const _kLatestKey = 'reservation_latest_v1';
const _kHistoryKey = 'reservation_history_v1';
const _kHistoryMax = 5;

class ReservationLocalCache {
  ReservationLocalCache(this._prefs);
  final SharedPreferences _prefs;

  Future<void> saveReceipt(ReservationReceipt r) async {
    await _prefs.setString(_kLatestKey, _encode(r.toJson()));

    // Append vào history (giữ tối đa _kHistoryMax cái)
    final list = await loadHistory();
    list.removeWhere((e) => e.id == r.id);
    list.insert(0, r);
    if (list.length > _kHistoryMax) list.removeRange(_kHistoryMax, list.length);
    await _prefs.setString(
        _kHistoryKey, jsonEncode(list.map((e) => e.toJson()).toList()));
  }

  ReservationReceipt? loadLatest() {
    final raw = _prefs.getString(_kLatestKey);
    if (raw == null) return null;
    try {
      return ReservationReceipt.fromJson(_decode(raw));
    } catch (_) {
      return null;
    }
  }

  Future<List<ReservationReceipt>> loadHistory() async {
    final raw = _prefs.getString(_kHistoryKey);
    if (raw == null) return [];
    try {
      final list = jsonDecode(raw) as List;
      return list
          .map((e) => ReservationReceipt.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> clearAll() async {
    await _prefs.remove(_kLatestKey);
    await _prefs.remove(_kHistoryKey);
  }

  static String _encode(Map<String, dynamic> json) => jsonEncode(json);
  static Map<String, dynamic> _decode(String raw) =>
      (jsonDecode(raw) as Map).cast<String, dynamic>();
}

extension on ReservationReceipt {
  Map<String, dynamic> toJson() => {
        'id': id,
        'customer_name': customerName,
        'guest_count': guestCount,
        'reserved_date':
            '${reservedDate.year.toString().padLeft(4, '0')}-${reservedDate.month.toString().padLeft(2, '0')}-${reservedDate.day.toString().padLeft(2, '0')}',
        'reserved_time': reservedTime,
        'status': status.name.toUpperCase(),
      };
}

final reservationLocalCacheProvider = FutureProvider<ReservationLocalCache>((ref) async {
  final prefs = await SharedPreferences.getInstance();
  return ReservationLocalCache(prefs);
});
