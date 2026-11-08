import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../domain/order_models.dart';
import '../domain/shipper_route.dart';

/// ============================================================
/// 📦 ORDER REPOSITORY — giao tiếp /api/orders/me
/// ============================================================
/// Tất cả endpoint yêu cầu Bearer token (customer đã đăng nhập).
/// ============================================================

class OrderRepository {
  OrderRepository(this._dio);
  final Dio _dio;

  /// GET /api/orders/me — danh sách đơn của customer
  Future<OrderListResult> list({
    OrderStatus? status,
    int page = 1,
    int perPage = 20,
  }) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/orders/me',
        queryParameters: {
          if (status != null) 'status': status.apiValue,
          'page': page,
          'per_page': perPage,
        },
      );
      final body = res.data ?? const {};
      final data = (body['data'] as List?) ?? [];
      final meta = body['meta'] is Map
          ? OrderListMeta.fromJson(
              (body['meta'] as Map).cast<String, dynamic>(),
            )
          : OrderListMeta(
              currentPage: 1,
              lastPage: 1,
              perPage: perPage,
              total: data.length,
            );

      final items = data
          .map((e) => OrderSummary.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
      return OrderListResult(items: items, meta: meta);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/orders/me/{id} — chi tiết đơn
  Future<OrderDetail> getById(int id) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/orders/me/$id');
      final body = res.data ?? const {};
      final data = body['data'] as Map?;
      if (data == null) {
        throw const ApiException(message: 'Không tìm thấy đơn hàng');
      }
      return OrderDetail.fromJson(data.cast<String, dynamic>());
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<ReorderResult> reorder(int id) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/orders/me/$id/reorder',
      );
      final data = (res.data?['data'] as Map?)?.cast<String, dynamic>();
      if (data == null) {
        throw const ApiException(message: 'Không thể đặt lại đơn hàng.');
      }
      return ReorderResult.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/orders/me/{id}/confirm-receipt — xác nhận đã nhận hàng
  Future<ConfirmReceiptResult> confirmReceipt(int id) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/orders/me/$id/confirm-receipt',
      );
      final body = res.data ?? const {};
      final data = (body['data'] as Map?)?.cast<String, dynamic>();
      if (data == null) {
        throw const ApiException(message: 'Xác nhận thất bại');
      }
      return ConfirmReceiptResult(
        order: OrderDetail.fromJson(data),
        loyaltyPoints: (body['loyalty_points'] as num?)?.toInt() ?? 0,
        pointsAwarded: (body['points_awarded'] as num?)?.toInt() ?? 0,
      );
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/orders/me/{id}/route — lấy polyline đường đi shipper
  /// từ nhà hàng → khách. Chỉ áp dụng cho đơn ONLINE có delivery_*
  Future<ShipperRoute> getRoute(int id) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/orders/me/$id/route');
      final body = res.data ?? const {};
      final data = body['data'] as Map?;
      if (data == null) {
        throw const ApiException(message: 'Không lấy được đường đi.');
      }
      return ShipperRoute.fromJson(data.cast<String, dynamic>());
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/orders/me/{id}/cancel — khách tự huỷ đơn (PENDING only)
  Future<OrderDetail> cancel(int id, String reason) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/orders/me/$id/cancel',
        data: {'reason': reason},
      );
      final body = res.data ?? const {};
      final data = (body['data'] as Map?)?.cast<String, dynamic>();
      if (data == null) {
        throw const ApiException(message: 'Huỷ đơn thất bại.');
      }
      return OrderDetail.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }
}

class OrderListResult {
  OrderListResult({required this.items, required this.meta});
  final List<OrderSummary> items;
  final OrderListMeta meta;
}

class ReorderResult {
  ReorderResult({
    required this.added,
    required this.skipped,
    required this.priceChanges,
  });
  final List<String> added;
  final List<ReorderSkipped> skipped;
  final List<ReorderPriceChange> priceChanges;

  factory ReorderResult.fromJson(Map<String, dynamic> json) => ReorderResult(
    added: (json['added'] as List? ?? [])
        .map((item) => item.toString())
        .toList(),
    skipped: (json['skipped'] as List? ?? [])
        .map(
          (item) =>
              ReorderSkipped.fromJson((item as Map).cast<String, dynamic>()),
        )
        .toList(),
    priceChanges: (json['price_changes'] as List? ?? [])
        .map(
          (item) => ReorderPriceChange.fromJson(
            (item as Map).cast<String, dynamic>(),
          ),
        )
        .toList(),
  );
}

class ReorderSkipped {
  ReorderSkipped({required this.name, required this.reason});
  final String name;
  final String reason;

  factory ReorderSkipped.fromJson(Map<String, dynamic> json) => ReorderSkipped(
    name: json['name'] as String? ?? '',
    reason: json['reason'] as String? ?? '',
  );
}

class ReorderPriceChange {
  ReorderPriceChange({
    required this.name,
    required this.oldPrice,
    required this.newPrice,
  });
  final String name;
  final double oldPrice;
  final double newPrice;

  factory ReorderPriceChange.fromJson(Map<String, dynamic> json) =>
      ReorderPriceChange(
        name: json['name'] as String? ?? '',
        oldPrice: (json['old_price'] as num).toDouble(),
        newPrice: (json['new_price'] as num).toDouble(),
      );
}

class ConfirmReceiptResult {
  ConfirmReceiptResult({
    required this.order,
    required this.loyaltyPoints,
    required this.pointsAwarded,
  });
  final OrderDetail order;
  final int loyaltyPoints;
  final int pointsAwarded;
}

final orderRepositoryProvider = Provider<OrderRepository>((ref) {
  return OrderRepository(ref.watch(dioProvider));
});
