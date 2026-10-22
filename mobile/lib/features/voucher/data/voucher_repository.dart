import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import 'voucher_models.dart';

/// ============================================================
/// 🎟️ VOUCHER REPOSITORY
/// ============================================================
///
/// Backend endpoints (đồng bộ với Vue `src/api/voucher.ts`):
/// - GET  /api/vouchers?active=1   → danh sách voucher đang hiệu lực
/// - POST /api/vouchers/preview     → validate + tính discount
///
/// Trong checkout Flutter, ta chủ yếu dùng `preview` qua
/// `POST /api/checkout/quote` (trả về `voucher_error` nếu không hợp lệ).
/// Repository này dùng cho picker — chỉ load danh sách + preview thử.
class VoucherRepository {
  VoucherRepository(this.dio);
  final Dio dio;

  /// GET /api/vouchers?active=1
  Future<List<Voucher>> fetchActive() async {
    try {
      final response = await dio.get<Map<String, dynamic>>(
        '/vouchers',
        queryParameters: {'active': 1},
      );
      final data = (response.data?['data'] as List?) ?? const [];
      return data
          .whereType<Map<String, dynamic>>()
          .map(Voucher.fromJson)
          .toList();
    } on DioException catch (e) {
      debugPrint('VoucherRepository.fetchActive: ${e.message}');
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/vouchers/preview { code, subtotal }
  ///
  /// Trả về [VoucherPreview] nếu OK, throw ApiException với message
  /// rõ ràng (vd "Mã đã hết hạn") nếu 422.
  Future<VoucherPreview> preview(
    String code, {
    double? subtotal,
  }) async {
    try {
      final response = await dio.post<Map<String, dynamic>>(
        '/vouchers/preview',
        data: {
          'code': code,
          if (subtotal != null) 'subtotal': subtotal,
        },
      );
      final data = response.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Mã giảm giá không hợp lệ.');
      }
      return VoucherPreview.fromJson(data);
    } on DioException catch (e) {
      debugPrint('VoucherRepository.preview: ${e.message}');
      throw apiExceptionFromDio(e);
    }
  }
}

final voucherRepositoryProvider = Provider(
  (ref) => VoucherRepository(ref.watch(dioProvider)),
);
