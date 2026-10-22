import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/cart/presentation/cart_controller.dart';
import '../../features/cart/presentation/cart_tab_screen.dart';
import '../../features/home/presentation/customer_home_content.dart';
import '../../features/home/presentation/menu_navigation.dart';
import '../../features/menu/presentation/menu_screen.dart';
import '../../features/orders/presentation/orders_list_screen.dart';
import '../../features/reservation/presentation/my_reservations_screen.dart';

/// ============================================================
/// 🏠 CUSTOMER HOME SHELL — bottom navigation cho khách hàng
/// ============================================================
/// Tabs: Trang chủ · Menu · Giỏ · Đơn · Tài khoản
/// - Tab "Trang chủ" dùng [CustomerHomeContent] (giống web).
/// - Tab "Menu" dùng [MenuScreen] có sẵn (filter + search).
/// - Tab "Giỏ" dùng [CartTabScreen] (bottom-nav friendly).
/// - Tab "Đơn" dùng [OrdersListScreen] (filter + pull-to-refresh).
/// - Tab "Tài khoản" hiển thị thông tin user + đăng xuất.
/// ============================================================

class CustomerHomeScreen extends ConsumerStatefulWidget {
  const CustomerHomeScreen({super.key});

  @override
  ConsumerState<CustomerHomeScreen> createState() => _CustomerHomeScreenState();
}

class _CustomerHomeScreenState extends ConsumerState<CustomerHomeScreen> {
  int _tabIndex = 0;

  static const _tabs = <_Tab>[
    _Tab(icon: Icons.home_rounded, label: 'Trang chủ'),
    _Tab(icon: Icons.restaurant_menu_rounded, label: 'Menu'),
    _Tab(icon: Icons.shopping_cart_rounded, label: 'Giỏ'),
    _Tab(icon: Icons.receipt_long_rounded, label: 'Đơn'),
    _Tab(icon: Icons.event_seat_rounded, label: 'Đặt bàn'),
    _Tab(icon: Icons.person_rounded, label: 'Tài khoản'),
  ];

  // Index của tab Menu để chuyển tab khi CTA ở Home bấm vào.
  static const _menuTabIndex = 1;

  void _switchTab(int i) {
    setState(() => _tabIndex = i);
    if (i == 2) ref.read(cartControllerProvider.notifier).refresh();
  }

  void _switchToMenu() => _switchTab(_menuTabIndex);

  @override
  Widget build(BuildContext context) {
    final cartCount = ref.watch(cartBadgeCountProvider);
    final pending = ref.watch(pendingMenuFilterProvider);

    final menuPage = MenuScreen(
      initialCategorySlug: pending?.categorySlug,
      initialSpicyLevel: pending?.spicyLevel,
    );

    final pages = <Widget>[
      CustomerHomeContent(onSwitchToMenu: _switchToMenu),
      menuPage,
      const CartTabScreen(),
      const OrdersListScreen(),
      const MyReservationsScreen(),
      const _AccountTab(),
    ];

    return Scaffold(
      body: IndexedStack(index: _tabIndex, children: pages),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/chat'),
        icon: const Icon(Icons.chat_bubble_outline_rounded),
        label: const Text('Trợ lý'),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tabIndex,
        backgroundColor: Colors.white,
        indicatorColor: AppColors.spicyRedSoft,
        onDestinationSelected: _switchTab,
        destinations: [
          for (var i = 0; i < _tabs.length; i++)
            NavigationDestination(
              icon: i == 2 && cartCount > 0
                  ? Badge.count(count: cartCount, child: Icon(_tabs[i].icon))
                  : Icon(_tabs[i].icon),
              selectedIcon: i == 2 && cartCount > 0
                  ? Badge.count(
                      count: cartCount,
                      child: Icon(_tabs[i].icon, color: AppColors.spicyRed),
                    )
                  : Icon(_tabs[i].icon, color: AppColors.spicyRed),
              label: _tabs[i].label,
            ),
        ],
      ),
    );
  }
}

class _Tab {
  const _Tab({required this.icon, required this.label});
  final IconData icon;
  final String label;
}

/// ───────────────── Account Tab ─────────────────
class _AccountTab extends ConsumerWidget {
  const _AccountTab();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Tài khoản')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          ListTile(
            leading: CircleAvatar(
              backgroundColor: AppColors.spicyRed,
              child: Text(
                user.name.isNotEmpty ? user.name[0].toUpperCase() : '?',
                style: const TextStyle(color: Colors.white),
              ),
            ),
            title: Text(user.name),
            subtitle: Text(user.email),
          ),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.workspace_premium_rounded),
            title: const Text('Hạng thành viên'),
            subtitle: Text('${user.loyaltyPoints} điểm'),
          ),
          ListTile(
            leading: const Icon(Icons.lock_outline_rounded),
            title: const Text('Đổi mật khẩu'),
          ),
          ListTile(
            leading: const Icon(Icons.logout_rounded, color: AppColors.danger),
            title: const Text(
              'Đăng xuất',
              style: TextStyle(color: AppColors.danger),
            ),
            onTap: () => ref.read(authControllerProvider.notifier).logout(),
          ),
        ],
      ),
    );
  }
}
