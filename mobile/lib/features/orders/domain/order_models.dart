// =============================================================
// 📦 ORDER MODELS — phản ánh response từ /api/orders/me
// Đồng bộ với OrderController.php + front-end types/orders.ts
// =============================================================

/// Trạng thái đơn hàng (đồng bộ backend)
enum OrderStatus {
  pending,
  confirmed,
  preparing,
  ready,
  readyNotifyShipper,
  delivering,
  delivered,
  served,
  completed,
  cancelled,
  unknown;

  static OrderStatus fromApi(String? raw) {
    switch ((raw ?? '').toUpperCase()) {
      case 'PENDING':
        return OrderStatus.pending;
      case 'CONFIRMED':
        return OrderStatus.confirmed;
      case 'PREPARING':
        return OrderStatus.preparing;
      case 'READY':
        return OrderStatus.ready;
      case 'READY_NOTIFY_SHIPPER':
        return OrderStatus.readyNotifyShipper;
      case 'DELIVERING':
        return OrderStatus.delivering;
      case 'DELIVERED':
        return OrderStatus.delivered;
      case 'SERVED':
        return OrderStatus.served;
      case 'COMPLETED':
        return OrderStatus.completed;
      case 'CANCELLED':
        return OrderStatus.cancelled;
      default:
        return OrderStatus.unknown;
    }
  }

  String get apiValue {
    switch (this) {
      case OrderStatus.readyNotifyShipper:
        return 'READY_NOTIFY_SHIPPER';
      default:
        return name.toUpperCase();
    }
  }
}

/// Loại đơn
enum OrderType { dineIn, takeaway, online, unknown }

OrderType parseOrderType(String? raw) {
  switch ((raw ?? '').toUpperCase()) {
    case 'DINE_IN':
      return OrderType.dineIn;
    case 'TAKEAWAY':
      return OrderType.takeaway;
    case 'ONLINE':
      return OrderType.online;
    default:
      return OrderType.unknown;
  }
}

/// Trạng thái thanh toán
enum InvoiceStatus { unpaid, pending, paid, failed, refunded, partial, unknown }

InvoiceStatus parseInvoiceStatus(String? raw) {
  switch ((raw ?? '').toUpperCase()) {
    case 'UNPAID':
      return InvoiceStatus.unpaid;
    case 'PENDING':
      return InvoiceStatus.pending;
    case 'PAID':
      return InvoiceStatus.paid;
    case 'FAILED':
      return InvoiceStatus.failed;
    case 'REFUNDED':
      return InvoiceStatus.refunded;
    case 'PARTIAL':
      return InvoiceStatus.partial;
    default:
      return InvoiceStatus.unknown;
  }
}

/// Topping trong 1 món của đơn
class OrderItemTopping {
  OrderItemTopping({
    required this.id,
    required this.name,
    required this.unitPrice,
    required this.quantity,
    required this.subtotal,
  });

  final int id;
  final String name;
  final double unitPrice;
  final int quantity;
  final double subtotal;

  factory OrderItemTopping.fromJson(Map<String, dynamic> json) =>
      OrderItemTopping(
        id: (json['id'] as num).toInt(),
        name: (json['name'] ?? '').toString(),
        unitPrice: (json['unit_price'] as num?)?.toDouble() ?? 0,
        quantity: (json['quantity'] as num?)?.toInt() ?? 1,
        subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
      );
}

/// Món trong đơn (chi tiết)
class OrderItem {
  OrderItem({
    required this.id,
    required this.menuItemId,
    required this.name,
    required this.unitPrice,
    required this.quantity,
    required this.subtotal,
    this.note,
    this.spicyLevel,
    this.toppings = const [],
  });

  final int id;
  final int menuItemId;
  final String name;
  final double unitPrice;
  final int quantity;
  final double subtotal;
  final String? note;
  final int? spicyLevel;
  final List<OrderItemTopping> toppings;

  factory OrderItem.fromJson(Map<String, dynamic> json) => OrderItem(
    id: (json['id'] as num).toInt(),
    menuItemId: (json['menu_item_id'] as num?)?.toInt() ?? 0,
    name: (json['name'] ?? '').toString(),
    unitPrice: (json['unit_price'] as num?)?.toDouble() ?? 0,
    quantity: (json['quantity'] as num?)?.toInt() ?? 1,
    subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
    note: json['note']?.toString(),
    spicyLevel: (json['spicy_level'] as num?)?.toInt(),
    toppings:
        (json['toppings'] as List?)
            ?.map(
              (t) =>
                  OrderItemTopping.fromJson((t as Map).cast<String, dynamic>()),
            )
            .toList() ??
        const [],
  );
}

/// Thông tin thanh toán (chỉ ONLINE)
class OrderPayment {
  OrderPayment({
    required this.invoiceId,
    required this.invoiceCode,
    required this.status,
    required this.total,
    required this.paid,
    required this.isSimulated,
  });

  final int invoiceId;
  final String invoiceCode;
  final InvoiceStatus status;
  final double total;
  final double paid;
  final bool isSimulated;

  factory OrderPayment.fromJson(Map<String, dynamic> json) => OrderPayment(
    invoiceId: (json['invoice_id'] as num).toInt(),
    invoiceCode: (json['invoice_code'] ?? '').toString(),
    status: parseInvoiceStatus(json['status']?.toString()),
    total: (json['total'] as num?)?.toDouble() ?? 0,
    paid: (json['paid'] as num?)?.toDouble() ?? 0,
    isSimulated: json['is_simulated'] as bool? ?? false,
  );
}

/// Sự kiện trong đời số đơn
class OrderEvent {
  OrderEvent({
    required this.event,
    required this.status,
    required this.createdAt,
    this.note,
  });

  final String event;
  final OrderStatus status;
  final String? note;
  final DateTime? createdAt;

  factory OrderEvent.fromJson(Map<String, dynamic> json) => OrderEvent(
    event: (json['event'] ?? '').toString(),
    status: OrderStatus.fromApi(json['status']?.toString()),
    note: json['note']?.toString(),
    createdAt: _parseDate(json['created_at']?.toString()),
  );

  static DateTime? _parseDate(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return DateTime.parse(raw);
    } catch (_) {
      return null;
    }
  }
}

/// Tóm tắt đơn (dùng trong list)
class OrderSummary {
  OrderSummary({
    required this.id,
    required this.code,
    required this.orderType,
    required this.status,
    required this.subtotal,
    required this.discount,
    required this.totalAmount,
    required this.itemCount,
    this.placedAt,
    this.completedAt,
    this.paymentStatus,
    this.recipientName,
    this.recipientPhone,
    this.deliveryAddress,
  });

  final int id;
  final String code;
  final OrderType orderType;
  final OrderStatus status;
  final double subtotal;
  final double discount;
  final double totalAmount;
  final int itemCount;
  final DateTime? placedAt;
  final DateTime? completedAt;
  final InvoiceStatus? paymentStatus;
  final String? recipientName;
  final String? recipientPhone;
  final String? deliveryAddress;

  factory OrderSummary.fromJson(Map<String, dynamic> json) => OrderSummary(
    id: (json['id'] as num).toInt(),
    code: (json['code'] ?? '').toString(),
    orderType: parseOrderType(json['order_type']?.toString()),
    status: OrderStatus.fromApi(json['status']?.toString()),
    subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
    discount: (json['discount'] as num?)?.toDouble() ?? 0,
    totalAmount: (json['total_amount'] as num?)?.toDouble() ?? 0,
    itemCount: (json['item_count'] as num?)?.toInt() ?? 0,
    placedAt: _parseDate(json['placed_at']?.toString()),
    completedAt: _parseDate(json['completed_at']?.toString()),
    paymentStatus: json['payment_status'] == null
        ? null
        : parseInvoiceStatus(json['payment_status'].toString()),
    recipientName: json['recipient_name']?.toString(),
    recipientPhone: json['recipient_phone']?.toString(),
    deliveryAddress: json['delivery_address']?.toString(),
  );

  static DateTime? _parseDate(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return DateTime.parse(raw);
    } catch (_) {
      return null;
    }
  }
}

/// Đầy đủ cho Order Detail
class OrderDetail {
  OrderDetail({
    required this.id,
    required this.code,
    required this.orderType,
    required this.status,
    required this.subtotal,
    required this.discount,
    required this.totalAmount,
    required this.items,
    this.note,
    this.placedAt,
    this.completedAt,
    this.recipientName,
    this.recipientPhone,
    this.deliveryAddress,
    this.shippingFee,
    this.paymentMethod,
    this.pointsRedeemed,
    this.confirmedAt,
    this.handedOverAt,
    this.pickedUpAt,
    this.deliveredAt,
    this.customerReceivedAt,
    this.pointsAwarded,
    this.cancelledAt,
    this.cancellationReason,
    this.shipperName,
    this.payment,
    this.events = const [],
  });

  final int id;
  final String code;
  final OrderType orderType;
  final OrderStatus status;
  final double subtotal;
  final double discount;
  final double totalAmount;
  final List<OrderItem> items;
  final String? note;
  final DateTime? placedAt;
  final DateTime? completedAt;
  final String? recipientName;
  final String? recipientPhone;
  final String? deliveryAddress;
  final double? shippingFee;
  final String? paymentMethod;
  final int? pointsRedeemed;
  final DateTime? confirmedAt;
  final DateTime? handedOverAt;
  final DateTime? pickedUpAt;
  final DateTime? deliveredAt;
  final DateTime? customerReceivedAt;
  final DateTime? cancelledAt;
  final String? cancellationReason;
  final int? pointsAwarded;
  final String? shipperName;
  final OrderPayment? payment;
  final List<OrderEvent> events;

  bool get isOnline => orderType == OrderType.online;
  bool get isTakeaway => orderType == OrderType.takeaway;

  /// Khách có thể xác nhận "Tôi đã nhận hàng" khi đơn DELIVERED
  bool get canConfirmReceipt => status == OrderStatus.delivered;

  /// Khách có thể tự huỷ đơn khi:
  ///  - đơn thuộc loại ONLINE / TAKEAWAY (không phải DINE_IN)
  ///  - status = PENDING (nhà hàng chưa xác nhận)
  ///  - chưa bị huỷ trước đó
  bool get canCancel =>
      (isOnline || isTakeaway) &&
      status == OrderStatus.pending;

  /// Đơn đang giao → mở màn theo dõi shipper được
  bool get canTrackShipper =>
      isOnline &&
      (status == OrderStatus.delivering ||
          status == OrderStatus.readyNotifyShipper ||
          status == OrderStatus.delivered);

  /// Đơn online đang giao → có thể xác nhận đã nhận
  bool get isInTransit =>
      isOnline &&
      (status == OrderStatus.delivering ||
          status == OrderStatus.readyNotifyShipper);

  /// Đơn online chưa thanh toán → có thể gọi simulate-payment
  bool get canPay =>
      (isOnline || isTakeaway) &&
      paymentMethod == 'PREPAID' &&
      status == OrderStatus.pending &&
      payment?.status != InvoiceStatus.paid;

  factory OrderDetail.fromJson(Map<String, dynamic> json) => OrderDetail(
    id: (json['id'] as num).toInt(),
    code: (json['code'] ?? '').toString(),
    orderType: parseOrderType(json['order_type']?.toString()),
    status: OrderStatus.fromApi(json['status']?.toString()),
    subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
    discount: (json['discount'] as num?)?.toDouble() ?? 0,
    totalAmount: (json['total_amount'] as num?)?.toDouble() ?? 0,
    items:
        (json['items'] as List?)
            ?.map((i) => OrderItem.fromJson((i as Map).cast<String, dynamic>()))
            .toList() ??
        const [],
    note: json['note']?.toString(),
    placedAt: _parseDate(json['placed_at']?.toString()),
    completedAt: _parseDate(json['completed_at']?.toString()),
    recipientName: json['recipient_name']?.toString(),
    recipientPhone: json['recipient_phone']?.toString(),
    deliveryAddress: json['delivery_address']?.toString(),
    shippingFee: (json['shipping_fee'] as num?)?.toDouble(),
    paymentMethod: json['payment_method']?.toString(),
    pointsRedeemed: (json['points_redeemed'] as num?)?.toInt(),
    confirmedAt: _parseDate(json['confirmed_at']?.toString()),
    handedOverAt: _parseDate(json['handed_over_at']?.toString()),
    pickedUpAt: _parseDate(json['picked_up_at']?.toString()),
    deliveredAt: _parseDate(json['delivered_at']?.toString()),
    customerReceivedAt: _parseDate(json['customer_received_at']?.toString()),
    pointsAwarded: (json['points_awarded'] as num?)?.toInt(),
    cancelledAt: _parseDate(json['cancelled_at']?.toString()),
    cancellationReason: json['cancellation_reason']?.toString(),
    shipperName: json['shipper_name']?.toString(),
    payment: json['payment'] == null
        ? null
        : OrderPayment.fromJson(
            (json['payment'] as Map).cast<String, dynamic>(),
          ),
    events:
        (json['events'] as List?)
            ?.map(
              (e) => OrderEvent.fromJson((e as Map).cast<String, dynamic>()),
            )
            .toList() ??
        const [],
  );

  static DateTime? _parseDate(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return DateTime.parse(raw);
    } catch (_) {
      return null;
    }
  }
}

/// Meta phân trang (Order List)
class OrderListMeta {
  OrderListMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  factory OrderListMeta.fromJson(Map<String, dynamic> json) => OrderListMeta(
    currentPage: (json['current_page'] as num?)?.toInt() ?? 1,
    lastPage: (json['last_page'] as num?)?.toInt() ?? 1,
    perPage: (json['per_page'] as num?)?.toInt() ?? 10,
    total: (json['total'] as num?)?.toInt() ?? 0,
  );
}
