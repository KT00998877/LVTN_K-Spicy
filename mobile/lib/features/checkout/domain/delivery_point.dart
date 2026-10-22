// =============================================================
// 📍 DELIVERY POINT MODEL — kết quả từ map picker
// =============================================================

import 'package:latlong2/latlong.dart';

class DeliveryPoint {
  const DeliveryPoint({
    required this.latitude,
    required this.longitude,
    required this.address,
  });

  final double latitude;
  final double longitude;
  final String address;
  LatLng get latLng => LatLng(latitude, longitude);
}

/// Nhà hàng mặc định (đã biết từ config/checkout.php + .env).
/// Dùng để center map khi user mở picker lần đầu.
class RestaurantLocation {
  static const double latitude = 10.0308;
  static const double longitude = 105.7831;
  static const String name = 'Mì Cay Hàn Quốc';
  static const String address =
      'Đại học Cần Thơ, Khu II, đường 3/2, Ninh Kiều, Cần Thơ';
}
