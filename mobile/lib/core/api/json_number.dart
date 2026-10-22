import 'api_exception.dart';

/// Laravel money may arrive as a JSON number or a decimal string.
/// Missing/invalid prices must not silently become free items.
double jsonPrice(Object? value, String field) {
  final number = value is num
      ? value.toDouble()
      : value is String
      ? double.tryParse(value)
      : null;
  if (number == null || !number.isFinite || number < 0) {
    throw ApiException(
      message: 'Dữ liệu giá không hợp lệ ($field). Vui lòng tải lại.',
    );
  }
  return number;
}
