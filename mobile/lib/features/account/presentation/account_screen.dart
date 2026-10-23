import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../auth/presentation/auth_controller.dart';
import 'profile_tab.dart';
import 'reviews_tab.dart';
import 'security_tab.dart';
import 'tier_tab.dart';
import 'widgets/account_widgets.dart';

/// ============================================================
/// 👤 ACCOUNT SCREEN — "Tài khoản của tôi"
/// ============================================================
///
/// Đối chiếu với web `/tai-khoan`, nhưng cắt bớt 2 tab theo phạm vi đã chốt:
///
///   Web                        Mobile
///   ─────────────────────────  ─────────────────────────────
///   Thông tin cá nhân    ✓ →  Hồ sơ (avatar + sửa thông tin)
///   Đơn hàng của tôi     ✗     (đã có màn /orders riêng)
///   Bảo mật              ✓ →  Bảo mật (đổi mật khẩu + đăng xuất)
///   Điểm thưởng          ✓ →  Điểm & Hạng (gộp: hạng + sổ cái điểm)
///   Hạng thành viên      ✓
///   Đánh giá của tôi     ✓ →  Đánh giá
///
/// Dùng `TabBar` dạng pill cuộn ngang thay vì 6 tab xuống dòng — mobile
/// không đủ chỗ ngang, và pill là pattern đã dùng ở `OrdersListScreen`.
///
class AccountScreen extends ConsumerStatefulWidget {
  const AccountScreen({super.key, this.initialIndex = 0});

  final int initialIndex;

  @override
  ConsumerState<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends ConsumerState<AccountScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabCtrl =
      TabController(length: _tabs.length, vsync: this, initialIndex: widget.initialIndex);

  static const _tabs = [
    _AccountTab('Hồ sơ', Icons.person_rounded),
    _AccountTab('Hạng & Điểm', Icons.workspace_premium_rounded),
    _AccountTab('Đánh giá', Icons.star_rounded),
    _AccountTab('Bảo mật', Icons.lock_rounded),
  ];

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final user = auth.user;
    if (user == null) return const Scaffold();

    final displayName =
        user.fullName?.isNotEmpty == true ? user.fullName! : user.name;

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        title: const Text('Tài khoản'),
        backgroundColor: AppColors.cream,
        surfaceTintColor: Colors.transparent,
      ),
      body: Column(
        children: [
          // ── Header: avatar + tên + hạng + điểm ──────────
          _AccountHeader(
            name: displayName,
            email: user.email,
            avatarUrl: user.avatarUrl,
            tierName: user.tier?.name,
            tierColor: user.tier?.color,
            tierIcon: user.tier?.icon,
            loyaltyPoints: user.loyaltyPoints,
          ),

          // ── Tab pills ─────────────────────────────────
          _TabStrip(controller: _tabCtrl, tabs: _tabs),

          Expanded(
            child: TabBarView(
              controller: _tabCtrl,
              children: const [
                ProfileTab(),
                TierTab(),
                ReviewsTab(),
                SecurityTab(),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _AccountTab {
  const _AccountTab(this.label, this.icon);

  final String label;
  final IconData icon;
}

class _AccountHeader extends StatelessWidget {
  const _AccountHeader({
    required this.name,
    required this.email,
    required this.loyaltyPoints,
    this.avatarUrl,
    this.tierName,
    this.tierColor,
    this.tierIcon,
  });

  final String name;
  final String email;
  final String? avatarUrl;
  final String? tierName;
  final String? tierColor;
  final String? tierIcon;
  final int loyaltyPoints;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.xs,
        AppSpacing.md,
        AppSpacing.md,
      ),
      decoration: const BoxDecoration(
        color: AppColors.cream,
        border: Border(bottom: BorderSide(color: AppColors.divider)),
      ),
      child: Column(
        children: [
          Row(
            children: [
              UserAvatar(name: name, avatarUrl: avatarUrl, size: 62),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 19,
                        fontWeight: FontWeight.w800,
                        height: 1.2,
                        letterSpacing: -0.3,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      email,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 13,
                        color: AppColors.textSecondary,
                      ),
                    ),
                    if (tierName != null) ...[
                      const SizedBox(height: AppSpacing.xs),
                      TierBadge(
                        name: tierName,
                        colorHex: tierColor,
                        icon: tierIcon,
                        compact: true,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (loyaltyPoints > 0) ...[
            const SizedBox(height: AppSpacing.sm),
            _LoyaltyPill(points: loyaltyPoints),
          ],
        ],
      ),
    );
  }
}

/// Pill vàng "điểm thưởng" — nổi bật nhưng không tranh chấp với avatar.
class _LoyaltyPill extends StatelessWidget {
  const _LoyaltyPill({required this.points});

  final int points;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AppColors.goldenYellow.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: AppColors.goldenYellow),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.star_rounded, size: 18, color: AppColors.warning),
          const SizedBox(width: AppSpacing.xs),
          Text(
            '$points',
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: AppColors.charcoal,
            ),
          ),
          const SizedBox(width: 4),
          const Text(
            'điểm thưởng',
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

/// Dải pill cuộn ngang — pattern giống filter chips ở OrdersListScreen.
class _TabStrip extends StatelessWidget {
  const _TabStrip({required this.controller, required this.tabs});

  final TabController controller;
  final List<_AccountTab> tabs;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 52,
      decoration: const BoxDecoration(
        color: AppColors.cream,
        border: Border(bottom: BorderSide(color: AppColors.divider)),
      ),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.xs,
        ),
        itemCount: tabs.length,
        separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.xs),
        itemBuilder: (context, i) {
          return _TabPill(
            tab: tabs[i],
            index: i,
            controller: controller,
          );
        },
      ),
    );
  }
}

class _TabPill extends StatelessWidget {
  const _TabPill({
    required this.tab,
    required this.index,
    required this.controller,
  });

  final _AccountTab tab;
  final int index;
  final TabController controller;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) {
        final selected = controller.index == index;
        return Material(
          color: selected ? AppColors.spicyRed : Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.pill),
          child: InkWell(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            onTap: () => controller.animateTo(index),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(AppRadius.pill),
                border: Border.all(
                  color: selected ? AppColors.spicyRed : AppColors.border,
                ),
              ),
              child: Row(
                children: [
                  Icon(
                    tab.icon,
                    size: 16,
                    color: selected ? Colors.white : AppColors.textSecondary,
                  ),
                  const SizedBox(width: 6),
                  Text(
                    tab.label,
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: selected ? Colors.white : AppColors.textPrimary,
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}