import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/auth/domain/app_user.dart';
import 'package:micay_app/features/auth/presentation/auth_controller.dart';
import 'package:micay_app/features/shipper/data/shipper_repository.dart';
import 'package:micay_app/features/shipper/domain/shipper_models.dart';
import 'package:micay_app/features/shipper/presentation/shipper_controller.dart';
import 'package:micay_app/features/shipper/shipper_home_screen.dart';

void main() {
  testWidgets('shipper screen lays out at phone width', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _LayoutRepository();
    await tester.pumpWidget(ProviderScope(overrides: [
      currentUserProvider.overrideWithValue(AppUser(
        id: 1, name: 'Shipper', email: 'shipper@example.test', role: UserRole.shipper)),
      shipperRepositoryProvider.overrideWithValue(repo),
      shipperHomeControllerProvider.overrideWith((ref) => ShipperHomeController(repo)),
    ], child: const MaterialApp(home: ShipperHomeScreen())));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Đang giao'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}

class _LayoutRepository extends ShipperRepository {
  _LayoutRepository() : super(Dio());

  @override
  Future<List<DeliveryOrder>> fetchDeliveries() async => [
    DeliveryOrder.fromJson({
      'id': 1, 'code': 'DH-001', 'status': 'DELIVERING',
      'order_type': 'ONLINE', 'payment_method': 'COD',
      'total_amount': 120000, 'items': [],
    }),
  ];

  @override
  Future<ShipperStats> fetchStats() async => const ShipperStats(
    delivering: 0, awaiting: 0, completedToday: 0, codCollectedToday: 0);

  @override
  Future<List<NearbyJob>> fetchNearbyJobs() async => [];
}
