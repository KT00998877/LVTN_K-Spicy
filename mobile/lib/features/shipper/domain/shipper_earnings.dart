class ShipperEarning {
  const ShipperEarning({
    required this.orderId,
    required this.code,
    required this.orderType,
    required this.actualDistanceKm,
    required this.billableDistanceKm,
    required this.ratePerKm,
    required this.amount,
    required this.earnedAt,
  });

  final int orderId;
  final String code;
  final String orderType;
  final double? actualDistanceKm;
  final double billableDistanceKm;
  final int ratePerKm;
  final int amount;
  final DateTime earnedAt;

  factory ShipperEarning.fromJson(Map<String, dynamic> json) => ShipperEarning(
    orderId: _number(json['order_id']).toInt(),
    code: (json['code'] ?? '').toString(),
    orderType: (json['order_type'] ?? '').toString(),
    actualDistanceKm: json['actual_distance_km'] == null
        ? null
        : _number(json['actual_distance_km']).toDouble(),
    billableDistanceKm: _number(json['billable_distance_km']).toDouble(),
    ratePerKm: _number(json['rate_per_km']).toInt(),
    amount: _number(json['amount']).toInt(),
    earnedAt: DateTime.parse(json['earned_at'].toString()).toLocal(),
  );
}

class ShipperEarningsPage {
  const ShipperEarningsPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.orderCount,
    required this.totalAmount,
  });

  final List<ShipperEarning> items;
  final int currentPage;
  final int lastPage;
  final int orderCount;
  final int totalAmount;

  factory ShipperEarningsPage.fromJson(Map<String, dynamic> json) {
    final page = Map<String, dynamic>.from(json['data'] as Map);
    final summary = Map<String, dynamic>.from(json['summary'] as Map);
    return ShipperEarningsPage(
      items: ((page['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => ShipperEarning.fromJson(Map<String, dynamic>.from(row)))
          .toList(),
      currentPage: _number(page['current_page']).toInt(),
      lastPage: _number(page['last_page']).toInt(),
      orderCount: _number(summary['order_count']).toInt(),
      totalAmount: _number(summary['total_amount']).toInt(),
    );
  }
}

num _number(Object? value) =>
    value is num ? value : num.parse(value.toString());
