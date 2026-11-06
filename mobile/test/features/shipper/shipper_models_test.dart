import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:dio/dio.dart';
import 'package:micay_app/core/api/api_exception.dart';
import 'package:micay_app/features/shipper/data/shipper_repository.dart';
import 'package:micay_app/features/shipper/domain/shipper_models.dart';
import 'package:micay_app/features/shipper/presentation/shipper_controller.dart';

void main() {
  test('delivery coordinates accept Laravel decimal strings', () {
    final order = DeliveryOrder.fromJson({
      'id': 12,
      'code': 'ON-12',
      'status': 'DELIVERING',
      'order_type': 'ONLINE',
      'payment_method': 'COD',
      'total_amount': 120000,
      'delivery_latitude': '10.0308000',
      'delivery_longitude': '105.7689000',
      'items': [],
    });

    expect(order.hasCoordinates, isTrue);
    expect(order.deliveryLatitude, 10.0308);
    expect(order.deliveryLongitude, 105.7689);
  });

  test('delivery list remains visible when stats endpoint fails', () async {
    final controller = ShipperHomeController(_StatsFailingRepository());
    addTearDown(controller.dispose);
    await controller.refresh();

    expect(controller.currentState.orders, hasLength(1));
    expect(controller.currentState.stats, isNull);
    expect(controller.currentState.hasLoadedOnce, isTrue);
    expect(controller.currentState.errorMessage, 'Không tải được thống kê.');
  });

  test('orders appear before a slow stats request finishes', () async {
    final repo = _SlowStatsRepository();
    final controller = ShipperHomeController(repo);
    addTearDown(controller.dispose);

    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(controller.currentState.orders, hasLength(1));
    expect(controller.currentState.isLoading, isFalse);
    expect(controller.currentState.stats, isNull);
    repo.finishStats();
  });
}

class _StatsFailingRepository extends ShipperRepository {
  _StatsFailingRepository() : super(Dio());

  @override
  Future<List<DeliveryOrder>> fetchDeliveries() async => [
    DeliveryOrder.fromJson({
      'id': 12,
      'code': 'ON-12',
      'status': 'DELIVERING',
      'order_type': 'ONLINE',
      'payment_method': 'COD',
      'total_amount': 120000,
      'items': [],
    }),
  ];

  @override
  Future<ShipperStats> fetchStats() async =>
      throw const ApiException(message: 'Không tải được thống kê.');

  @override
  Future<List<NearbyJob>> fetchNearbyJobs() async => [];
}

class _SlowStatsRepository extends _StatsFailingRepository {
  final Completer<ShipperStats> _stats = Completer<ShipperStats>();

  @override
  Future<ShipperStats> fetchStats() => _stats.future;

  void finishStats() => _stats.complete(
    const ShipperStats(
      delivering: 1,
      awaiting: 0,
      completedToday: 0,
      codCollectedToday: 0,
    ),
  );
}
