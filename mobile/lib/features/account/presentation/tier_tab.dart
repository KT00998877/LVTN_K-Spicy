import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../domain/loyalty_models.dart';
import 'account_controller.dart';
import 'profile_tab.dart' show AccountFeedbackBanner;
import 'widgets/account_widgets.dart';

/// ============================================================
/// 🏆 TAB: HẠNG THÀNH VIÊN
/// ============================================================
///
/// Bố cục theo đúng web `LoyaltyTierPanel.vue`:
///
///   1. Hạng hiện tại (tên hạng + tổng chi tiêu + điểm)
///   2. Thanh tiến trình lên hạng kế (hoặc badge "đã đạt hạng cao nhất")
///   3. Lưới 4 hạng, đánh dấu hạng hiện tại
///   4. Sổ cái điểm (giao dịch)
///
/// Điểm khác biệt so với web: dùng `ListView` + `RefreshIndicator`
/// thay vì panel tĩnh, vì đây là màn mobile cần vuốt.
///
class TierTab extends ConsumerStatefulWidget {
  const TierTab({super.key});

  @override
  ConsumerState<TierTab> createState() => _TierTabState();
}

class _TierTabState extends ConsumerState<TierTab> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(tierControllerProvider.notifier).load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(tierControllerProvider);

    return RefreshIndicator(
      color: AppColors.spicyRed,
      onRefresh: () => ref.read(tierControllerProvider.notifier).refresh(),
      child: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (state.isLoading && !state.hasLoadedOnce)
            const _TierSkeleton()
          else ...[
            if (state.error != null)
              AccountFeedbackBanner(error: state.error),
            _CurrentTierCard(overview: state.overview),
            const SizedBox(height: AppSpacing.md),
            if (state.overview.isMaxTier)
              const _MaxTierBanner()
            else
              _TierProgressCard(
                overview: state.overview,
              ),
            const SizedBox(height: AppSpacing.lg),
            if (state.tiers.isNotEmpty) ...[
              const _SectionLabel('Quyền lợi theo hạng'),
              const SizedBox(height: AppSpacing.sm),
              ...state.tiers.map(
                (tier) => Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _TierCard(
                    tier: tier,
                    isCurrent: state.overview.current?.id == tier.id,
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
            ],
            const _SectionLabel('Lịch sử điểm thưởng'),
            const SizedBox(height: AppSpacing.sm),
            _LedgerSection(state: state),
          ],
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }
}

// ────────────────── Hạng hiện tại ──────────────────────────

class _CurrentTierCard extends StatelessWidget {
  const _CurrentTierCard({required this.overview});

  final LoyaltyOverview overview;

  @override
  Widget build(BuildContext context) {
    final tier = overview.current;
    final accent = TierBadge.resolveColor(tier?.color);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: accent.withValues(alpha: 0.5), width: 1.6),
        boxShadow: AppShadow.card,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 52,
                height: 52,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
                child: Text(
                  tier?.icon ?? '🌶',
                  style: const TextStyle(fontSize: 26),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Hạng hiện tại của bạn',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textMuted,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      tier?.name ?? 'Chưa có hạng',
                      style: const TextStyle(
                        fontSize: 21,
                        fontWeight: FontWeight.w800,
                        height: 1.15,
                        letterSpacing: -0.3,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          const Divider(height: 1),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(
                child: _Stat(
                  label: 'Tổng chi tiêu',
                  value: MoneyVnd.format(overview.totalSpent),
                ),
              ),
              Container(width: 1, height: 34, color: AppColors.divider),
              Expanded(
                child: _Stat(
                  label: 'Điểm thưởng',
                  value: '${overview.loyaltyPoints}',
                  valueColor: AppColors.spicyRed,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value, this.valueColor});

  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          value,
          style: TextStyle(
            fontSize: 17,
            fontWeight: FontWeight.w800,
            color: valueColor ?? AppColors.textPrimary,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        const SizedBox(height: 2),
        Text(
          label,
          style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
        ),
      ],
    );
  }
}

// ────────────────── Tiến trình thăng hạng ───────────────────

class _TierProgressCard extends StatelessWidget {
  const _TierProgressCard({required this.overview});

  final LoyaltyOverview overview;

  @override
  Widget build(BuildContext context) {
    final progress = overview.progress;
    final next = overview.next;
    if (progress == null || next == null) return const SizedBox.shrink();

    final percent = progress.percent.clamp(0.0, 100.0);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.goldenYellow.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.goldenYellow.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: RichText(
                  text: TextSpan(
                    style: const TextStyle(
                      fontSize: 13,
                      height: 1.5,
                      color: AppColors.textSecondary,
                    ),
                    children: [
                      const TextSpan(text: 'Còn '),
                      TextSpan(
                        text: MoneyVnd.format(progress.amountToNext),
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      const TextSpan(text: ' nữa để lên hạng '),
                      TextSpan(
                        text: next.name,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          color: AppColors.textPrimary,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Text(
                '${percent.toStringAsFixed(0)}%',
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: AppColors.spicyRed,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: percent / 100),
              duration: const Duration(milliseconds: 550),
              curve: Curves.easeOutCubic,
              builder: (context, value, _) => LinearProgressIndicator(
                value: value,
                minHeight: 8,
                backgroundColor: AppColors.creamSoft,
                valueColor: const AlwaysStoppedAnimation(AppColors.spicyRed),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _MaxTierBanner extends StatelessWidget {
  const _MaxTierBanner();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            AppColors.goldenYellow.withValues(alpha: 0.35),
            AppColors.goldenYellow.withValues(alpha: 0.18),
          ],
        ),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.goldenYellow),
      ),
      child: const Row(
        children: [
          Text('🏆', style: TextStyle(fontSize: 26)),
          SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              'Bạn đã đạt hạng cao nhất. Cảm ơn vì đã đồng hành cùng K-SPICY!',
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                height: 1.45,
                color: AppColors.charcoal,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ────────────────── Lưới hạng ───────────────────────────────

class _TierCard extends StatelessWidget {
  const _TierCard({required this.tier, required this.isCurrent});

  final LoyaltyTier tier;
  final bool isCurrent;

  @override
  Widget build(BuildContext context) {
    final accent = TierBadge.resolveColor(tier.color);

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(
          color: isCurrent ? accent : AppColors.border,
          width: isCurrent ? 1.8 : 1,
        ),
        boxShadow: isCurrent ? AppShadow.card : null,
      ),
      child: Column(
        children: [
          // Viền trên 5px theo màu hạng (giống web).
          Container(
            height: 5,
            decoration: BoxDecoration(
              color: accent,
              borderRadius: const BorderRadius.vertical(
                top: Radius.circular(AppRadius.lg),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(tier.icon ?? '🌶', style: const TextStyle(fontSize: 28)),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            tier.name,
                            style: const TextStyle(
                              fontSize: 17,
                              fontWeight: FontWeight.w800,
                              height: 1.2,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Từ ${MoneyVnd.format(tier.minSpent)} chi tiêu',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                              color: AppColors.textMuted,
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (isCurrent)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.xs,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: accent,
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: const Text(
                          'Hạng của bạn',
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.w800,
                            color: Colors.white,
                          ),
                        ),
                      ),
                  ],
                ),
                if (tier.description != null && tier.description!.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    tier.description!,
                    style: const TextStyle(
                      fontSize: 13,
                      height: 1.5,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
                if (tier.perks.hasMultiplier ||
                    tier.perks.hasFreeShip ||
                    tier.perks.hasDiscount) ...[
                  const SizedBox(height: AppSpacing.sm),
                  ..._perkRows(tier.perks, accent),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _perkRows(TierPerks perks, Color accent) {
    final rows = <Widget>[];

    if (perks.hasMultiplier) {
      rows.add(_perkRow(
        accent,
        '×',
        'Tích điểm x${perks.pointMultiplier.toStringAsFixed(1)}',
      ));
    }
    if (perks.hasFreeShip) {
      final threshold = perks.freeShipThreshold!;
      rows.add(_perkRow(
        accent,
        '🚚',
        threshold == 0
            ? 'Miễn phí ship mọi đơn'
            : 'Miễn phí ship đơn từ ${MoneyVnd.format(threshold)}',
      ));
    }
    if (perks.hasDiscount) {
      rows.add(_perkRow(
        accent,
        '%',
        'Giảm thêm ${perks.extraDiscountPct.toStringAsFixed(0)}% trọn đời',
      ));
    }
    return rows;
  }

  Widget _perkRow(Color accent, String bullet, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 20,
              height: 20,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: accent.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(AppRadius.xs),
              ),
              child: Text(
                bullet,
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Text(
                text,
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.5,
                  color: AppColors.textSecondary,
                ),
              ),
            ),
          ],
        ),
      );
}

// ────────────────── Sổ cái điểm ────────────────────────────

class _LedgerSection extends ConsumerWidget {
  const _LedgerSection({required this.state});

  final TierState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (state.isLoadingLedger && state.ledger.isEmpty) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: AppSpacing.xl),
        child: Center(
          child: SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2.5),
          ),
        ),
      );
    }

    if (state.ledger.isEmpty) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xl),
        child: const EmptyState(
          icon: '⭐',
          title: 'Chưa có giao dịch điểm',
          subtitle: 'Bạn sẽ nhận điểm khi xác nhận đã nhận hàng.',
        ),
      );
    }

    return Column(
      children: [
        ...state.ledger.map(
          (entry) => Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: _LedgerRow(entry: entry),
          ),
        ),
        if (state.ledgerMeta.lastPage > 1) ...[
          const SizedBox(height: AppSpacing.sm),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              _PageButton(
                icon: Icons.chevron_left_rounded,
                enabled: state.ledgerMeta.currentPage > 1,
                onTap: () => ref
                    .read(tierControllerProvider.notifier)
                    .loadLedger(page: state.ledgerMeta.currentPage - 1),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
                child: Text(
                  'Trang ${state.ledgerMeta.currentPage}/${state.ledgerMeta.lastPage}',
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
              _PageButton(
                icon: Icons.chevron_right_rounded,
                enabled: state.ledgerMeta.currentPage < state.ledgerMeta.lastPage,
                onTap: () => ref
                    .read(tierControllerProvider.notifier)
                    .loadLedger(page: state.ledgerMeta.currentPage + 1),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

class _LedgerRow extends StatelessWidget {
  const _LedgerRow({required this.entry});

  final LoyaltyEntry entry;

  @override
  Widget build(BuildContext context) {
    final positive = entry.isEarn;
    final color = positive ? AppColors.success : AppColors.chiliOrange;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.xs,
              vertical: 4,
            ),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Text(
              positive ? 'Cộng điểm' : 'Dùng điểm',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                color: color,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.reason ?? '—',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    height: 1.35,
                  ),
                ),
                if (entry.createdAt != null)
                  Text(
                    DateFormat('dd/MM/yyyy · HH:mm').format(entry.createdAt!),
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppColors.textMuted,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '${positive ? '+' : ''}${entry.points}',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: color,
                ),
              ),
              Text(
                'Còn ${entry.balanceAfter}',
                style: const TextStyle(
                  fontSize: 10,
                  color: AppColors.textMuted,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _PageButton extends StatelessWidget {
  const _PageButton({
    required this.icon,
    required this.enabled,
    required this.onTap,
  });

  final IconData icon;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: enabled ? Colors.white : AppColors.cream,
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        onTap: enabled ? onTap : null,
        child: Container(
          width: 36,
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.sm),
            border: Border.all(color: AppColors.border),
          ),
          child: Icon(
            icon,
            size: 20,
            color: enabled ? AppColors.textPrimary : AppColors.textMuted,
          ),
        ),
      ),
    );
  }
}

// ────────────────── Skeleton ────────────────────────────────

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.6,
          color: AppColors.textMuted,
        ),
      );
}

class _TierSkeleton extends StatelessWidget {
  const _TierSkeleton();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        _SkeletonBlock(height: 150),
        const SizedBox(height: AppSpacing.md),
        _SkeletonBlock(height: 84),
        const SizedBox(height: AppSpacing.md),
        _SkeletonBlock(height: 120),
        const SizedBox(height: AppSpacing.md),
        _SkeletonBlock(height: 120),
      ],
    );
  }
}

class _SkeletonBlock extends StatefulWidget {
  const _SkeletonBlock({required this.height});

  final double height;

  @override
  State<_SkeletonBlock> createState() => _SkeletonBlockState();
}

class _SkeletonBlockState extends State<_SkeletonBlock>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (context, _) => Container(
        height: widget.height,
        decoration: BoxDecoration(
          color: Color.lerp(
            AppColors.creamSoft,
            AppColors.creamDark,
            _ctrl.value,
          ),
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
      ),
    );
  }
}