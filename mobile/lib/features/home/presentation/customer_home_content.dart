import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../cart/presentation/cart_controller.dart';
import 'home_controller.dart';
import 'menu_navigation.dart';
import 'section_best_sellers.dart';
import 'section_categories.dart';
import 'section_combo_campaign.dart';
import 'section_footer.dart';
import 'section_hero.dart';
import 'section_menu_discovery.dart';
import 'section_reservation_cta.dart';
import 'section_restaurant_story.dart';
import 'section_vouchers.dart';
import 'section_featured_promotions.dart';

/// ============================================================
/// 🏠 CUSTOMER HOME — phiên bản đầy đủ giống trang chủ web
/// ============================================================
/// Section rhythm (theo home-ui.mdc):
///   CREAM Hero → WHITE Categories → CHARCOAL Best Sellers →
///   RED Combo Promo → CREAM Spicy Level → WHITE Menu Discovery →
///   CHARCOAL Restaurant Story → RED Reservation CTA →
///   CHARCOAL Footer
///
/// Mỗi section là một widget riêng → dễ bảo trì, dễ tinh chỉnh.
/// ============================================================

class CustomerHomeContent extends ConsumerStatefulWidget {
  const CustomerHomeContent({super.key, required this.onSwitchToMenu});

  /// Callback chuyển sang tab Menu. Trước khi gọi, parent có thể
  /// set filter vào [pendingMenuFilterProvider].
  final VoidCallback onSwitchToMenu;

  @override
  ConsumerState<CustomerHomeContent> createState() =>
      _CustomerHomeContentState();
}

class _CustomerHomeContentState
    extends ConsumerState<CustomerHomeContent> {
  @override
  void initState() {
    super.initState();
    // Kick off home payload fetch + cart refresh sau frame đầu tiên.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(homeControllerProvider.notifier).load();
      ref.read(cartControllerProvider.notifier).refresh();
    });
  }

  void _onReservationTap() {
    context.push('/reservation');
  }

  void _openMenuWithCategory(String slug) {
    ref.read(pendingMenuFilterProvider.notifier).state =
        MenuFilter(categorySlug: slug);
    widget.onSwitchToMenu();
  }

  void _openMenu() {
    ref.read(pendingMenuFilterProvider.notifier).state = null;
    widget.onSwitchToMenu();
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      color: AppColors.spicyRed,
      onRefresh: () async {
        await ref.read(homeControllerProvider.notifier).refresh();
        await ref.read(cartControllerProvider.notifier).refresh();
        ref.invalidate(featuredPromotionsProvider);
        ref.invalidate(comboCampaignProvider);
        ref.invalidate(homeVouchersProvider);
      },
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          HeroSection(
            onReservationTap: _onReservationTap,
            onOpenMenu: _openMenu,
          ),
          CategoriesSection(onOpenMenu: _openMenuWithCategory),
          const BestSellersSection(),
          ComboCampaignSection(onOpenMenu: () => _openMenuWithCategory('combo')),
          const FeaturedPromotionsSection(),
          const HomeVouchersSection(),
          MenuDiscoverySection(onOpenMenu: _openMenu),
          const RestaurantStorySection(),
          ReservationCtaSection(onReservationTap: _onReservationTap),
          const FooterHomeSection(),
          const SizedBox(height: 12),
        ],
      ),
    );
  }
}
