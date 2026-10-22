import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ============================================================
/// 🔗 DEEP LINK SERVICE — nhận VNPay return từ browser về app
/// ============================================================
///
/// Luồng:
/// 1. Create a payment with platform=mobile in the request body.
/// 2. VNPay returns to the configured mobile backend URL.
/// 3. Laravel verifies the result and uses persisted platform metadata.
/// 4. The backend redirects to micay://payment/return?code=...&order_id=...
/// 5. app_links forwards the URI to the router; the app queries backend status.
///
/// Stream này là single source of truth cho deep link: cả cold-start
/// (app đã đóng) và warm-start (app đang chạy) đều chui qua đây.
class DeepLinkService {
  DeepLinkService() {
    _init();
  }

  final AppLinks _appLinks = AppLinks();
  final StreamController<Uri> _controller =
      StreamController<Uri>.broadcast();

  /// Cold-start URI (nếu app được mở từ link khi đang đóng).
  Uri? initialUri;

  Stream<Uri> get stream => _controller.stream;

  Future<void> _init() async {
    try {
      // 1. Cold-start: app mở từ deep link
      initialUri = await _appLinks.getInitialLink();
      if (initialUri != null) {
        _controller.add(initialUri!);
      }
      // 2. Warm-start: app đang chạy, nhận link mới
      _appLinks.uriLinkStream.listen((uri) {
        _controller.add(uri);
      }, onError: (e) {
        debugPrint('DeepLinkService error: $e');
      });
    } catch (e) {
      debugPrint('DeepLinkService init failed: $e');
    }
  }
}

final deepLinkServiceProvider = Provider<DeepLinkService>((ref) {
  final service = DeepLinkService();
  ref.onDispose(service._controller.close);
  return service;
});

/// ============================================================
/// 📦 PAYMENT DEEP LINK PAYLOAD — model cho link VNPay return
/// ============================================================
class PaymentReturnPayload {
  const PaymentReturnPayload({
    required this.paymentCode,
    required this.orderId,
  });

  final String paymentCode;
  final int orderId;

  factory PaymentReturnPayload.fromUri(Uri uri) {
    final code = uri.queryParameters['code'] ?? '';
    final orderId =
        int.tryParse(uri.queryParameters['order_id'] ?? '') ?? 0;
    return PaymentReturnPayload(paymentCode: code, orderId: orderId);
  }

  bool get isValid => paymentCode.isNotEmpty && orderId > 0;
}