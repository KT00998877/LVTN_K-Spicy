import 'package:latlong2/latlong.dart';

import '../../../core/utils/polyline_codec.dart';

/// ============================================================
/// 🚚 SHIPPER ROUTE — kết quả từ GET /api/orders/me/{id}/route
/// ============================================================
///
/// Backend trả:
/// {
///   success, data: {
///     restaurant: { name, address, latitude, longitude },
///     customer:   { name, phone, address, latitude, longitude, note },
///     route:      { polyline, distance_km, duration_min }
///   }
/// }
///
/// Mobile dùng cho customer xem đường đi của shipper trên bản đồ khi
/// đơn đang DELIVERING. Polyline decode 1 lần, cache vào bộ nhớ.
/// ============================================================

class ShipperRoute {
  ShipperRoute({
    required this.restaurant,
    required this.customer,
    required this.route,
  });

  final RouteEndpoint restaurant;
  final RouteEndpoint customer;
  final RouteData route;

  /// Polyline đã decode thành LatLng[] — cache trong bộ nhớ.
  List<LatLng>? _cachedPolyline;
  List<LatLng> get polylinePoints {
    if (_cachedPolyline != null) return _cachedPolyline!;
    if (route.polyline.isEmpty) return const [];
    try {
      _cachedPolyline = PolylineCodec.decode(route.polyline);
    } on FormatException {
      _cachedPolyline = const [];
    }
    return _cachedPolyline!;
  }

  factory ShipperRoute.fromJson(Map<String, dynamic> json) => ShipperRoute(
        restaurant:
            RouteEndpoint.fromJson(json['restaurant'] as Map<String, dynamic>),
        customer:
            RouteEndpoint.fromJson(json['customer'] as Map<String, dynamic>),
        route: RouteData.fromJson(json['route'] as Map<String, dynamic>),
      );
}

class RouteEndpoint {
  const RouteEndpoint({
    required this.name,
    required this.address,
    required this.latitude,
    required this.longitude,
    this.phone,
    this.note,
  });

  final String name;
  final String address;
  final double latitude;
  final double longitude;
  final String? phone;
  final String? note;

  LatLng get position => LatLng(latitude, longitude);

  factory RouteEndpoint.fromJson(Map<String, dynamic> json) => RouteEndpoint(
        name: json['name']?.toString() ?? '',
        address: json['address']?.toString() ?? '',
        latitude: (json['latitude'] as num?)?.toDouble() ?? 0,
        longitude: (json['longitude'] as num?)?.toDouble() ?? 0,
        phone: json['phone']?.toString(),
        note: json['note']?.toString(),
      );
}

class RouteData {
  const RouteData({
    required this.polyline,
    required this.distanceKm,
    required this.durationMin,
  });

  final String polyline;
  final double distanceKm;
  final int durationMin;

  factory RouteData.fromJson(Map<String, dynamic> json) => RouteData(
        polyline: json['polyline']?.toString() ?? '',
        distanceKm: (json['distance_km'] as num?)?.toDouble() ?? 0,
        durationMin: (json['duration_min'] as num?)?.toInt() ?? 0,
      );
}
