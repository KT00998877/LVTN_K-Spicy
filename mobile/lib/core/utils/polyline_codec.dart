import 'package:latlong2/latlong.dart';

/// ============================================================
/// 🧭 GOOGLE POLYLINE CODEC (Google's Polyline Algorithm Format)
/// ============================================================
///
/// Định dạng polyline mà Google/OSRM trả về qua `geometries=polyline`.
/// Mỗi điểm được mã hoá thành 1 chuỗi ASCII. Thuật toán:
///
///   1. Lấy toạ độ (lat, lng), nhân 1e5, làm tròn.
///   2. Tính delta so với điểm trước (lần đầu = giá trị gốc).
///   3. Chuyển sang "zigzag": dương → <<1, âm → ~(<<1).
///   4. Chia thành block 5-bit, OR với 0x20 (trừ block cuối).
///   5. Convert mỗi block sang ký tự ASCII (mã + 63).
///
/// Decode ngược lại: chuyển từng ký tự về 5-bit, gộp thành số,
/// rồi zigzag-decode và chia 1e5.
///
/// Tham khảo: https://developers.google.com/maps/documentation/utilities/polylinealgorithm
/// ============================================================
class PolylineCodec {
  PolylineCodec._();

  /// Decode 1 chuỗi polyline thành danh sách LatLng (precision 1e5).
  static List<LatLng> decode(String encoded) {
    final points = <LatLng>[];
    int index = 0;
    int lat = 0;
    int lng = 0;
    const length = 1e5;

    while (index < encoded.length) {
      // Decode latitude delta
      int shift = 0;
      int result = 0;
      int byte;
      do {
        if (index >= encoded.length) {
          throw const FormatException('Polyline thiếu ký tự kết thúc.');
        }
        byte = encoded.codeUnitAt(index++) - 63;
        result |= (byte & 0x1f) << shift;
        shift += 5;
      } while (byte >= 0x20);
      final dlat = ((result & 1) != 0 ? ~(result >> 1) : (result >> 1));
      lat += dlat;

      // Decode longitude delta
      shift = 0;
      result = 0;
      do {
        if (index >= encoded.length) {
          throw const FormatException('Polyline thiếu ký tự kết thúc.');
        }
        byte = encoded.codeUnitAt(index++) - 63;
        result |= (byte & 0x1f) << shift;
        shift += 5;
      } while (byte >= 0x20);
      final dlng = ((result & 1) != 0 ? ~(result >> 1) : (result >> 1));
      lng += dlng;

      points.add(LatLng(lat / length, lng / length));
    }

    return points;
  }
}
