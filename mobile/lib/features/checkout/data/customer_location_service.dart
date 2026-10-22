import 'package:geolocator/geolocator.dart';
import '../domain/delivery_point.dart';

class CustomerLocationException implements Exception {
  const CustomerLocationException(this.message);
  final String message;
}

/// One foreground fix, requested only when the customer taps the GPS button.
class CustomerLocationService {
  Future<DeliveryPoint> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const CustomerLocationException(
        'Vui lòng bật dịch vụ vị trí/GPS rồi thử lại.',
      );
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.deniedForever) {
      throw const CustomerLocationException(
        'Quyền vị trí đã bị chặn. Mở Cài đặt của ứng dụng để cấp quyền, hoặc chọn điểm trên bản đồ.',
      );
    }
    if (permission != LocationPermission.whileInUse &&
        permission != LocationPermission.always) {
      throw const CustomerLocationException(
        'Chưa có quyền vị trí. Bạn có thể thử lại hoặc chọn điểm trên bản đồ.',
      );
    }
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );
    return DeliveryPoint(
      latitude: position.latitude,
      longitude: position.longitude,
      address: '',
    );
  }
}
