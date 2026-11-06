import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:micay_app/features/shipper/data/shipper_repository.dart';
import 'package:micay_app/features/shipper/domain/shipper_earnings.dart';
import 'package:micay_app/features/shipper/shipper_earnings_screen.dart';

void main() {
  setUpAll(() => initializeDateFormatting('vi'));

  test('earnings parse decimal strings from Laravel', () {
    final page = ShipperEarningsPage.fromJson({
      'data': {
        'data': [
          {
            'order_id': 12,
            'code': 'DH-12',
            'order_type': 'ONLINE',
            'actual_distance_km': '2.400',
            'billable_distance_km': '3.000',
            'rate_per_km': 3500,
            'amount': 10500,
            'earned_at': '2026-11-06T12:00:00+07:00',
          },
        ],
        'current_page': 1,
        'last_page': 2,
      },
      'summary': {'order_count': 2, 'total_amount': 21500},
    });

    expect(page.items.single.actualDistanceKm, 2.4);
    expect(page.items.single.billableDistanceKm, 3);
    expect(page.totalAmount, 21500);
    expect(page.lastPage, 2);
  });

  testWidgets('earnings render and filter at phone width', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final repo = _FakeEarningsRepository();

    await tester.pumpWidget(
      ProviderScope(
        overrides: [shipperRepositoryProvider.overrideWithValue(repo)],
        child: const MaterialApp(home: ShipperEarningsScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('21.500 đ'), findsOneWidget);
    expect(find.text('DH-12'), findsOneWidget);
    expect(repo.requestedPage, 1);
    expect(tester.takeException(), isNull);
    await tester.tap(find.text('Hôm nay'));
    await tester.pumpAndSettle();
    expect(repo.requestedFrom, repo.requestedTo);
    await tester.ensureVisible(find.text('Trang sau'));
    await tester.tap(find.text('Trang sau'));
    await tester.pumpAndSettle();
    expect(repo.requestedPage, 2);
    expect(tester.takeException(), isNull);
  });
}

class _FakeEarningsRepository extends ShipperRepository {
  _FakeEarningsRepository() : super(Dio());

  int? requestedPage;
  String? requestedFrom;
  String? requestedTo;

  @override
  Future<ShipperEarningsPage> fetchEarnings({
    required String from,
    required String to,
    int page = 1,
  }) async {
    requestedPage = page;
    requestedFrom = from;
    requestedTo = to;
    return ShipperEarningsPage.fromJson({
      'data': {
        'data': [
          {
            'order_id': 12,
            'code': 'DH-12',
            'order_type': 'ONLINE',
            'actual_distance_km': '2.400',
            'billable_distance_km': '3.000',
            'rate_per_km': 3500,
            'amount': 10500,
            'earned_at': '2026-11-06T12:00:00+07:00',
          },
        ],
        'current_page': page,
        'last_page': 2,
      },
      'summary': {'order_count': 2, 'total_amount': 21500},
    });
  }
}
