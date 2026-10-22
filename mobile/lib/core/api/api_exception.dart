/// ============================================================
/// ❌ API EXCEPTION — wrapper có cấu trúc cho lỗi từ server
/// ============================================================
///
/// Tất cả Repository phải throw [ApiException] khi server trả
/// lỗi, để UI có thể hiển thị message tiếng Việt rõ ràng.
///
class ApiException implements Exception {
  const ApiException({
    required this.message,
    this.statusCode,
    this.errors,
    this.path,
  });

  /// Message tiếng Việt để hiển thị cho user
  final String message;

  /// HTTP status code (401, 403, 422, 500…)
  final int? statusCode;

  /// Validation errors từ Laravel `errors` (field → list msg)
  final Map<String, List<String>>? errors;

  /// Endpoint xảy ra lỗi (debug)
  final String? path;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isValidation => statusCode == 422;
  bool get isServerError => statusCode != null && statusCode! >= 500;

  @override
  String toString() =>
      'ApiException($statusCode, $path): $message'
      '${errors != null ? ' | errors=$errors' : ''}';
}
