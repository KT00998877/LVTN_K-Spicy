import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/dio_client.dart';

class CheckoutQuote {
  CheckoutQuote(this.json);
  final Map<String, dynamic> json;
  double amount(String key) => (json[key] as num?)?.toDouble() ?? 0;
  int get maxPoints => (json['max_points'] as num?)?.toInt() ?? 0;
  int get balance => (json['balance'] as num?)?.toInt() ?? 0;
  String? get voucherError => json['voucher_error'] as String?;
}

class CheckoutRepository {
  CheckoutRepository(this.dio);
  final Dio dio;

  Future<CheckoutQuote> quote(Map<String, dynamic> input) async {
    try {
      final response = await dio.post<Map<String, dynamic>>(
        '/checkout/quote',
        data: input,
      );
      return CheckoutQuote(response.data!);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<Map<String, dynamic>> config() async {
    try {
      return (await dio.publicGet<Map<String, dynamic>>(
        '/checkout/config',
      )).data!;
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }
}

final checkoutRepositoryProvider = Provider(
  (ref) => CheckoutRepository(ref.watch(dioProvider)),
);
