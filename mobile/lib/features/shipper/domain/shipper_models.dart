import 'package:intl/intl.dart';

import '../../orders/domain/order_models.dart';

/// ============================================================
/// 🚚 SHIPPER DOMAIN MODELS
/// ============================================================
/// Phản ánh response từ các endpoint:
///   - GET /api/staff/online-orders/deliveries  (danh sách đơn của shipper)
///   - GET /api/staff/deliveries/{id}/route     (chỉ đường — đã có ShipperRoute)
///   - GET /api/staff/deliveries/stats          (thống kê cá nhân)
/// ============================================================

final _vndFormatter = NumberFormat('#,##0', 'vi_VN');

String _formatVnd(num amount) => '${_vndFormatter.format(amount.round())} đ';

/// Thống kê cá nhân cho shipper
class ShipperStats {
  const ShipperStats({
    required this.delivering,
    required this.awaiting,
    required this.completedToday,
    required this.codCollectedToday,
  });

  final int delivering;
  final int awaiting;
  final int completedToday;
  final double codCollectedToday;

  String get codCollectedTodayLabel => _formatVnd(codCollectedToday);

  factory ShipperStats.fromJson(Map<String, dynamic> json) {
    return ShipperStats(
      delivering: (json['delivering'] as num?)?.toInt() ?? 0,
      awaiting: (json['awaiting'] as num?)?.toInt() ?? 0,
      completedToday: (json['completed_today'] as num?)?.toInt() ?? 0,
      codCollectedToday:
          (json['cod_collected_today'] as num?)?.toDouble() ?? 0.0,
    );
  }
}

/// Đơn của shipper — dùng cho tab list + cards
class DeliveryOrder {
  DeliveryOrder({
    required this.id,
    required this.code,
    required this.status,
    required this.orderType,
    required this.paymentMethod,
    required this.totalAmount,
    required this.items,
    required this.placedAt,
    required this.handedOverAt,
    required this.pickedUpAt,
    required this.arrivedAt,
    required this.deliveredAt,
    required this.completedAt,
    required this.customerName,
    required this.customerPhone,
    required this.deliveryAddress,
    required this.deliveryLatitude,
    required this.deliveryLongitude,
    required this.note,
    required this.deliveryIssue,
    required this.payment,
  });

  final int id;
  final String code;
  final OrderStatus status;
  final OrderType orderType;
  final String paymentMethod; // 'COD' | 'PREPAID'
  final double totalAmount;
  final List<DeliveryOrderItem> items;
  final DateTime? placedAt;
  final DateTime? handedOverAt;
  final DateTime? pickedUpAt;
  final DateTime? arrivedAt;
  final DateTime? deliveredAt;
  final DateTime? completedAt;

  // Người nhận (recipient cho đơn giao + tên khách cho đơn mang đi)
  final String? customerName;
  final String? customerPhone;
  final String? deliveryAddress;
  final double? deliveryLatitude;
  final double? deliveryLongitude;

  final String? note;
  final String? deliveryIssue;

  /// Hóa đơn kèm trạng thái (do service presentation() của OnlineOrderService)
  final DeliveryPayment? payment;

  String get totalLabel => _formatVnd(totalAmount);

  bool get isOnline => orderType == OrderType.online;
  bool get isTakeaway => orderType == OrderType.takeaway;

  /// Có toạ độ để mở map
  bool get hasCoordinates =>
      deliveryLatitude != null && deliveryLongitude != null;

  factory DeliveryOrder.fromJson(Map<String, dynamic> json) {
    final items = ((json['items'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DeliveryOrderItem.fromJson(Map<String, dynamic>.from(e)))
        .where((it) => it.status != OrderStatus.cancelled)
        .toList();

    final paymentJson = json['payment'];
    final payment = paymentJson is Map
        ? DeliveryPayment.fromJson(Map<String, dynamic>.from(paymentJson))
        : null;

    return DeliveryOrder(
      id: (json['id'] as num?)?.toInt() ?? 0,
      code: (json['code'] ?? '').toString(),
      status: OrderStatus.fromApi(json['status']?.toString()),
      orderType: parseOrderType(json['order_type']?.toString()),
      paymentMethod: (json['payment_method'] ?? '').toString(),
      totalAmount: (json['total_amount'] as num?)?.toDouble() ?? 0,
      items: items,
      placedAt: _parseDate(json['placed_at']),
      handedOverAt: _parseDate(json['handed_over_at']),
      pickedUpAt: _parseDate(json['picked_up_at']),
      arrivedAt: _parseDate(json['arrived_at']),
      deliveredAt: _parseDate(json['delivered_at']),
      completedAt: _parseDate(json['completed_at']),
      customerName: _asString(json['recipient_name']),
      customerPhone: _asString(json['recipient_phone']),
      deliveryAddress: _asString(json['delivery_address']),
      deliveryLatitude: (json['delivery_latitude'] as num?)?.toDouble(),
      deliveryLongitude: (json['delivery_longitude'] as num?)?.toDouble(),
      note: _asString(json['note']),
      deliveryIssue: _asString(json['delivery_issue']),
      payment: payment,
    );
  }
}

class DeliveryOrderItem {
  DeliveryOrderItem({
    required this.id,
    required this.name,
    required this.quantity,
    required this.spicyLevel,
    required this.status,
  });

  final int id;
  final String name;
  final int quantity;
  final int? spicyLevel;
  final OrderStatus status;

  factory DeliveryOrderItem.fromJson(Map<String, dynamic> json) {
    return DeliveryOrderItem(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: (json['menu_item_name'] ?? '').toString(),
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      spicyLevel: (json['spicy_level'] as num?)?.toInt(),
      status: OrderStatus.fromApi(json['status']?.toString()),
    );
  }
}

class DeliveryPayment {
  DeliveryPayment({
    required this.invoiceId,
    required this.invoiceCode,
    required this.status,
    required this.total,
    required this.paid,
    required this.isSimulated,
  });

  final int invoiceId;
  final String? invoiceCode;
  final String status; // PAID/UNPAID/...
  final double total;
  final double paid;
  final bool isSimulated;

  bool get isPaid => status == 'PAID';

  factory DeliveryPayment.fromJson(Map<String, dynamic> json) {
    return DeliveryPayment(
      invoiceId: (json['invoice_id'] as num?)?.toInt() ?? 0,
      invoiceCode: json['invoice_code']?.toString(),
      status: (json['status'] ?? '').toString(),
      total: (json['total'] as num?)?.toDouble() ?? 0,
      paid: (json['paid'] as num?)?.toDouble() ?? 0,
      isSimulated: json['is_simulated'] == true,
    );
  }
}

DateTime? _parseDate(Object? raw) {
  if (raw == null) return null;
  final s = raw.toString();
  if (s.isEmpty) return null;
  try {
    return DateTime.parse(s).toLocal();
  } catch (_) {
    return null;
  }
}

String? _asString(Object? raw) {
  if (raw == null) return null;
  final s = raw.toString();
  return s.isEmpty ? null : s;
}
