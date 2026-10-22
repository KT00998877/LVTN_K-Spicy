import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../cart/presentation/cart_controller.dart';
import '../../reservation/presentation/reservation_booking_screen.dart';
import 'home_controller.dart';
import 'menu_navigation.dart';
import 'section_best_sellers.dart';
import 'section_categories.dart';
import 'section_combo_promo.dart';
import 'section_footer.dart';
import 'section_hero.dart';
import 'section_menu_discovery.dart';
import 'section_reservation_cta.dart';
import 'section_restaurant_story.dart';
import 'section_spicy_level.dart';

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
    // Mở booking form dưới dạng bottom sheet (mobile-first UX).
    // User đã đăng nhập → dùng thông tin từ profile tự điền sẵn.
    ReservationBookingScreen.openAsSheet(context);
  }

  void _openMenuWithCategory(String slug) {
    ref.read(pendingMenuFilterProvider.notifier).state =
        MenuFilter(categorySlug: slug);
    widget.onSwitchToMenu();
  }

  void _openMenuWithSpicy(int level) {
    ref.read(pendingMenuFilterProvider.notifier).state =
        MenuFilter(spicyLevel: level);
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
          const ComboPromoSection(),
          SpicyLevelSection(onOpenMenu: _openMenuWithSpicy),
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
