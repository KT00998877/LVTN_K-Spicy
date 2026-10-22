import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../domain/cart_models.dart';

/// ============================================================
/// 🛒 CART REPOSITORY — giao tiếp /api/cart
/// ============================================================
class CartRepository {
  CartRepository(this._dio);
  final Dio _dio;

  /// GET /api/cart — lấy giỏ hàng hiện tại
  Future<Cart> get() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/cart');
      final data = res.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Giỏ hàng trống');
      }
      return Cart.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/cart/count — số lượng item trong giỏ
  Future<int> getCount() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/cart/count');
      return (res.data?['data']?['count'] as num?)?.toInt() ?? 0;
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/cart — thêm món
  Future<CartItem> addItem({
    required int menuItemId,
    int quantity = 1,
    int spicyLevel = 0,
    String? note,
    List<int> toppingIds = const [],
  }) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/cart',
        data: {
          'menu_item_id': menuItemId,
          'quantity': quantity,
          'spicy_level': spicyLevel,
          if (note != null && note.isNotEmpty) 'note': note,
          if (toppingIds.isNotEmpty) 'topping_ids': toppingIds,
        },
      );
      final data = res.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Thêm món thất bại');
      }
      return CartItem.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// PUT /api/cart/items/{id} — cập nhật món
  Future<CartItem> updateItem({
    required int cartItemId,
    int? quantity,
    int? spicyLevel,
    String? note,
    List<int>? toppingIds,
  }) async {
    try {
      final res = await _dio.put<Map<String, dynamic>>(
        '/cart/items/$cartItemId',
        data: {
          if (quantity != null) 'quantity': quantity,
          if (spicyLevel != null) 'spicy_level': spicyLevel,
          if (note != null) 'note': note,
          if (toppingIds != null) 'topping_ids': toppingIds,
        },
      );
      final data = res.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Cập nhật thất bại');
      }
      return CartItem.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// DELETE /api/cart/items/{id} — xóa 1 món
  Future<void> removeItem(int cartItemId) async {
    try {
      await _dio.delete('/cart/items/$cartItemId');
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// DELETE /api/cart — xóa toàn bộ
  Future<void> clear() async {
    try {
      await _dio.delete('/cart');
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<CheckoutResult> checkoutPayload(Map<String, dynamic> payload) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/cart/checkout',
        data: payload,
      );
      return CheckoutResult.fromJson(
        (res.data!['data'] as Map).cast<String, dynamic>(),
      );
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/cart/checkout — tạo Order
  Future<CheckoutResult> checkout({
    required String orderType, // ONLINE | TAKEAWAY
    required String paymentMethod, // COD | PREPAID
    required String idempotencyKey,
    required int cartVersion,
    required String recipientName,
    required String recipientPhone,
    String? deliveryAddress,
    double? deliveryLatitude,
    double? deliveryLongitude,
    int? pointsRedeemed,
    String? voucherCode,
    String? note,
  }) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/cart/checkout',
        data: {
          'idempotency_key': idempotencyKey,
          'cart_version': cartVersion,
          'order_type': orderType,
          'payment_method': paymentMethod,
          'recipient_name': recipientName,
          'recipient_phone': recipientPhone,
          if (deliveryAddress != null) 'delivery_address': deliveryAddress,
          if (deliveryLatitude != null) 'delivery_latitude': deliveryLatitude,
          if (deliveryLongitude != null)
            'delivery_longitude': deliveryLongitude,
          if (pointsRedeemed != null && pointsRedeemed > 0)
            'points_redeemed': pointsRedeemed,
          if (voucherCode != null && voucherCode.isNotEmpty)
            'voucher_code': voucherCode,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );
      final data = res.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Đặt món thất bại');
      }
      return CheckoutResult.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }
}

class CheckoutResult {
  CheckoutResult({
    required this.orderId,
    required this.orderCode,
    required this.orderType,
    required this.paymentMethod,
  });

  final int orderId;
  final String orderCode;
  final String orderType;
  final String paymentMethod;

  factory CheckoutResult.fromJson(Map<String, dynamic> json) => CheckoutResult(
    orderId: json['order_id'] as int,
    orderCode: (json['order_code'] ?? '').toString(),
    orderType: (json['order_type'] ?? '').toString(),
    paymentMethod: (json['payment_method'] ?? '').toString(),
  );
}

final cartRepositoryProvider = Provider<CartRepository>((ref) {
  return CartRepository(ref.watch(dioProvider));
});
