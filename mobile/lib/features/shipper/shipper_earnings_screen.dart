import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api/api_exception.dart';
import 'data/shipper_repository.dart';
import 'domain/shipper_earnings.dart';

enum _Period { today, week, month }

class ShipperEarningsScreen extends ConsumerStatefulWidget {
  const ShipperEarningsScreen({super.key});

  @override
  ConsumerState<ShipperEarningsScreen> createState() =>
      _ShipperEarningsScreenState();
}

class _ShipperEarningsScreenState extends ConsumerState<ShipperEarningsScreen> {
  static final _money = NumberFormat('#,##0', 'vi_VN');
  static final _distance = NumberFormat('0.##', 'vi_VN');
  static final _day = DateFormat('yyyy-MM-dd');
  static final _timestamp = DateFormat('HH:mm dd/MM/yyyy', 'vi_VN');

  _Period _period = _Period.month;
  ShipperEarningsPage? _data;
  String? _error;
  bool _loading = false;
  int _requestId = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _load(1);
    });
  }

  (String, String) _dates() {
    final now = DateTime.now();
    final from = switch (_period) {
      _Period.today => now,
      _Period.week => now.subtract(Duration(days: now.weekday - 1)),
      _Period.month => DateTime(now.year, now.month),
    };
    return (_day.format(from), _day.format(now));
  }

  Future<void> _load(int page) async {
    final requestId = ++_requestId;
    final (from, to) = _dates();
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(shipperRepositoryProvider)
          .fetchEarnings(from: from, to: to, page: page);
      if (!mounted || requestId != _requestId) return;
      setState(() => _data = result);
    } catch (error) {
      if (!mounted || requestId != _requestId) return;
      setState(
        () => _error = error is ApiException
            ? error.message
            : 'Chưa tải được thu nhập. Vui lòng thử lại.',
      );
    } finally {
      if (mounted && requestId == _requestId) {
        setState(() => _loading = false);
      }
    }
  }

  void _selectPeriod(_Period period) {
    if (_period == period) return;
    setState(() {
      _period = period;
      _data = null;
    });
    _load(1);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(title: const Text('Thu nhập giao hàng')),
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.spicyRed,
          onRefresh: () => _load(_data?.currentPage ?? 1),
          child: ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              const Text(
                'TIỀN CÔNG SHIPPER',
                style: TextStyle(
                  color: AppColors.spicyRed,
                  fontWeight: FontWeight.w800,
                  fontSize: 11,
                  letterSpacing: 1,
                ),
              ),
              const SizedBox(height: AppSpacing.xs),
              const Text(
                '3.500 đ/km · tối thiểu 3 km mỗi đơn',
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: AppSpacing.xxs),
              const Text(
                'Tiền công được làm tròn đến 100 đ khi giao xong. COD thu hộ không tính vào thu nhập.',
                style: TextStyle(color: AppColors.textSecondary, fontSize: 12),
              ),
              const SizedBox(height: AppSpacing.md),
              Wrap(
                spacing: AppSpacing.xs,
                children: [
                  _periodChip(_Period.today, 'Hôm nay'),
                  _periodChip(_Period.week, 'Tuần này'),
                  _periodChip(_Period.month, 'Tháng này'),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              if (_error != null) _errorCard(),
              if (_loading && _data == null)
                const Padding(
                  padding: EdgeInsets.all(AppSpacing.xl),
                  child: Center(child: CircularProgressIndicator()),
                ),
              if (_data case final data?) ...[
                _summary(data),
                const SizedBox(height: AppSpacing.lg),
                const Text(
                  'Chi tiết từng đơn',
                  style: TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                if (data.items.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: AppSpacing.xl),
                    child: Center(
                      child: Text(
                        'Chưa có đơn giao xong trong khoảng thời gian này.',
                      ),
                    ),
                  ),
                for (final item in data.items) ...[
                  _earningCard(item),
                  const SizedBox(height: AppSpacing.sm),
                ],
                if (data.lastPage > 1) _pagination(data),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _periodChip(_Period period, String label) => ChoiceChip(
    label: Text(label),
    selected: _period == period,
    onSelected: (_) => _selectPeriod(period),
    selectedColor: AppColors.spicyRedSoft,
    labelStyle: TextStyle(
      color: _period == period
          ? AppColors.spicyRedDark
          : AppColors.textSecondary,
      fontWeight: FontWeight.w700,
    ),
  );

  Widget _errorCard() => Card(
    color: AppColors.spicyRedSoft,
    child: Padding(
      padding: const EdgeInsets.all(AppSpacing.sm),
      child: Row(
        children: [
          const Icon(Icons.error_outline, color: AppColors.danger),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              _error!,
              style: const TextStyle(color: AppColors.danger),
            ),
          ),
          TextButton(
            onPressed: _loading ? null : () => _load(_data?.currentPage ?? 1),
            child: const Text('Thử lại'),
          ),
        ],
      ),
    ),
  );

  Widget _summary(ShipperEarningsPage data) => Row(
    children: [
      Expanded(
        child: _summaryCard(
          'Tiền công',
          '${_money.format(data.totalAmount)} đ',
        ),
      ),
      const SizedBox(width: AppSpacing.xs),
      Expanded(child: _summaryCard('Đơn đã giao', '${data.orderCount}')),
    ],
  );

  Widget _summaryCard(String label, String value) => Container(
    padding: const EdgeInsets.all(AppSpacing.md),
    decoration: BoxDecoration(
      color: Colors.white,
      border: Border.all(color: AppColors.border),
      borderRadius: BorderRadius.circular(AppRadius.md),
      boxShadow: AppShadow.card,
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
        ),
        const SizedBox(height: AppSpacing.xs),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            value,
            style: const TextStyle(
              fontSize: 21,
              fontWeight: FontWeight.w800,
              color: AppColors.brandDark,
            ),
          ),
        ),
      ],
    ),
  );

  Widget _earningCard(ShipperEarning item) => Container(
    padding: const EdgeInsets.all(AppSpacing.md),
    decoration: BoxDecoration(
      color: Colors.white,
      border: Border.all(color: AppColors.border),
      borderRadius: BorderRadius.circular(AppRadius.md),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                item.code,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
            Text(
              '${_money.format(item.amount)} đ',
              style: const TextStyle(
                fontWeight: FontWeight.w800,
                color: AppColors.spicyRed,
                fontSize: 17,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xxs),
        Text(
          '${item.orderType == 'TAKEAWAY' ? 'Mang đi' : 'Giao tận nơi'} · ${_timestamp.format(item.earnedAt)}',
          style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
        ),
        const Divider(height: AppSpacing.lg),
        Text(
          'Quãng đường thực: ${item.actualDistanceKm == null ? '—' : '${_distance.format(item.actualDistanceKm)} km'}',
          style: const TextStyle(color: AppColors.textSecondary),
        ),
        const SizedBox(height: AppSpacing.xxs),
        Text(
          'Tính công: ${_distance.format(item.billableDistanceKm)} km × ${_money.format(item.ratePerKm)} đ/km',
          style: const TextStyle(
            color: AppColors.textPrimary,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    ),
  );

  Widget _pagination(ShipperEarningsPage data) => Wrap(
    alignment: WrapAlignment.spaceBetween,
    crossAxisAlignment: WrapCrossAlignment.center,
    spacing: AppSpacing.xs,
    children: [
      TextButton(
        onPressed: _loading || data.currentPage <= 1
            ? null
            : () => _load(data.currentPage - 1),
        child: const Text('Trang trước'),
      ),
      Text('${data.currentPage}/${data.lastPage}'),
      TextButton(
        onPressed: _loading || data.currentPage >= data.lastPage
            ? null
            : () => _load(data.currentPage + 1),
        child: const Text('Trang sau'),
      ),
    ],
  );
}
