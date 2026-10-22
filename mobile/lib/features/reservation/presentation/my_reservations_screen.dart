import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../data/reservation_local_cache.dart';
import '../domain/reservation_models.dart';
import 'reservation_visuals.dart';

/// ============================================================
/// 📅 MY RESERVATIONS — đơn đặt bàn của tôi (local cache)
/// ============================================================
/// Backend không có endpoint /api/reservations/me cho customer,
/// nên ta hiển thị các đơn đã cache trong SharedPreferences
/// (tối đa 5 đơn gần nhất).
///
/// Đây là giải pháp tạm thời; khi backend bổ sung endpoint
/// customer-scoped, có thể thay bằng FutureProvider gọi API.
/// ============================================================

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
    final cache = await ref.read(reservationLocalCacheProvider.future);
    return cache.loadHistory();
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() => _future = next);
    await next;
  }

  @override
  Widget build(BuildContext context) {
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
              itemCount: items.length,
              separatorBuilder: (_, __) =>
                  const SizedBox(height: AppSpacing.sm),
              itemBuilder: (_, i) => _ReservationCard(receipt: items[i]),
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

          const SizedBox(height: 10),
          Row(
            children: [
              const Spacer(),
              Text(
                'Tổng ước tính',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(width: 6),
              Text(
                MoneyVnd.format(estimateTotal(receipt)),
                style: const TextStyle(
                  color: AppColors.spicyRed,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String? get _note {
    // ReservationReceipt không có note field — để null cho tới khi backend expose
    return null;
  }
}

/// Ước tính tổng tiền dựa trên số khách (avg 80k/người).
/// Đây là placeholder, backend không trả price cho reservation.
double estimateTotal(ReservationReceipt r) => r.guestCount * 80000;
