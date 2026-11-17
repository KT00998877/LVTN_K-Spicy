import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/reservation_local_cache.dart';
import '../data/reservation_repository.dart';
import '../domain/reservation_models.dart';
import '../domain/reservation_validator.dart';
import 'reservation_visuals.dart';

/// Lịch của tài khoản lấy từ API; khách chưa đăng nhập xem phiếu lưu trên máy.

class MyReservationsScreen extends ConsumerStatefulWidget {
  const MyReservationsScreen({super.key});

  @override
  ConsumerState<MyReservationsScreen> createState() =>
      _MyReservationsScreenState();
}

class _MyReservationsScreenState extends ConsumerState<MyReservationsScreen> {
  late Future<List<ReservationReceipt>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<ReservationReceipt>> _load() async {
    if (!ref.read(authControllerProvider).isAuthenticated) {
      final cache = await ref.read(reservationLocalCacheProvider.future);
      return cache.loadHistory();
    }
    return ref.read(reservationRepositoryProvider).fetchMine();
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() => _future = next);
    try {
      await next;
    } catch (_) {
      // FutureBuilder hiển thị lỗi để người dùng có thể thử lại.
    }
  }

  Future<void> _edit(ReservationReceipt item) async {
    ReservationOptions options;
    try {
      options = await ref.read(reservationOptionsProvider.future);
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Không tải được quy định đặt bàn. Vui lòng thử lại.')));
      return;
    }
    final guests = TextEditingController(text: item.guestCount.toString());
    final note = TextEditingController(text: item.note ?? '');
    var date = item.reservedDate;
    var time = TimeOfDay(hour: int.parse(item.reservedTime.substring(0, 2)), minute: int.parse(item.reservedTime.substring(3, 5)));
    var location = item.location == 'Tầng 2' ? 'Tầng 2' : 'Tầng 1';
    String? error;
    bool saving = false;
    if (!mounted) return;
    try {
      final saved = await showDialog<bool>(context: context, builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Sửa lịch đặt bàn'),
          content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextButton(onPressed: saving ? null : () async {
              final now = DateTime.now();
              final picked = await showDatePicker(context: context, initialDate: date, firstDate: DateTime(now.year, now.month, now.day), lastDate: now.add(const Duration(days: 365)));
              if (picked != null) setDialogState(() => date = picked);
            }, child: Text('Ngày: ${DateFormat('dd/MM/yyyy').format(date)}')),
            TextButton(onPressed: saving ? null : () async {
              final picked = await showTimePicker(context: context, initialTime: time);
              if (picked != null) setDialogState(() => time = picked);
            }, child: Text('Giờ: ${time.format(context)}')),
            TextField(controller: guests, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Số khách')),
            DropdownButtonFormField<String>(initialValue: location, decoration: const InputDecoration(labelText: 'Khu vực'), items: const [
              DropdownMenuItem(value: 'Tầng 1', child: Text('Tầng 1')),
              DropdownMenuItem(value: 'Tầng 2', child: Text('Tầng 2')),
            ], onChanged: (value) { if (value != null) setDialogState(() => location = value); }),
            TextField(controller: note, maxLines: 2, decoration: const InputDecoration(labelText: 'Ghi chú')),
            if (error != null) Text(error!, style: const TextStyle(color: AppColors.danger)),
          ])),
          actions: [
            TextButton(onPressed: saving ? null : () => Navigator.pop(dialogContext, false), child: const Text('Đóng')),
            FilledButton(onPressed: saving ? null : () async {
              final count = int.tryParse(guests.text);
              final hhmm = '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}';
              final validation = count == null ? 'Số khách không hợp lệ.' : ReservationValidator.validateGuestCount(count, options.maxGuests)
                  ?? ReservationValidator.validateDate(date, DateTime.now())
                  ?? ReservationValidator.validateTime(date: date, hhmm: hhmm, options: options, now: DateTime.now())
                  ?? ReservationValidator.validateNote(note.text, options.maxNoteLength);
              if (validation != null) { setDialogState(() => error = validation); return; }
              setDialogState(() { saving = true; error = null; });
              try {
                await ref.read(reservationRepositoryProvider).updateMine(item.id, {
                  'guest_count': count, 'reserved_date': DateFormat('yyyy-MM-dd').format(date),
                  'reserved_time': hhmm, 'location': location, 'note': note.text.trim(),
                });
                if (dialogContext.mounted) Navigator.pop(dialogContext, true);
              } catch (_) {
                if (dialogContext.mounted) setDialogState(() { saving = false; error = 'Không thể sửa lịch. Vui lòng chọn giờ khác hoặc thử lại.'; });
              }
            }, child: const Text('Lưu lịch')),
          ],
        ),
      ));
      if (saved == true) await _refresh();
    } finally {
      guests.dispose();
      note.dispose();
    }
  }

  Future<void> _cancel(ReservationReceipt item) async {
    final confirmed = await showDialog<bool>(context: context, builder: (dialogContext) => AlertDialog(
      title: const Text('Hủy đặt bàn?'),
      content: Text('Hủy lịch lúc ${item.reservedTime} ngày ${DateFormat('dd/MM/yyyy').format(item.reservedDate)}?'),
      actions: [TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('Giữ lịch')),
        TextButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('Hủy lịch'))],
    ));
    if (confirmed != true) return;
    try {
      await ref.read(reservationRepositoryProvider).cancelMine(item.id);
      await _refresh();
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Không thể hủy lịch. Vui lòng thử lại.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<AuthState>(authControllerProvider, (previous, next) {
      if (previous?.status != next.status) _refresh();
    });
    final isAuthenticated = ref.watch(authControllerProvider).isAuthenticated;
    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        title: const Text('Đơn đặt bàn của tôi'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            tooltip: 'Tải lại',
            onPressed: _refresh,
          ),
        ],
      ),
      body: RefreshIndicator(
        color: AppColors.spicyRed,
        onRefresh: _refresh,
        child: FutureBuilder<List<ReservationReceipt>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snap.hasError) {
              return Center(child: Text('Không thể tải lịch đặt bàn. Vui lòng thử lại.'));
            }
            final items = snap.data ?? const <ReservationReceipt>[];
            if (items.isEmpty) {
              return const EmptyState(
                icon: '📅',
                title: 'Chưa có đơn đặt bàn nào',
                subtitle:
                    'Bấm "Đặt bàn" ở trang chủ để giữ chỗ trước cho nhóm của bạn.',
              );
            }
            return ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.md),
              itemCount: items.length + (isAuthenticated ? 0 : 1),
              separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
              itemBuilder: (_, i) => !isAuthenticated && i == 0
                  ? const Text('Phiếu lưu trên thiết bị không tự cập nhật trạng thái. Đăng nhập trước khi đặt để theo dõi xác nhận.')
                  : _ReservationCard(receipt: items[i - (isAuthenticated ? 0 : 1)], onEdit: _edit, onCancel: _cancel),
            );
          },
        ),
      ),
    );
  }
}

class _ReservationCard extends StatelessWidget {
  const _ReservationCard({required this.receipt, required this.onEdit, required this.onCancel});
  final ReservationReceipt receipt;
  final Future<void> Function(ReservationReceipt) onEdit;
  final Future<void> Function(ReservationReceipt) onCancel;

  @override
  Widget build(BuildContext context) {
    final dateText =
        DateFormat('EEEE, dd/MM/yyyy', 'vi').format(receipt.reservedDate);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Header: mã + status ─────────────────────────
          Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.spicyRedSoft,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
                alignment: Alignment.center,
                child: const Icon(
                  Icons.event_seat_rounded,
                  color: AppColors.spicyRed,
                  size: 22,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Đặt bàn #${receipt.id}',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 14,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '$dateText · ${receipt.reservedTime}',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              ReservationStatusPill(status: receipt.status),
            ],
          ),

          const SizedBox(height: 10),
          const Divider(height: 1),
          const SizedBox(height: 10),

          // ── Info rows ─────────────────────────────────
          Row(
            children: [
              const Icon(Icons.person_outline_rounded,
                  size: 14, color: AppColors.textMuted),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  receipt.customerName,
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              const Icon(Icons.group_rounded,
                  size: 14, color: AppColors.textMuted),
              const SizedBox(width: 4),
              Text(
                '${receipt.guestCount} khách',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              const Icon(Icons.location_on_rounded,
                  size: 14, color: AppColors.textMuted),
              const SizedBox(width: 6),
              Text(
                receipt.location,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ],
          ),

          // ── Note nếu có ───────────────────────────────
          if (receipt.note != null && receipt.note!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(AppSpacing.sm),
              decoration: BoxDecoration(
                color: AppColors.creamSoft,
                borderRadius: BorderRadius.circular(AppRadius.sm),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.edit_note_rounded,
                      size: 14, color: AppColors.textMuted),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      receipt.note!,
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          if (receipt.canEdit || receipt.canCancel) Row(children: [
            if (receipt.canEdit) TextButton(onPressed: () => onEdit(receipt), child: const Text('Sửa lịch')),
            if (receipt.canCancel) TextButton(onPressed: () => onCancel(receipt), child: const Text('Hủy lịch')),
          ]),
        ],
      ),
    );
  }

}
