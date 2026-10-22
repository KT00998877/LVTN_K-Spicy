import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../domain/order_models.dart';
import 'order_controller.dart';
import 'order_visuals.dart';

/// ============================================================
/// 📋 ORDERS LIST — đơn hàng của tôi (mobile-first)
/// ============================================================
/// - Filter theo status (tabs pill ngang)
/// - Pull-to-refresh
/// - Scroll xuống đáy → load more
/// - Bấm card → mở Order Detail
/// ============================================================

class OrdersListScreen extends ConsumerStatefulWidget {
  const OrdersListScreen({super.key});

  @override
  ConsumerState<OrdersListScreen> createState() => _OrdersListScreenState();
}

class _OrdersListScreenState extends ConsumerState<OrdersListScreen> {
  final _scrollCtrl = ScrollController();

  static const _filters = <_StatusFilter>[
    _StatusFilter(null, 'Tất cả'),
    _StatusFilter(OrderStatus.pending, 'Chờ xác nhận'),
    _StatusFilter(OrderStatus.delivering, 'Đang giao'),
    _StatusFilter(OrderStatus.completed, 'Đã hoàn tất'),
    _StatusFilter(OrderStatus.cancelled, 'Đã huỷ'),
  ];

  @override
  void initState() {
    super.initState();
    _scrollCtrl.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(orderListControllerProvider.notifier).load(force: true);
    });
  }

  @override
  void dispose() {
    _scrollCtrl.removeListener(_onScroll);
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scrollCtrl.hasClients) return;
    final pos = _scrollCtrl.position;
    if (pos.pixels >= pos.maxScrollExtent - 240) {
      ref.read(orderListControllerProvider.notifier).loadMore();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(orderListControllerProvider);
    final activeFilter = state.activeFilter;

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        title: const Text('Đơn hàng của tôi'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            tooltip: 'Tải lại',
            onPressed: () =>
                ref.read(orderListControllerProvider.notifier).refresh(),
          ),
        ],
      ),
      body: Column(
        children: [
          // ── Filter chips ─────────────────────────────────
          SizedBox(
            height: 48,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              itemCount: _filters.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (_, i) {
                final f = _filters[i];
                final selected = activeFilter == f.status;
                return FilterChip(
                  selected: selected,
                  showCheckmark: false,
                  label: Text(f.label),
                  onSelected: (_) => ref
                      .read(orderListControllerProvider.notifier)
                      .load(filter: f.status, force: true),
                  selectedColor: AppColors.spicyRed,
                  backgroundColor: Colors.white,
                  side: BorderSide(
                    color: selected ? AppColors.spicyRed : AppColors.border,
                  ),
                  labelStyle: TextStyle(
                    color: selected ? Colors.white : AppColors.textPrimary,
                    fontWeight: FontWeight.w700,
                    fontSize: 12,
                  ),
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                );
              },
            ),
          ),

          // ── Body ─────────────────────────────────────────
          Expanded(
            child: RefreshIndicator(
              color: AppColors.spicyRed,
              onRefresh: () =>
                  ref.read(orderListControllerProvider.notifier).refresh(),
              child: _buildBody(state),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBody(OrderListState state) {
    if (state.isLoading && !state.hasLoadedOnce) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.errorMessage != null && state.items.isEmpty) {
      return EmptyState(
        icon: '😢',
        title: 'Không tải được đơn hàng',
        subtitle: state.errorMessage,
        actionLabel: 'Thử lại',
        onAction: () =>
            ref.read(orderListControllerProvider.notifier).refresh(),
      );
    }
    if (state.items.isEmpty) {
      return EmptyState(
        icon: '📦',
        title: 'Chưa có đơn hàng',
        subtitle: state.activeFilter == null
            ? 'Đặt món để đơn đầu tiên xuất hiện nhé!'
            : 'Không có đơn nào ở trạng thái này.',
        actionLabel: 'Khám phá menu',
        onAction: () => context.go('/home/menu'),
      );
    }
    return ListView.separated(
      controller: _scrollCtrl,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppSpacing.md),
      itemCount: state.items.length + (state.hasMore ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
      itemBuilder: (_, i) {
        if (i >= state.items.length) {
          return const Padding(
            padding: EdgeInsets.all(AppSpacing.md),
            child: Center(
              child: SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2.5),
              ),
            ),
          );
        }
        return _OrderCard(order: state.items[i]);
      },
    );
  }
}

class _StatusFilter {
  const _StatusFilter(this.status, this.label);
  final OrderStatus? status;
  final String label;
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order});
  final OrderSummary order;

  IconData _typeIcon(OrderType t) {
    switch (t) {
      case OrderType.online:
        return Icons.delivery_dining_rounded;
      case OrderType.takeaway:
        return Icons.shopping_bag_rounded;
      case OrderType.dineIn:
        return Icons.restaurant_rounded;
      case OrderType.unknown:
        return Icons.help_outline_rounded;
    }
  }

  String _typeLabel(OrderType t) {
    switch (t) {
      case OrderType.online:
        return 'Giao tận nơi';
      case OrderType.takeaway:
        return 'Mang đi';
      case OrderType.dineIn:
        return 'Tại bàn';
      case OrderType.unknown:
        return '—';
    }
  }

  @override
  Widget build(BuildContext context) {
    final dateText = order.placedAt != null
        ? DateFormat('HH:mm · dd/MM/yyyy').format(order.placedAt!)
        : '—';

    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        onTap: () => context.push('/orders/${order.id}'),
        child: Container(
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ── Header row ────────────────────────────────
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '#${order.code}',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 14,
                        letterSpacing: -0.2,
                      ),
                    ),
                  ),
                  OrderStatusBadge(status: order.status),
                ],
              ),
              const SizedBox(height: 8),

              // ── Type + items + date ───────────────────────
              Row(
                children: [
                  Icon(
                    _typeIcon(order.orderType),
                    size: 14,
                    color: AppColors.textSecondary,
                  ),
                  const SizedBox(width: 4),
                  Text(
                    _typeLabel(order.orderType),
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(width: 10),
                  const Text('·', style: TextStyle(color: AppColors.textMuted)),
                  const SizedBox(width: 10),
                  Text(
                    '${order.itemCount} món',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    dateText,
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppColors.textMuted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),

              // ── Delivery address (nếu có) ────────────────
              if (order.deliveryAddress != null &&
                  order.deliveryAddress!.isNotEmpty) ...[
                const SizedBox(height: 6),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(
                      Icons.place_rounded,
                      size: 13,
                      color: AppColors.textMuted,
                    ),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(
                        order.deliveryAddress!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 12,
                          color: AppColors.textMuted,
                        ),
                      ),
                    ),
                  ],
                ),
              ],

              const SizedBox(height: 10),
              const Divider(height: 1),
              const SizedBox(height: 10),

              // ── Footer ───────────────────────────────────
              Row(
                children: [
                  if (order.paymentStatus != null) ...[
                    InvoiceStatusBadge(status: order.paymentStatus!),
                    const SizedBox(width: 8),
                  ],
                  const Spacer(),
                  Text('Tổng ', style: Theme.of(context).textTheme.bodySmall),
                  Text(
                    MoneyVnd.format(order.totalAmount),
                    style: const TextStyle(
                      color: AppColors.spicyRed,
                      fontWeight: FontWeight.w800,
                      fontSize: 15,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
