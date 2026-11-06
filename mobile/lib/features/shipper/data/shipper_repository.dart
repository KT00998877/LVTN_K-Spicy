import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../../orders/domain/shipper_route.dart';
import '../domain/shipper_models.dart';
import '../domain/shipper_earnings.dart';

/// ============================================================
/// 🚚 SHIPPER REPOSITORY — giao tiếp /api/staff/deliveries/*
/// ============================================================
/// Tất cả endpoint yêu cầu Bearer token (staff SHIPPER đã đăng nhập).
///
/// Theo route list Laravel:
///   - GET    /api/staff/deliveries                  (list đơn của shipper)
///   - POST   /api/staff/deliveries/{id}/pickup
///   - POST   /api/staff/deliveries/{id}/arrived
///   - POST   /api/staff/deliveries/{id}/complete
///   - POST   /api/staff/deliveries/{id}/issue
///   - GET    /api/staff/deliveries/{id}/route       (chỉ đường)
///   - GET    /api/staff/deliveries/stats            (thống kê cá nhân)
/// ============================================================

class ShipperRepository {
  ShipperRepository(this._dio);
  final Dio _dio;

  Future<ShipperEarningsPage> fetchEarnings({
    required String from,
    required String to,
    int page = 1,
  }) async {
    try {
      final response = await _dio.get<Map<String, dynamic>>(
        '/staff/deliveries/earnings',
        queryParameters: {'from': from, 'to': to, 'page': page},
      );
      return ShipperEarningsPage.fromJson(response.data ?? const {});
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<List<NearbyJob>> fetchNearbyJobs() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/staff/deliveries/nearby-jobs',
      );
      return ((res.data?['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => NearbyJob.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<void> accept(int orderId) async {
    try {
      await _dio.post('/staff/deliveries/$orderId/accept');
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<void> updateLocation(
    double latitude,
    double longitude,
    double accuracy,
  ) async {
    try {
      await _dio.post(
        '/staff/deliveries/location',
        data: {
          'latitude': latitude,
          'longitude': longitude,
          'accuracy': accuracy,
        },
      );
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/staff/deliveries
  Future<List<DeliveryOrder>> fetchDeliveries() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/staff/deliveries');
      final body = res.data ?? const {};
      final list = (body['data'] as List?) ?? const [];
      return list
          .whereType<Map>()
          .map((e) => DeliveryOrder.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// POST /api/staff/online-orders/{id}/pickup
  Future<DeliveryOrder> pickup(int orderId) async {
    return _postDetail(orderId, '/pickup');
  }

  /// POST /api/staff/online-orders/{id}/arrived
  Future<DeliveryOrder> arrived(int orderId) async {
    return _postDetail(orderId, '/arrived');
  }

  /// POST /api/staff/online-orders/{id}/complete
  Future<DeliveryOrder> complete(int orderId) async {
    return _postDetail(orderId, '/complete');
  }

  /// POST /api/staff/deliveries/{id}/issue
  Future<DeliveryOrder> reportIssue(int orderId, String reason) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/staff/deliveries/$orderId/issue',
        data: {'reason': reason},
      );
      return _parseDetail(res.data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/staff/deliveries/{id}/route
  /// Shipper xem chỉ đường từ quán → khách (chỉ ONLINE có toạ độ).
  Future<ShipperRoute> fetchRoute(int orderId) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/staff/deliveries/$orderId/route',
      );
      final body = res.data ?? const {};
      final data = body['data'];
      if (data is! Map) {
        throw const ApiException(message: 'Không lấy được đường đi.');
      }
      return ShipperRoute.fromJson(Map<String, dynamic>.from(data));
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/staff/deliveries/stats
  Future<ShipperStats> fetchStats() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '/staff/deliveries/stats',
      );
      final body = res.data ?? const {};
      final data = body['data'];
      if (data is! Map) {
        throw const ApiException(message: 'Không tải được thống kê.');
      }
      return ShipperStats.fromJson(Map<String, dynamic>.from(data));
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  Future<DeliveryOrder> _postDetail(int orderId, String action) async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/staff/deliveries/$orderId$action',
      );
      return _parseDetail(res.data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  DeliveryOrder _parseDetail(Map<String, dynamic>? body) {
    final data = body?['data'];
    if (data is! Map) {
      throw const ApiException(message: 'Phản hồi từ máy chủ không hợp lệ.');
    }
    return DeliveryOrder.fromJson(Map<String, dynamic>.from(data));
  }
}

final shipperRepositoryProvider = Provider<ShipperRepository>((ref) {
  return ShipperRepository(ref.watch(dioProvider));
});
