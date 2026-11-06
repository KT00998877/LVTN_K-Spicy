import 'dart:async' show Timer;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:geolocator/geolocator.dart';

import '../../app/theme.dart';
import '../auth/presentation/auth_controller.dart';
import '../orders/domain/order_models.dart';
import 'domain/shipper_models.dart';
import 'data/shipper_repository.dart';
import 'presentation/shipper_controller.dart';

/// ============================================================
/// 🛵 SHIPPER HOME — màn chính cho nhân viên giao hàng
/// ============================================================
/// Mirror ShipperHomeView.vue (web):
///   • Stats card: Đang giao / Chờ XN / Hoàn tất hôm nay / COD hôm nay
///   • 3 tab: Đang giao · Chờ khách xác nhận · Hoàn tất
///   • Card đơn: mã, status pill, type badge, payment pill,
///     timeline 3 bước, thông tin người nhận, danh sách món,
///     payment summary, action buttons theo trạng thái.
///   • Bottom sheet "Báo sự cố" + route sang map "Xem đường đi"
/// ============================================================

class ShipperHomeScreen extends ConsumerStatefulWidget {
  const ShipperHomeScreen({super.key});

  @override
  ConsumerState<ShipperHomeScreen> createState() => _ShipperHomeScreenState();
}

class _ShipperHomeScreenState extends ConsumerState<ShipperHomeScreen> {
  ShipperTab _tab = ShipperTab.delivering;
  Timer? _autoRefreshTimer;
  Timer? _locationTimer;
  String _locationStatus = 'Đang lấy vị trí…';
  bool _sendingLocation = false;

  @override
  void initState() {
    super.initState();
    // Auto-refresh mỗi 30s (giảm tải mạng, vẫn đủ real-time cho shipper).
    _autoRefreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!mounted) return;
      if (ref.read(shipperHomeControllerProvider).busyId == null) {
        ref.read(shipperHomeControllerProvider.notifier).refresh(quiet: true);
      }
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _updateLocation());
    _locationTimer = Timer.periodic(
      const Duration(seconds: 60),
      (_) => _updateLocation(),
    );
  }

  @override
  void dispose() {
    _autoRefreshTimer?.cancel();
    _locationTimer?.cancel();
    super.dispose();
  }

  Future<void> _onPullRefresh() async {
    await ref.read(shipperHomeControllerProvider.notifier).refresh(quiet: true);
  }

  Future<void> _onManualRefresh() async {
    await ref.read(shipperHomeControllerProvider.notifier).refresh();
    await _updateLocation();
  }

  Future<void> _updateLocation() async {
    if (!mounted || _sendingLocation) return;
    setState(() => _sendingLocation = true);
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw Exception('Bật GPS để nhận đơn gần nhất.');
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission != LocationPermission.whileInUse &&
          permission != LocationPermission.always) {
        throw Exception('Cấp quyền vị trí để nhận đơn gần nhất.');
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
      await ref
          .read(shipperRepositoryProvider)
          .updateLocation(
            position.latitude,
            position.longitude,
            position.accuracy,
          );
      if (mounted) setState(() => _locationStatus = 'Đang chia sẻ vị trí');
    } catch (e) {
      if (mounted) {
        setState(
          () => _locationStatus = e.toString().replaceFirst('Exception: ', ''),
        );
      }
    } finally {
      if (mounted) setState(() => _sendingLocation = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(shipperHomeControllerProvider);
    final user = ref.watch(currentUserProvider);

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Shipper'),
            Text(
              'Xin chào, ${user.name}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Làm mới',
            icon: state.isLoading
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: AppColors.spicyRed,
                    ),
                  )
                : const Icon(Icons.refresh_rounded),
            onPressed: state.isLoading ? null : _onManualRefresh,
          ),
          IconButton(
            tooltip: 'Đăng xuất',
            icon: const Icon(Icons.logout_rounded),
            onPressed: () => ref.read(authControllerProvider.notifier).logout(),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: AppColors.spicyRed,
        onRefresh: _onPullRefresh,
        child: _buildBody(context, state),
      ),
    );
  }

  Widget _buildBody(BuildContext context, ShipperHomeState state) {
    final notifier = ref.read(shipperHomeControllerProvider.notifier);
    return ListView(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.xl,
      ),
      children: [
        _StatsBar(stats: state.stats),
        const SizedBox(height: AppSpacing.md),
        Row(
          children: [
            const Icon(
              Icons.location_on_outlined,
              size: 18,
              color: AppColors.spicyRed,
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                _locationStatus,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            TextButton(
              onPressed: _sendingLocation ? null : _updateLocation,
              child: Text(_sendingLocation ? 'Đang gửi…' : 'Cập nhật vị trí'),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        _TabBar(
          current: _tab,
          counts: (
            nearby: state.nearbyJobs.length,
            assigned: state.assignedOrders.length,
            delivering: state.deliveringOrders.length,
            awaiting: state.awaitingOrders.length,
            completed: state.completedOrders.length,
          ),
          onChanged: (tab) => setState(() => _tab = tab),
        ),
        const SizedBox(height: AppSpacing.sm),
        if (state.errorMessage != null) ...[
          _ErrorBanner(message: state.errorMessage!, onRetry: _onManualRefresh),
          const SizedBox(height: AppSpacing.sm),
        ],
        if (state.lastRefreshedAt != null)
          Padding(
            padding: const EdgeInsets.only(left: AppSpacing.xxs),
            child: Text(
              'Cập nhật lúc ${_formatTime(state.lastRefreshedAt!)}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        const SizedBox(height: AppSpacing.sm),
        ..._buildTabContent(state, notifier),
      ],
    );
  }

  List<Widget> _buildTabContent(
    ShipperHomeState state,
    ShipperHomeController notifier,
  ) {
    final isFirstLoad = !state.hasLoadedOnce && state.isLoading;
    if (isFirstLoad && _tab != ShipperTab.nearby) {
      return [
        const Padding(
          padding: EdgeInsets.symmetric(vertical: AppSpacing.xl),
          child: Center(child: CircularProgressIndicator()),
        ),
      ];
    }
    final List<DeliveryOrder> list;
    final String emptyTitle;
    final String emptyMessage;
    list = switch (_tab) {
      ShipperTab.nearby => const <DeliveryOrder>[],
      ShipperTab.assigned => state.assignedOrders,
      ShipperTab.delivering => state.deliveringOrders,
      ShipperTab.awaiting => state.awaitingOrders,
      ShipperTab.completed => state.completedOrders,
    };
    switch (_tab) {
      case ShipperTab.nearby:
        return [
          if (!state.nearbyLoaded)
            const Center(
              child: Padding(
                padding: EdgeInsets.all(AppSpacing.xl),
                child: CircularProgressIndicator(),
              ),
            ),
          for (final job in state.nearbyJobs)
            _NearbyJobCard(job: job, notifier: notifier),
          if (state.nearbyLoaded && state.nearbyJobs.isEmpty)
            const _EmptyState(
              title: 'Chưa có đơn gần bạn',
              message: 'Đơn mới sẽ hiện tại đây khi có thể nhận.',
            ),
        ];
      case ShipperTab.assigned:
        emptyTitle = 'Chưa có đơn được phân công';
        emptyMessage = 'Đơn được quản lý giao cho bạn sẽ hiện tại đây.';
        break;
      case ShipperTab.delivering:
        emptyTitle = 'Chưa có đơn đang giao';
        emptyMessage =
            'Chờ quản lý / thu ngân bàn giao đơn cho bạn. Đơn mới sẽ tự hiển thị ở đây.';
        break;
      case ShipperTab.awaiting:
        emptyTitle = 'Chưa có đơn chờ khách xác nhận';
        emptyMessage =
            'Các đơn bạn đã giao sẽ xuất hiện ở đây và chờ khách bấm "Tôi đã nhận hàng".';
        break;
      case ShipperTab.completed:
        emptyTitle = 'Chưa có đơn hoàn tất';
        emptyMessage =
            'Các đơn khách đã xác nhận nhận hàng sẽ xuất hiện ở đây.';
        break;
    }
    if (list.isEmpty) {
      return [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xl),
          child: _EmptyState(title: emptyTitle, message: emptyMessage),
        ),
      ];
    }
    return [
      for (final order in list) ...[
        _DeliveryCard(order: order, notifier: notifier),
        const SizedBox(height: AppSpacing.sm),
      ],
    ];
  }
}

// ─────────────────────── STATS ───────────────────────

class _StatsBar extends StatelessWidget {
  const _StatsBar({required this.stats});
  final ShipperStats? stats;

  @override
  Widget build(BuildContext context) {
    final s = stats;
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = (constraints.maxWidth - AppSpacing.xs) / 2;
        return Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          children: [
            SizedBox(
              width: width,
              child: _StatCard(
                label: 'Đang giao',
                value: s == null ? '—' : '${s.delivering}',
                accent: AppColors.spicyRed,
                icon: Icons.delivery_dining_rounded,
              ),
            ),
            SizedBox(
              width: width,
              child: _StatCard(
                label: 'Chờ XN',
                value: s == null ? '—' : '${s.awaiting}',
                accent: AppColors.chiliOrange,
                icon: Icons.hourglass_top_rounded,
              ),
            ),
            SizedBox(
              width: width,
              child: _StatCard(
                label: 'Hoàn tất',
                value: s == null ? '—' : '${s.completedToday}',
                accent: AppColors.success,
                icon: Icons.check_circle_rounded,
              ),
            ),
            SizedBox(
              width: width,
              child: _StatCard(
                label: 'COD hôm nay',
                value: s == null ? '—' : _shortMoney(s.codCollectedToday),
                accent: AppColors.goldenYellow,
                icon: Icons.payments_rounded,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.label,
    required this.value,
    required this.accent,
    required this.icon,
  });

  final String label;
  final String value;
  final Color accent;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.card,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: accent, size: 18),
          const SizedBox(height: AppSpacing.xxs),
          Text(
            value,
            style: const TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              letterSpacing: -0.3,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          Text(
            label,
            style: const TextStyle(
              fontSize: 11,
              color: AppColors.textSecondary,
              fontWeight: FontWeight.w600,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
  }
}

// ─────────────────────── TABS ───────────────────────

class _TabBar extends StatelessWidget {
  const _TabBar({
    required this.current,
    required this.counts,
    required this.onChanged,
  });

  final ShipperTab current;
  final ({
    int nearby,
    int assigned,
    int delivering,
    int awaiting,
    int completed,
  })
  counts;
  final ValueChanged<ShipperTab> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: AppColors.border),
      ),
      padding: const EdgeInsets.all(AppSpacing.xxs),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            _tab(context, ShipperTab.nearby, 'Đơn gần nhất', counts.nearby),
            _tab(
              context,
              ShipperTab.assigned,
              'Được phân công',
              counts.assigned,
            ),
            _tab(
              context,
              ShipperTab.delivering,
              'Đang giao',
              counts.delivering,
            ),
            _tab(context, ShipperTab.awaiting, 'Chờ XN', counts.awaiting),
            _tab(context, ShipperTab.completed, 'Hoàn tất', counts.completed),
          ],
        ),
      ),
    );
  }

  Widget _tab(BuildContext context, ShipperTab tab, String label, int count) {
    final selected = current == tab;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => onChanged(tab),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(
          vertical: AppSpacing.sm,
          horizontal: AppSpacing.sm,
        ),
        decoration: BoxDecoration(
          color: selected ? AppColors.spicyRed : Colors.transparent,
          borderRadius: BorderRadius.circular(AppRadius.pill),
        ),
        alignment: Alignment.center,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              label,
              style: TextStyle(
                fontWeight: FontWeight.w700,
                fontSize: 13,
                color: selected
                    ? AppColors.textOnDark
                    : AppColors.textSecondary,
              ),
            ),
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
              decoration: BoxDecoration(
                color: selected
                    ? Colors.white.withValues(alpha: 0.22)
                    : AppColors.creamSoft,
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
              child: Text(
                '$count',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: selected
                      ? AppColors.textOnDark
                      : AppColors.textPrimary,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────── DELIVERY CARD ───────────────────────

class _NearbyJobCard extends StatelessWidget {
  const _NearbyJobCard({required this.job, required this.notifier});
  final NearbyJob job;
  final ShipperHomeController notifier;

  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
    child: Padding(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(job.code, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            '${job.recipientName ?? 'Khách hàng'} — ${job.deliveryAddress ?? 'Chưa có địa chỉ'}',
          ),
          const SizedBox(height: 6),
          Text(
            '${_shortMoney(job.totalAmount)} · ${job.paymentMethod == 'COD' ? 'Thu COD' : 'Đã thanh toán'}'
            '${job.distanceKm == null ? '' : ' · cách ${job.distanceKm!.toStringAsFixed(2)} km'}',
          ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton(
            onPressed: notifier.currentState.busyId != null
                ? null
                : () async {
                    final ok = await notifier.accept(job.id);
                    if (!context.mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          ok
                              ? 'Đã nhận đơn ${job.code}.'
                              : notifier.currentState.errorMessage ??
                                    'Không nhận được đơn.',
                        ),
                      ),
                    );
                  },
            child: const Text('Nhận đơn'),
          ),
        ],
      ),
    ),
  );
}

class _DeliveryCard extends ConsumerWidget {
  const _DeliveryCard({required this.order, required this.notifier});

  final DeliveryOrder order;
  final ShipperHomeController notifier;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.card,
      ),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _CardHeader(order: order),
            const SizedBox(height: AppSpacing.sm),
            if (order.status == OrderStatus.delivering) ...[
              _StepTimeline(order: order),
              const SizedBox(height: AppSpacing.sm),
            ] else if (order.status == OrderStatus.delivered) ...[
              const _AwaitingBanner(),
              const SizedBox(height: AppSpacing.sm),
            ],
            _RecipientSection(order: order),
            const SizedBox(height: AppSpacing.sm),
            _ItemsSection(order: order),
            const SizedBox(height: AppSpacing.sm),
            _PaymentSection(order: order),
            if (order.deliveryIssue != null) ...[
              const SizedBox(height: AppSpacing.sm),
              _IssueBanner(message: order.deliveryIssue!),
            ],
            const SizedBox(height: AppSpacing.sm),
            _MetaRow(order: order),
            const SizedBox(height: AppSpacing.sm),
            _Actions(order: order, notifier: notifier),
          ],
        ),
      ),
    );
  }
}

class _CardHeader extends StatelessWidget {
  const _CardHeader({required this.order});
  final DeliveryOrder order;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Wrap(
            spacing: AppSpacing.xxs,
            runSpacing: AppSpacing.xxs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                order.code,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 15,
                  color: AppColors.textPrimary,
                ),
              ),
              _Pill(
                text: _statusLabel(order.status),
                color: _statusColor(order.status),
              ),
              _Pill(
                text: order.isTakeaway ? 'MANG ĐI' : 'GIAO TẬN NƠI',
                color: order.isTakeaway ? AppColors.warning : AppColors.info,
              ),
              _Pill(
                text: order.paymentMethod == 'COD' ? 'COD' : 'ĐÃ THANH TOÁN',
                color: order.paymentMethod == 'COD'
                    ? AppColors.chiliOrange
                    : AppColors.mutedGreen,
              ),
              if (order.deliveryIssue != null)
                const _Pill(text: 'SỰ CỐ', color: AppColors.warning),
            ],
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Text(
          order.totalLabel,
          style: const TextStyle(
            fontSize: 17,
            fontWeight: FontWeight.w800,
            color: AppColors.spicyRed,
          ),
        ),
      ],
    );
  }
}

class _StepTimeline extends StatelessWidget {
  const _StepTimeline({required this.order});
  final DeliveryOrder order;

  @override
  Widget build(BuildContext context) {
    final steps = _buildSteps();
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          for (int i = 0; i < steps.length; i++) ...[
            if (i > 0)
              Expanded(
                child: Container(
                  height: 2,
                  margin: const EdgeInsets.only(top: 18),
                  color: steps[i].done ? AppColors.spicyRed : AppColors.border,
                ),
              ),
            _StepNode(step: steps[i]),
          ],
        ],
      ),
    );
  }

  List<_Step> _buildSteps() {
    final pickedUp = order.pickedUpAt != null;
    final arrived = order.arrivedAt != null;
    final delivered = order.deliveredAt != null;
    return [
      _Step(
        label: 'Lấy món',
        time: order.pickedUpAt,
        done: pickedUp,
        active: !pickedUp,
      ),
      _Step(
        label: order.isOnline ? 'Tới nơi' : 'Mang đi',
        time: order.arrivedAt,
        done: arrived || (order.isTakeaway && pickedUp),
        active: pickedUp && !arrived && order.isOnline,
        disabled: !pickedUp && order.isOnline,
      ),
      _Step(
        label: 'Giao xong',
        time: order.deliveredAt,
        done: delivered,
        active:
            (pickedUp && order.isTakeaway && !delivered) ||
            (arrived && !delivered),
      ),
    ];
  }
}

class _Step {
  _Step({
    required this.label,
    required this.time,
    required this.done,
    required this.active,
    this.disabled = false,
  });
  final String label;
  final DateTime? time;
  final bool done;
  final bool active;
  final bool disabled;
}

class _StepNode extends StatelessWidget {
  const _StepNode({required this.step});
  final _Step step;

  @override
  Widget build(BuildContext context) {
    final color = step.done
        ? AppColors.spicyRed
        : step.active
        ? AppColors.spicyRed
        : AppColors.border;
    final fg = step.done || step.active
        ? AppColors.textOnDark
        : AppColors.textMuted;
    final stepNumber = step.label == 'Lấy món'
        ? '1'
        : step.label == 'Tới nơi' || step.label == 'Mang đi'
        ? '2'
        : '3';
    return SizedBox(
      width: 76,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: step.done
                  ? AppColors.spicyRed
                  : step.active
                  ? Colors.white
                  : AppColors.creamSoft,
              shape: BoxShape.circle,
              border: Border.all(
                color: step.disabled ? AppColors.border : color,
                width: step.active ? 2 : 0,
              ),
            ),
            alignment: Alignment.center,
            child: step.done
                ? const Icon(Icons.check_rounded, color: Colors.white, size: 18)
                : Text(
                    stepNumber,
                    style: TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 13,
                      color: fg,
                    ),
                  ),
          ),
          const SizedBox(height: 4),
          Text(
            step.label,
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: step.disabled
                  ? AppColors.textMuted
                  : AppColors.textPrimary,
            ),
          ),
          SizedBox(
            height: 12,
            child: step.time != null
                ? Text(
                    _formatTime(step.time!),
                    style: const TextStyle(
                      fontSize: 10,
                      color: AppColors.textMuted,
                    ),
                  )
                : null,
          ),
        ],
      ),
    );
  }
}

class _AwaitingBanner extends StatelessWidget {
  const _AwaitingBanner();
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: const Color(0xFFECFEFF),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: const Color(0xFFA5F3FC)),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Đã giao xong · chờ khách xác nhận',
            style: TextStyle(
              fontWeight: FontWeight.w800,
              color: Color(0xFF0E7490),
              fontSize: 13,
            ),
          ),
          SizedBox(height: 2),
          Text(
            'Đơn hoàn tất khi khách bấm "Tôi đã nhận hàng". Nếu khách không xác nhận, đơn vẫn ở trạng thái này và không được tích điểm.',
            style: TextStyle(
              fontSize: 12,
              color: Color(0xFF155E75),
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }
}

class _RecipientSection extends StatelessWidget {
  const _RecipientSection({required this.order});
  final DeliveryOrder order;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionLabel(text: 'Người nhận'),
        const SizedBox(height: AppSpacing.xxs),
        Row(
          children: [
            Expanded(
              child: Text(
                order.customerName ?? '—',
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
            if (order.customerPhone != null && order.customerPhone!.isNotEmpty)
              _CallButton(phone: order.customerPhone!),
          ],
        ),
        if (order.isOnline) ...[
          const SizedBox(height: 2),
          Text(
            order.deliveryAddress ?? '—',
            style: const TextStyle(fontSize: 13, color: AppColors.textPrimary),
          ),
          if (order.hasCoordinates) ...[
            const SizedBox(height: AppSpacing.xs),
            Wrap(
              spacing: AppSpacing.xxs,
              runSpacing: AppSpacing.xxs,
              children: [
                _GhostChip(
                  icon: Icons.map_rounded,
                  label: 'Xem đường đi',
                  onTap: () => _openMap(context),
                ),
                _GhostChip(
                  icon: Icons.directions_car_rounded,
                  label: 'Mở Google Maps',
                  onTap: () => _openExternalMap(context),
                ),
              ],
            ),
          ],
        ] else ...[
          const SizedBox(height: 2),
          const Text(
            'Khách sẽ đến quán nhận — không cần giao tận nơi.',
            style: TextStyle(
              fontSize: 12,
              fontStyle: FontStyle.italic,
              color: AppColors.textMuted,
            ),
          ),
        ],
        if (order.note != null && order.note!.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.xs),
          Container(
            padding: const EdgeInsets.all(AppSpacing.xs),
            decoration: BoxDecoration(
              color: const Color(0xFFFFFBEB),
              borderRadius: BorderRadius.circular(AppRadius.sm),
              border: Border.all(color: const Color(0xFFFDE68A)),
            ),
            child: Text(
              'Ghi chú khách: ${order.note}',
              style: const TextStyle(fontSize: 12, color: Color(0xFF78350F)),
            ),
          ),
        ],
      ],
    );
  }

  void _openMap(BuildContext context) {
    context.push('/orders/${order.id}/route');
  }

  Future<void> _openExternalMap(BuildContext context) async {
    if (!order.hasCoordinates) return;
    final lat = order.deliveryLatitude;
    final lng = order.deliveryLongitude;
    final uri = Uri.parse(
      'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving',
    );
    final messenger = ScaffoldMessenger.of(context);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      messenger.showSnackBar(
        const SnackBar(content: Text('Không mở được Google Maps.')),
      );
    }
  }
}

class _CallButton extends StatelessWidget {
  const _CallButton({required this.phone});
  final String phone;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.spicyRedSoft,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        onTap: () async {
          final uri = Uri(scheme: 'tel', path: phone);
          final messenger = ScaffoldMessenger.of(context);
          if (await canLaunchUrl(uri)) {
            await launchUrl(uri);
          } else {
            messenger.showSnackBar(
              SnackBar(content: Text('Không gọi được $phone')),
            );
          }
        },
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: AppSpacing.xxs,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.phone_rounded,
                size: 14,
                color: AppColors.spicyRed,
              ),
              const SizedBox(width: 4),
              Text(
                phone,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.spicyRed,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ItemsSection extends StatelessWidget {
  const _ItemsSection({required this.order});
  final DeliveryOrder order;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _SectionLabel(text: 'Món trong đơn (${order.items.length})'),
        const SizedBox(height: AppSpacing.xxs),
        ...order.items.map(
          (it) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: [
                Expanded(
                  child: Wrap(
                    crossAxisAlignment: WrapCrossAlignment.center,
                    spacing: AppSpacing.xxs,
                    children: [
                      Text(
                        it.name,
                        style: const TextStyle(
                          fontSize: 13,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      if (it.spicyLevel != null && it.spicyLevel! > 0)
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 5,
                            vertical: 1,
                          ),
                          decoration: BoxDecoration(
                            color: AppColors.spicyRedSoft,
                            borderRadius: BorderRadius.circular(AppRadius.xs),
                          ),
                          child: Text(
                            'Cấp ${it.spicyLevel}',
                            style: const TextStyle(
                              fontSize: 10,
                              fontWeight: FontWeight.w700,
                              color: AppColors.spicyRed,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                Text(
                  '× ${it.quantity}',
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _PaymentSection extends StatelessWidget {
  const _PaymentSection({required this.order});
  final DeliveryOrder order;

  @override
  Widget build(BuildContext context) {
    final p = order.payment;
    final statusText = p == null
        ? '—'
        : p.isPaid
        ? 'Đã thanh toán'
        : 'Chưa thanh toán';
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Row(
        children: [
          Expanded(
            child: Row(
              children: [
                const Text(
                  'Thanh toán:',
                  style: TextStyle(
                    fontSize: 13,
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(width: 4),
                Text(
                  statusText,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    color: p?.isPaid == true
                        ? AppColors.success
                        : AppColors.warning,
                  ),
                ),
              ],
            ),
          ),
          Text(
            order.totalLabel,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
            ),
          ),
        ],
      ),
    );
  }
}

class _IssueBanner extends StatelessWidget {
  const _IssueBanner({required this.message});
  final String message;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: const Color(0xFFFECACA)),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.warning_amber_rounded,
            color: AppColors.danger,
            size: 18,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              'Sự cố: $message',
              style: const TextStyle(fontSize: 12, color: AppColors.danger),
            ),
          ),
        ],
      ),
    );
  }
}

class _MetaRow extends StatelessWidget {
  const _MetaRow({required this.order});
  final DeliveryOrder order;
  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: AppSpacing.sm,
      runSpacing: 2,
      children: [
        Text(
          'Bàn giao: ${_formatDateTime(order.handedOverAt)}',
          style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
        ),
        if (order.deliveredAt != null)
          Text(
            'Giao lúc: ${_formatDateTime(order.deliveredAt)}',
            style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
          ),
      ],
    );
  }
}

class _Actions extends StatelessWidget {
  const _Actions({required this.order, required this.notifier});
  final DeliveryOrder order;
  final ShipperHomeController notifier;

  @override
  Widget build(BuildContext context) {
    if (order.status == OrderStatus.delivering) {
      return _DeliveringActions(order: order, notifier: notifier);
    }
    if (order.status == OrderStatus.delivered) {
      return Container(
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.sm,
        ),
        decoration: BoxDecoration(
          color: const Color(0xFFD1FAE5),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: const Text(
          'Chờ khách bấm "Tôi đã nhận hàng" để hoàn tất',
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: AppColors.success,
          ),
        ),
      );
    }
    if (order.status == OrderStatus.completed) {
      return Container(
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.sm,
        ),
        decoration: BoxDecoration(
          color: AppColors.creamSoft,
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: Text(
          order.completedAt == null
              ? 'Đã hoàn tất'
              : 'Hoàn tất lúc ${_formatDateTime(order.completedAt)}',
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: AppColors.textSecondary,
          ),
        ),
      );
    }
    return const SizedBox.shrink();
  }
}

class _DeliveringActions extends StatelessWidget {
  const _DeliveringActions({required this.order, required this.notifier});
  final DeliveryOrder order;
  final ShipperHomeController notifier;

  @override
  Widget build(BuildContext context) {
    final pickedUp = order.pickedUpAt != null;
    final arrived = order.arrivedAt != null;
    final busy = notifier.currentState.busyId == order.id;

    final _PrimaryAction action = _resolveAction(pickedUp, arrived);

    return Row(
      children: [
        Expanded(
          child: _GhostButton(
            label: 'Báo sự cố',
            icon: Icons.report_problem_rounded,
            loading: busy,
            onPressed: busy
                ? null
                : () => _openIssueSheet(context, order, notifier),
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          flex: 2,
          child: _PrimaryButton(
            label: action.label,
            icon: action.icon,
            loading: busy,
            onPressed: busy
                ? null
                : () => _runAction(context, action, notifier),
          ),
        ),
      ],
    );
  }

  _PrimaryAction _resolveAction(bool pickedUp, bool arrived) {
    if (!pickedUp) {
      return const _PrimaryAction(
        label: 'Đã lấy món từ bếp',
        icon: Icons.shopping_basket_rounded,
        kind: _ActionKind.pickup,
      );
    }
    if (!arrived && order.isOnline) {
      return const _PrimaryAction(
        label: 'Đã tới điểm giao',
        icon: Icons.location_on_rounded,
        kind: _ActionKind.arrived,
      );
    }
    return _PrimaryAction(
      label: order.paymentMethod == 'COD'
          ? 'Thu COD và giao xong'
          : 'Đã giao xong (chờ khách XN)',
      icon: Icons.check_circle_rounded,
      kind: _ActionKind.complete,
    );
  }

  Future<void> _runAction(
    BuildContext context,
    _PrimaryAction action,
    ShipperHomeController notifier,
  ) async {
    final ok = switch (action.kind) {
      _ActionKind.pickup => await notifier.pickup(order.id),
      _ActionKind.arrived => await notifier.arrived(order.id),
      _ActionKind.complete => await notifier.complete(order.id),
    };
    if (!context.mounted) return;
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(_successMessage(action)),
          duration: const Duration(seconds: 2),
        ),
      );
    } else if (notifier.currentState.errorMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(notifier.currentState.errorMessage!)),
      );
    }
  }

  String _successMessage(_PrimaryAction action) {
    switch (action.kind) {
      case _ActionKind.pickup:
        return 'Đã xác nhận lấy món từ bếp.';
      case _ActionKind.arrived:
        return 'Đã xác nhận đã tới điểm giao.';
      case _ActionKind.complete:
        return action.label.contains('COD')
            ? 'Đã thu COD và xác nhận giao xong.'
            : 'Đã giao xong. Đang chờ khách xác nhận.';
    }
  }

  void _openIssueSheet(
    BuildContext context,
    DeliveryOrder order,
    ShipperHomeController notifier,
  ) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.lg)),
      ),
      builder: (ctx) => _IssueSheet(order: order, notifier: notifier),
    );
  }
}

enum _ActionKind { pickup, arrived, complete }

class _PrimaryAction {
  const _PrimaryAction({
    required this.label,
    required this.icon,
    required this.kind,
  });
  final String label;
  final IconData icon;
  final _ActionKind kind;
}

// ─────────────────────── ISSUE SHEET ───────────────────────

class _IssueSheet extends StatefulWidget {
  const _IssueSheet({required this.order, required this.notifier});
  final DeliveryOrder order;
  final ShipperHomeController notifier;

  @override
  State<_IssueSheet> createState() => _IssueSheetState();
}

class _IssueSheetState extends State<_IssueSheet> {
  final _controller = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reason = _controller.text.trim();
    if (reason.isEmpty) return;
    setState(() => _busy = true);
    final ok = await widget.notifier.reportIssue(widget.order.id, reason);
    if (!mounted) return;
    setState(() => _busy = false);
    if (ok) {
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Đã báo cáo sự cố cho quản lý.')),
      );
    } else if (widget.notifier.currentState.errorMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(widget.notifier.currentState.errorMessage!)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final viewInsets = MediaQuery.of(context).viewInsets;
    return Padding(
      padding: EdgeInsets.only(bottom: viewInsets.bottom),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Báo cáo sự cố giao hàng',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'Đơn ${widget.order.code} · tối đa 500 ký tự',
              style: const TextStyle(
                fontSize: 12,
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              controller: _controller,
              minLines: 3,
              maxLines: 6,
              maxLength: 500,
              decoration: const InputDecoration(
                hintText:
                    'VD: Khách không nghe máy, sai địa chỉ, đóng cửa không nhận...',
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: _busy ? null : () => Navigator.of(context).pop(),
                    child: const Text('Huỷ'),
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: ElevatedButton.icon(
                    icon: const Icon(Icons.send_rounded, size: 18),
                    onPressed: _busy ? null : _submit,
                    label: const Text('Gửi báo cáo'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────── COMMON WIDGETS ───────────────────────

class _Pill extends StatelessWidget {
  const _Pill({required this.text, required this.color});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w800,
          color: color,
          letterSpacing: 0.4,
        ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel({required this.text});
  final String text;
  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontSize: 11,
        color: AppColors.textSecondary,
        fontWeight: FontWeight.w700,
        letterSpacing: 0.6,
      ),
    );
  }
}

class _GhostChip extends StatelessWidget {
  const _GhostChip({
    required this.icon,
    required this.label,
    required this.onTap,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: 6,
          ),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.border),
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 14, color: AppColors.textPrimary),
              const SizedBox(width: 4),
              Text(
                label,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PrimaryButton extends StatelessWidget {
  const _PrimaryButton({
    required this.label,
    required this.icon,
    required this.onPressed,
    required this.loading,
  });
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return ElevatedButton.icon(
      icon: loading
          ? const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: Colors.white,
              ),
            )
          : Icon(icon, size: 18),
      label: Text(label),
      onPressed: onPressed,
      style: ElevatedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
    );
  }
}

class _GhostButton extends StatelessWidget {
  const _GhostButton({
    required this.label,
    required this.icon,
    required this.onPressed,
    required this.loading,
  });
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      icon: loading
          ? const SizedBox(
              width: 14,
              height: 14,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: AppColors.spicyRed,
              ),
            )
          : Icon(icon, size: 16),
      label: Text(label),
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        foregroundColor: AppColors.textPrimary,
        side: const BorderSide(color: AppColors.border, width: 1),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: const Color(0xFFFECACA)),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.error_outline_rounded,
            color: AppColors.danger,
            size: 18,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(fontSize: 12, color: AppColors.danger),
            ),
          ),
          TextButton(onPressed: onRetry, child: const Text('Thử lại')),
        ],
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.title, required this.message});
  final String title;
  final String message;
  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: 80,
          height: 80,
          decoration: BoxDecoration(
            color: AppColors.creamSoft,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
          alignment: Alignment.center,
          child: const Text('🛵', style: TextStyle(fontSize: 36)),
        ),
        const SizedBox(height: AppSpacing.sm),
        Text(
          title,
          style: const TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w800,
            color: AppColors.textPrimary,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          message,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
        ),
      ],
    );
  }
}

// ─────────────────────── HELPERS ───────────────────────

String _statusLabel(OrderStatus s) {
  switch (s) {
    case OrderStatus.confirmed:
      return 'CHỜ CHẾ BIẾN';
    case OrderStatus.preparing:
      return 'ĐANG CHẾ BIẾN';
    case OrderStatus.ready:
      return 'MÓN ĐÃ XONG';
    case OrderStatus.readyNotifyShipper:
      return 'ĐẾN LẤY HÀNG';
    case OrderStatus.delivering:
      return 'ĐANG GIAO';
    case OrderStatus.delivered:
      return 'CHỜ KHÁCH XN';
    case OrderStatus.completed:
      return 'HOÀN TẤT';
    case OrderStatus.cancelled:
      return 'ĐÃ HỦY';
    default:
      return s.name.toUpperCase();
  }
}

Color _statusColor(OrderStatus s) {
  switch (s) {
    case OrderStatus.delivering:
      return AppColors.info;
    case OrderStatus.delivered:
      return AppColors.warning;
    case OrderStatus.completed:
      return AppColors.success;
    case OrderStatus.cancelled:
      return AppColors.danger;
    default:
      return AppColors.textSecondary;
  }
}

String _formatTime(DateTime dt) {
  return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
}

String _formatDateTime(DateTime? dt) {
  if (dt == null) return '—';
  final dd = dt.day.toString().padLeft(2, '0');
  final mm = dt.month.toString().padLeft(2, '0');
  return '$dd/$mm ${_formatTime(dt)}';
}

String _shortMoney(double v) {
  if (v >= 1000000) return '${(v / 1000000).toStringAsFixed(1)}M';
  if (v >= 1000) return '${(v / 1000).toStringAsFixed(0)}k';
  return v.toStringAsFixed(0);
}
