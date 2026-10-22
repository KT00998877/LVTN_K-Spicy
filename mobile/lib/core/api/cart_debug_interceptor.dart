import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

/// Cart diagnostics only. Never log bearer tokens or checkout contact details.
class CartDebugInterceptor extends Interceptor {
  bool _matches(RequestOptions request) =>
      kDebugMode &&
      (request.path == '/cart' ||
          request.path.startsWith('/cart/items/') ||
          request.path == '/cart/toppings');

  Object? _redact(Object? value) {
    if (value is Map) {
      return value.map(
        (key, entry) => MapEntry(
          key.toString(),
          key == 'note' ? '[redacted]' : _redact(entry),
        ),
      );
    }
    if (value is List) return value.map(_redact).toList();
    return value;
  }

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    if (_matches(options)) {
      debugPrint(
        '[Cart API] --> ${options.method} ${options.uri} '
        'Accept=${options.headers['Accept']} Content-Type=${options.contentType} '
        'authenticated=${options.headers.containsKey('Authorization')} '
        'body=${jsonEncode(_redact(options.data))}',
      );
    }
    handler.next(options);
  }

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    if (_matches(response.requestOptions)) {
      debugPrint(
        '[Cart API] <-- ${response.statusCode} '
        '${response.requestOptions.method} ${response.requestOptions.uri} '
        'body=${jsonEncode(_redact(response.data))}',
      );
    }
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (_matches(err.requestOptions)) {
      debugPrint(
        '[Cart API] ERROR ${err.type.name} ${err.response?.statusCode} '
        '${err.requestOptions.method} ${err.requestOptions.uri} '
        'body=${jsonEncode(_redact(err.response?.data))}',
      );
    }
    handler.next(err);
  }
}
