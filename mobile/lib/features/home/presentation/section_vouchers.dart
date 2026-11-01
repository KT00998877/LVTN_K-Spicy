import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../voucher/data/voucher_models.dart';
import '../../voucher/data/voucher_repository.dart';

final homeVouchersProvider = FutureProvider<List<Voucher>>((ref) async {
  return ref.read(voucherRepositoryProvider).fetchActive();
});

class HomeVouchersSection extends ConsumerWidget {
  const HomeVouchersSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vouchers = ref.watch(homeVouchersProvider).valueOrNull;
    if (vouchers == null || vouchers.isEmpty) return const SizedBox.shrink();

    return ColoredBox(
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('VOUCHER', style: Theme.of(context).textTheme.labelLarge?.copyWith(
              color: AppColors.spicyRed, letterSpacing: 1.1,
            )),
            const SizedBox(height: 6),
            Text('Ưu đãi cho đơn hàng của bạn',
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                color: AppColors.textPrimary, fontWeight: FontWeight.w800,
              )),
            const SizedBox(height: 14),
            for (final voucher in vouchers.take(3)) ...[
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.cream,
                  borderRadius: BorderRadius.circular(AppRadius.lg),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(children: [
                  const Icon(Icons.local_offer_outlined, color: AppColors.spicyRed),
                  const SizedBox(width: 12),
                  Expanded(child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(voucher.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(voucher.code, style: const TextStyle(
                        color: AppColors.brandDark, fontWeight: FontWeight.w800,
                      )),
                      if (voucher.minOrderSubtotal > 0)
                        Text('Đơn từ ${MoneyVnd.format(voucher.minOrderSubtotal)}',
                          style: const TextStyle(color: AppColors.textSecondary)),
                    ],
                  )),
                  IconButton(
                    tooltip: 'Sao chép mã ${voucher.code}',
                    onPressed: () async {
                      await Clipboard.setData(ClipboardData(text: voucher.code));
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Đã sao chép mã voucher')),
                        );
                      }
                    },
                    icon: const Icon(Icons.copy_rounded),
                  ),
                ]),
              ),
              const SizedBox(height: 10),
            ],
          ],
        ),
      ),
    );
  }
}
