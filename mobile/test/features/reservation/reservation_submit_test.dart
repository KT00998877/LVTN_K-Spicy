import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/reservation/data/reservation_local_cache.dart';
import 'package:micay_app/features/reservation/data/reservation_repository.dart';
import 'package:micay_app/features/reservation/domain/reservation_models.dart';
import 'package:micay_app/features/reservation/presentation/reservation_form_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _SuccessfulRepository extends ReservationRepository {
  _SuccessfulRepository() : super(Dio());

  @override
  Future<ReservationReceipt> create(ReservationForm form) async =>
      ReservationReceipt(
        id: 42,
        customerName: form.customerName,
        guestCount: form.guestCount,
        reservedDate: form.reservedDate,
        reservedTime: form.reservedTime,
        status: ReservationStatus.pending,
      );
}

class _FailingCache extends ReservationLocalCache {
  _FailingCache(super.prefs);

  @override
  Future<void> saveReceipt(ReservationReceipt receipt) async {
    throw StateError('storage unavailable');
  }
}

void main() {
  test('đặt bàn thành công dù lưu lịch sử tại máy thất bại', () async {
    SharedPreferences.setMockInitialValues({});
    final cache = _FailingCache(await SharedPreferences.getInstance());
    final controller = ReservationFormController(_SuccessfulRepository());
    final receipt = await controller.submit(
      form: ReservationForm(
        customerName: 'Nguyễn An',
        customerPhone: '0912345678',
        guestCount: 2,
        reservedDate: DateTime(2026, 11, 2),
        reservedTime: '18:00',
      ),
      cache: cache,
    );

    expect(receipt?.id, 42);
    expect(controller.state.errorMessage, isNull);
    expect(controller.state.isSubmitting, false);
    controller.dispose();
  });
}
