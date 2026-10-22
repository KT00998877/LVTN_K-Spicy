import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../domain/reservation_models.dart';

/// ============================================================
/// 📅 RESERVATION REPOSITORY — giao tiếp /api/reservations
/// ============================================================
/// Endpoint PUBLIC (không cần auth):
///   - GET  /api/reservations/options
///   - POST /api/reservations (rate-limited 30/min)
/// ============================================================

class ReservationRepository {
  ReservationRepository(this._dio);
  final Dio _dio;

  /// GET /api/reservations/options — cấu hình mở cửa, max khách, ...
  Future<ReservationOptions> fetchOptions() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/reservations/options');
      final data = res.data?['data'] as Map?;
      if (data == null) {
        throw const ApiException(message: 'Không tải được cấu hình đặt bàn');
      }
      return ReservationOptions.fromJson(data.cast<String, dynamic>());
    } on DioException catch (e) {
      throw e.error as ApiException;
    }
  }

  /// POST /api/reservations — tạo đặt bàn (kèm UUID idempotency)
  Future<ReservationReceipt> create(ReservationForm form) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/reservations',
        data: form.toJson(),
      );
      final data = res.data?['data'] as Map?;
      if (data == null) {
        throw const ApiException(message: 'Đặt bàn thất bại');
      }
      return ReservationReceipt.fromJson(data.cast<String, dynamic>());
    } on DioException catch (e) {
      throw e.error as ApiException;
    }
  }
}

final reservationRepositoryProvider = Provider<ReservationRepository>((ref) {
  return ReservationRepository(ref.watch(dioProvider));
});

/// ============================================================
/// 📅 RESERVATION OPTIONS PROVIDER — cache options
/// ============================================================

final reservationOptionsProvider = FutureProvider<ReservationOptions>((ref) {
  return ref.watch(reservationRepositoryProvider).fetchOptions();
});
