import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/reservation_local_cache.dart';
import '../data/reservation_repository.dart';
import '../domain/reservation_models.dart';
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
                  : _ReservationCard(receipt: items[i - (isAuthenticated ? 0 : 1)]),
            );
          },
        ),
      ),
    );
  }
}

class _ReservationCard extends StatelessWidget {
  const _ReservationCard({required this.receipt});
  final ReservationReceipt receipt;

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
          if (_note != null) ...[
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
                      _note!,
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
        ],
      ),
    );
  }

  String? get _note {
    // ReservationReceipt không có note field — để null cho tới khi backend expose
    return null;
  }
}
