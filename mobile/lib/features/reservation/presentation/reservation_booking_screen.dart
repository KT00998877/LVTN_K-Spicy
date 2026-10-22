import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/utils/validators.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/reservation_local_cache.dart';
import '../data/reservation_repository.dart';
import '../domain/reservation_models.dart';
import '../domain/reservation_validator.dart';
import 'reservation_form_controller.dart';
import 'reservation_visuals.dart';

/// ============================================================
/// 📅 RESERVATION BOOKING SCREEN — đặt bàn (mobile-first)
/// ============================================================
/// Sections (theo rule restaurant-ui.mdc §12):
///   1. Số khách (1..maxGuests)
///   2. Ngày (date picker, không cho chọn quá khứ)
///   3. Giờ (time picker, ràng buộc opensAt..closesAt, min advance)
///   4. Khu vực (Tầng 1 / Tầng 2 — segmented)
///   5. Thông tin người đặt
///   6. Ghi chú (optional)
///   7. Tóm tắt + nút "Xác nhận đặt bàn"
/// ============================================================

class ReservationBookingScreen extends ConsumerStatefulWidget {
  const ReservationBookingScreen({super.key});

  /// Mở như bottom sheet (modal_first) hoặc full screen.
  /// Mobile thường dùng bottom sheet cho CTA từ Home.
  static Future<void> openAsSheet(BuildContext context) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const ReservationBookingSheet(),
    );
  }

  @override
  ConsumerState<ReservationBookingScreen> createState() =>
      _ReservationBookingScreenState();
}

class _ReservationBookingScreenState
    extends ConsumerState<ReservationBookingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();

  int _guestCount = 2;
  DateTime _selectedDate = DateTime.now().add(const Duration(days: 1));
  TimeOfDay _selectedTime = const TimeOfDay(hour: 18, minute: 0);
  String _location = 'Tầng 1';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final user = ref.read(currentUserProvider);
      _nameCtrl.text = user.name;
      _phoneCtrl.text = user.phone ?? '';
    });
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _pickDate(ReservationOptions options) async {
    final now = reservationNow(options);
    final today = DateTime(now.year, now.month, now.day);
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate.isBefore(today) ? today : _selectedDate,
      firstDate: today,
      lastDate: today.add(const Duration(days: 60)),
      helpText: 'Chọn ngày đặt bàn',
      cancelText: 'Huỷ',
      confirmText: 'Xác nhận',
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: ColorScheme.light(
              primary: AppColors.spicyRed,
              onPrimary: Colors.white,
              surface: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() => _selectedDate = picked);
    }
  }

  Future<void> _pickTime(ReservationOptions options) async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _selectedTime,
      helpText: 'Chọn giờ',
      cancelText: 'Huỷ',
      confirmText: 'Xác nhận',
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: ColorScheme.light(
              primary: AppColors.spicyRed,
              onPrimary: Colors.white,
              surface: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: MediaQuery(
            data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true),
            child: child!,
          ),
        );
      },
    );
    if (picked != null) {
      setState(() => _selectedTime = picked);
    }
  }

  Future<void> _submit(ReservationOptions options) async {
    if (!_formKey.currentState!.validate()) return;

    // Client-side validation (UX trước khi gửi)
    final hhmm =
        '${_selectedTime.hour.toString().padLeft(2, '0')}:${_selectedTime.minute.toString().padLeft(2, '0')}';
    final now = reservationNow(options);

    final guestErr = ReservationValidator.validateGuestCount(
        _guestCount, options.maxGuests);
    if (guestErr != null) {
      _showError(guestErr);
      return;
    }
    final dateErr =
        ReservationValidator.validateDate(_selectedDate, now);
    if (dateErr != null) {
      _showError(dateErr);
      return;
    }
    final timeErr = ReservationValidator.validateTime(
      date: _selectedDate,
      hhmm: hhmm,
      options: options,
      now: now,
    );
    if (timeErr != null) {
      _showError(timeErr);
      return;
    }
    final noteErr = ReservationValidator.validateNote(
        _noteCtrl.text, options.maxNoteLength);
    if (noteErr != null) {
      _showError(noteErr);
      return;
    }

    final form = ReservationForm(
      customerName: _nameCtrl.text,
      customerPhone: _phoneCtrl.text,
      guestCount: _guestCount,
      reservedDate: _selectedDate,
      reservedTime: hhmm,
      location: _location,
      note: _noteCtrl.text,
    );

    final cache = await ref.read(reservationLocalCacheProvider.future);
    final receipt = await ref
        .read(reservationFormControllerProvider.notifier)
        .submit(form: form, cache: cache);

    if (!mounted) return;
    if (receipt == null) {
      final err =
          ref.read(reservationFormControllerProvider).errorMessage ??
              'Đặt bàn thất bại';
      _showError(err);
      return;
    }

    // Hiển thị success sheet
    _showSuccess(receipt);
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: AppColors.danger,
        duration: const Duration(seconds: 3),
      ),
    );
  }

  void _showSuccess(ReservationReceipt r) {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.cream,
      isDismissible: false,
      enableDrag: false,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
      ),
      builder: (sheetCtx) => _ReservationSuccessSheet(receipt: r),
    );
  }

  @override
  Widget build(BuildContext context) {
    final asyncOptions = ref.watch(reservationOptionsProvider);
    final formState = ref.watch(reservationFormControllerProvider);

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(title: const Text('Đặt bàn')),
      body: asyncOptions.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => EmptyState(
          icon: '😢',
          title: 'Không tải được cấu hình',
          subtitle: e is ApiException
              ? e.message
              : 'Vui lòng thử lại sau',
          actionLabel: 'Thử lại',
          onAction: () =>
              ref.invalidate(reservationOptionsProvider),
        ),
        data: (options) => _buildForm(options, formState.isSubmitting),
      ),
    );
  }

  Widget _buildForm(ReservationOptions options, bool isSubmitting) {
    final dateText = DateFormat('EEEE, dd/MM/yyyy', 'vi').format(_selectedDate);
    final timeText =
        '${_selectedTime.hour.toString().padLeft(2, '0')}:${_selectedTime.minute.toString().padLeft(2, '0')}';

    return Form(
      key: _formKey,
      child: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          // ── Số khách ────────────────────────────────────
          const _SectionHeader(
              icon: Icons.group_rounded, title: 'Số khách'),
          const SizedBox(height: AppSpacing.xs),
          _Card(
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '$_guestCount khách',
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    _StepperButton(
                      icon: Icons.remove_rounded,
                      onTap: _guestCount > 1
                          ? () => setState(() => _guestCount--)
                          : null,
                    ),
                    Padding(
                      padding:
                          const EdgeInsets.symmetric(horizontal: 16),
                      child: Text(
                        '$_guestCount',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w900,
                          color: AppColors.spicyRed,
                        ),
                      ),
                    ),
                    _StepperButton(
                      icon: Icons.add_rounded,
                      onTap: _guestCount < options.maxGuests
                          ? () => setState(() => _guestCount++)
                          : null,
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  'Tối đa ${options.maxGuests} khách/lượt',
                  style: const TextStyle(
                    fontSize: 11,
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: AppSpacing.md),

          // ── Ngày + giờ ──────────────────────────────────
          const _SectionHeader(
              icon: Icons.event_rounded, title: 'Ngày & giờ'),
          const SizedBox(height: AppSpacing.xs),
          Row(
            children: [
              Expanded(
                child: _PickerField(
                  icon: Icons.calendar_today_rounded,
                  label: 'Ngày',
                  value: dateText,
                  onTap: () => _pickDate(options),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: _PickerField(
                  icon: Icons.schedule_rounded,
                  label: 'Giờ',
                  value: timeText,
                  onTap: () => _pickTime(options),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Nhà hàng mở cửa từ ${options.opensAt} đến ${options.closesAt}',
            style: const TextStyle(
              fontSize: 11,
              color: AppColors.textMuted,
              fontWeight: FontWeight.w700,
            ),
          ),

          const SizedBox(height: AppSpacing.md),

          // ── Khu vực ─────────────────────────────────────
          const _SectionHeader(
              icon: Icons.location_on_rounded, title: 'Khu vực'),
          const SizedBox(height: AppSpacing.xs),
          _LocationSelector(
            value: _location,
            onChanged: (v) => setState(() => _location = v),
          ),

          const SizedBox(height: AppSpacing.md),

          // ── Người đặt ───────────────────────────────────
          const _SectionHeader(
              icon: Icons.person_outline_rounded,
              title: 'Thông tin người đặt'),
          const SizedBox(height: AppSpacing.xs),
          _Card(
            child: Column(
              children: [
                TextFormField(
                  controller: _nameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'Họ tên',
                    prefixIcon: Icon(Icons.person_outline_rounded),
                  ),
                  validator: ReservationValidator.validateName,
                ),
                const SizedBox(height: AppSpacing.sm),
                TextFormField(
                  controller: _phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Số điện thoại',
                    prefixIcon: Icon(Icons.phone_outlined),
                    hintText: '0912345678',
                  ),
                  validator: (raw) {
                    final err = ReservationValidator.validatePhone(raw);
                    if (err != null) return err;
                    return Validators.vietnamPhone(raw);
                  },
                ),
              ],
            ),
          ),

          const SizedBox(height: AppSpacing.md),

          // ── Ghi chú ─────────────────────────────────────
          const _SectionHeader(
              icon: Icons.edit_note_rounded, title: 'Ghi chú (tuỳ chọn)'),
          const SizedBox(height: AppSpacing.xs),
          _Card(
            child: TextFormField(
              controller: _noteCtrl,
              maxLines: 3,
              maxLength: options.maxNoteLength,
              decoration: const InputDecoration(
                hintText:
                    'Có trẻ nhỏ, cần ghế cao, kỷ niệm sinh nhật...',
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
              ),
            ),
          ),

          const SizedBox(height: AppSpacing.lg),
          ElevatedButton.icon(
            onPressed: isSubmitting ? null : () => _submit(options),
            icon: isSubmitting
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: Colors.white,
                    ),
                  )
                : const Icon(Icons.check_rounded),
            label: const Text('Xác nhận đặt bàn'),
          ),
          const SizedBox(height: AppSpacing.md),
        ],
      ),
    );
  }
}

// ───────────────── Building blocks ─────────────────

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.icon, required this.title});
  final IconData icon;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: Row(
        children: [
          Icon(icon, size: 16, color: AppColors.spicyRed),
          const SizedBox(width: 6),
          Text(
            title,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: child,
    );
  }
}

class _StepperButton extends StatelessWidget {
  const _StepperButton({required this.icon, this.onTap});
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: onTap == null ? AppColors.creamSoft : AppColors.spicyRedSoft,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: SizedBox(
          width: 40,
          height: 40,
          child: Icon(
            icon,
            color: onTap == null ? AppColors.textMuted : AppColors.spicyRed,
            size: 20,
          ),
        ),
      ),
    );
  }
}

class _PickerField extends StatelessWidget {
  const _PickerField({
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });
  final IconData icon;
  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.md),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(icon, size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 4),
                  Text(
                    label,
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppColors.textMuted,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.4,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LocationSelector extends StatelessWidget {
  const _LocationSelector({required this.value, required this.onChanged});
  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: _tile(context, 'Tầng 1', Icons.stairs_rounded)),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: _tile(context, 'Tầng 2', Icons.stairs_rounded)),
      ],
    );
  }

  Widget _tile(BuildContext context, String location, IconData icon) {
    final selected = value == location;
    return GestureDetector(
      onTap: () => onChanged(location),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: selected ? AppColors.spicyRedSoft : Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(
            color: selected ? AppColors.spicyRed : AppColors.border,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Icon(icon,
                size: 18,
                color: selected
                    ? AppColors.spicyRed
                    : AppColors.textMuted),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                location,
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  color: selected
                      ? AppColors.spicyRedDark
                      : AppColors.textPrimary,
                ),
              ),
            ),
            if (selected)
              const Icon(Icons.check_circle_rounded,
                  color: AppColors.spicyRed, size: 18),
          ],
        ),
      ),
    );
  }
}

// ───────────────── Bottom sheet wrapper ─────────────────

class ReservationBookingSheet extends StatelessWidget {
  const ReservationBookingSheet({super.key});

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.92,
      minChildSize: 0.5,
      maxChildSize: 0.96,
      expand: false,
      builder: (_, scrollCtrl) {
        return Container(
          decoration: const BoxDecoration(
            color: AppColors.cream,
            borderRadius:
                BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(
                    color: AppColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              // Reuse full-screen UI trong sheet
              Expanded(
                child: Theme(
                  data: Theme.of(context),
                  child: const ReservationBookingScreen(),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

// ───────────────── Success sheet ─────────────────

class _ReservationSuccessSheet extends StatelessWidget {
  const _ReservationSuccessSheet({required this.receipt});
  final ReservationReceipt receipt;

  @override
  Widget build(BuildContext context) {
    final dateText =
        DateFormat('EEEE, dd/MM/yyyy', 'vi').format(receipt.reservedDate);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('🎉', style: TextStyle(fontSize: 72)),
            const SizedBox(height: AppSpacing.sm),
            const Text(
              'Đặt bàn thành công!',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: AppSpacing.sm),
            Container(
              padding: const EdgeInsets.all(AppSpacing.md),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(AppRadius.lg),
                border: Border.all(color: AppColors.border),
              ),
              child: Column(
                children: [
                  _Row('Mã đặt bàn', '#${receipt.id}'),
                  const SizedBox(height: 6),
                  _Row('Khách', receipt.customerName),
                  const SizedBox(height: 6),
                  _Row('Số khách', '${receipt.guestCount} người'),
                  const SizedBox(height: 6),
                  _Row('Ngày', dateText),
                  const SizedBox(height: 6),
                  _Row('Giờ', receipt.reservedTime),
                  const SizedBox(height: 6),
                  _Row('Khu vực', receipt.location),
                  const SizedBox(height: 10),
                  const Divider(),
                  const SizedBox(height: 6),
                  ReservationStatusPill(status: receipt.status),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            const Text(
              'Chúng tôi sẽ gọi xác nhận trước giờ đặt. Bạn có thể xem lại đơn đặt trong tab "Đơn đặt bàn".',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textSecondary, height: 1.5),
            ),
            const SizedBox(height: AppSpacing.lg),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                icon: const Icon(Icons.home_rounded),
                label: const Text('Về trang chủ'),
                onPressed: () {
                  Navigator.of(context).pop(); // đóng success sheet
                  Navigator.of(context).pop(); // đóng booking sheet
                },
              ),
            ),
            const SizedBox(height: AppSpacing.xs),
            SizedBox(
              width: double.infinity,
              child: TextButton(
                onPressed: () {
                  Navigator.of(context).pop();
                  Navigator.of(context).pop();
                },
                child: const Text('Đặt thêm bàn khác'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label,
            style: const TextStyle(color: AppColors.textSecondary)),
        Text(value,
            style: const TextStyle(fontWeight: FontWeight.w800)),
      ],
    );
  }
}
