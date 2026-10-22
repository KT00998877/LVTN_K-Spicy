import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/dio_client.dart';

class PaymentRepository {
  PaymentRepository(this.dio);
  final Dio dio;

  /// POST /payments/vnpay/create
  /// Platform selects the backend return URL and is persisted per transaction.
  /// Mobile returns through micay://payment/return and queries backend status.
  Future<Map<String, dynamic>> create(
    int orderId, {
    String platform = 'mobile',
  }) async {
    try {
      return (await dio.post<Map<String, dynamic>>(
        '/payments/vnpay/create',
        data: {
          'order_id': orderId,
          'platform': platform,
        },
        options: Options(
          headers: {'User-Agent': 'MicayApp/1.0 (Flutter; $platform)'},
        ),
      )).data!;
    } on DioException catch (e) {
      debugPrint('PaymentRepository.create error: ${e.message}');
      throw apiExceptionFromDio(e);
    }
  }

  Future<Map<String, dynamic>> status(String code) async {
    try {
      return (await dio.get<Map<String, dynamic>>(
        '/payments/${Uri.encodeComponent(code)}/status',
      )).data!;
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }
}

final paymentRepositoryProvider = Provider(
  (ref) => PaymentRepository(ref.watch(dioProvider)),
);