import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../data/reservation_local_cache.dart';
import '../data/reservation_repository.dart';
import '../domain/reservation_models.dart';

/// ============================================================
/// 📅 RESERVATION FORM CONTROLLER — state cho form đặt bàn
/// ============================================================
/// Quản lý: form input + loading + error + lastReceipt
/// Sau khi submit thành công → save vào local cache để user
/// có thể xem "đơn vừa đặt" qua tab "Đơn đặt bàn".
/// ============================================================

class ReservationFormState {
  ReservationFormState({
    this.isSubmitting = false,
    this.errorMessage,
    this.lastReceipt,
  });

  final bool isSubmitting;
  final String? errorMessage;
  final ReservationReceipt? lastReceipt;

  ReservationFormState copyWith({
    bool? isSubmitting,
    String? errorMessage,
    ReservationReceipt? lastReceipt,
    bool clearError = false,
    bool clearReceipt = false,
  }) =>
      ReservationFormState(
        isSubmitting: isSubmitting ?? this.isSubmitting,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        lastReceipt:
            clearReceipt ? null : (lastReceipt ?? this.lastReceipt),
      );
}

class ReservationFormController extends StateNotifier<ReservationFormState> {
  ReservationFormController(this._repo) : super(ReservationFormState());

  final ReservationRepository _repo;

  /// Submit form. Trả về Receipt nếu thành công, null nếu thất bại.
  Future<ReservationReceipt?> submit({
    required ReservationForm form,
    ReservationLocalCache? cache,
  }) async {
    state = state.copyWith(isSubmitting: true, clearError: true);
    try {
      // Idempotency key
      final finalForm = ReservationForm(
        customerName: form.customerName,
        customerPhone: form.customerPhone,
        guestCount: form.guestCount,
        reservedDate: form.reservedDate,
        reservedTime: form.reservedTime,
        location: form.location,
        note: form.note,
        requestKey: form.requestKey ?? const Uuid().v4(),
      );

      final receipt = await _repo.create(finalForm);

      // Cache local (best-effort)
      if (cache != null) {
        await cache.saveReceipt(receipt);
      }

      state = state.copyWith(isSubmitting: false, lastReceipt: receipt);
      return receipt;
    } on ApiException catch (e) {
      state = state.copyWith(isSubmitting: false, errorMessage: e.message);
      return null;
    } catch (e) {
      state = state.copyWith(
          isSubmitting: false,
          errorMessage: 'Đặt bàn thất bại. Vui lòng thử lại.');
      return null;
    }
  }

  void clearError() => state = state.copyWith(clearError: true);
  void reset() => state = ReservationFormState();
}

final reservationFormControllerProvider =
    StateNotifierProvider<ReservationFormController, ReservationFormState>((ref) {
  return ReservationFormController(ref.watch(reservationRepositoryProvider));
});
