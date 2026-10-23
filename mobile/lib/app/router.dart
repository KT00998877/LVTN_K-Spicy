import '../features/payments/presentation/payment_screen.dart';
import '../features/payments/presentation/vnpay_return_screen.dart';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/deep_link/deep_link_service.dart';
import '../features/auth/domain/app_user.dart';
import '../features/auth/presentation/auth_controller.dart';
import '../features/auth/presentation/login_screen.dart';
import '../features/cart/presentation/cart_screen.dart';
import '../features/cart/presentation/checkout_screen.dart';
import '../features/customer/customer_home_screen.dart';
import '../features/account/presentation/account_screen.dart';
import '../features/chat/presentation/chat_screen.dart';
import '../features/live_chat/presentation/live_chat_screen.dart';
import '../features/menu/presentation/menu_detail_screen.dart';
import '../features/orders/presentation/orders_list_screen.dart';
import '../features/orders/presentation/order_detail_screen.dart';
import '../features/orders/presentation/order_success_screen.dart';
import '../features/orders/presentation/shipper_route_map_screen.dart';
import '../features/reservation/presentation/my_reservations_screen.dart';
import '../features/reservation/presentation/reservation_booking_screen.dart';
import '../features/shipper/shipper_home_screen.dart';

/// ============================================================
/// 🧭 GO_ROUTER — auth guard theo role
/// ============================================================
/// - Chưa đăng nhập  → /login
/// - CUSTOMER        → /home
/// - SHIPPER         → /shipper
/// - Manager/Cashier/Kitchen/Waiter → /login (chưa hỗ trợ trong mobile LVTN)
/// ============================================================

class _SplashScreen extends ConsumerWidget {
  const _SplashScreen();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    if (auth.error != null) {
      return Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(auth.error!.message),
                  FilledButton(
                    onPressed: () => ref
                        .read(authControllerProvider.notifier)
                        .retrySession(),
                    child: const Text('Thử lại'),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }
    return const Scaffold(body: Center(child: CircularProgressIndicator()));
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final authNotifier = _AuthRouterRefresh(ref);
  final deepLinkBridge = _DeepLinkRouterBridge(ref);

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: Listenable.merge([authNotifier, deepLinkBridge]),
    debugLogDiagnostics: false,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loc = state.matchedLocation;

      if (auth.status == AuthStatus.unknown) {
        return loc == '/splash' ? null : '/splash';
      }
      if (auth.status == AuthStatus.unauthenticated) {
        return loc == '/login' || loc == '/register' ? null : '/login';
      }
      if (auth.status == AuthStatus.authenticated) {
        final user = auth.user!;
        if (user.role != UserRole.customer && user.role != UserRole.shipper) {
          return loc == '/unsupported' ? null : '/unsupported';
        }
        final pending = deepLinkBridge.consumePending();
        if (pending != null && user.role == UserRole.customer) return pending;
        if (loc == '/splash' || loc == '/login' || loc == '/register') {
          return _homePathFor(user.role);
        }
        if (loc.startsWith('/shipper') && user.role != UserRole.shipper) {
          return _homePathFor(user.role);
        }
        if (loc.startsWith('/payment') && user.role != UserRole.customer) {
          return _homePathFor(user.role);
        }
        if ((loc.startsWith('/home') ||
                loc.startsWith('/chat') ||
                loc.startsWith('/live-chat') ||
                loc.startsWith('/menu') ||
                loc.startsWith('/cart') ||
                loc.startsWith('/checkout') ||
                loc.startsWith('/orders') ||
                loc.startsWith('/account') ||
                loc.startsWith('/reservation')) &&
            user.role != UserRole.customer) {
          return _homePathFor(user.role);
        }
      }
      return null;
    },
    routes: [
      GoRoute(
        path: '/register',
        builder: (_, __) => const LoginScreen(register: true),
      ),
      GoRoute(
        path: '/unsupported',
        builder: (_, __) => Scaffold(
          appBar: AppBar(title: const Text('Tài khoản nhân viên')),
          body: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text('Vui lòng dùng trang web cho vai trò này.'),
                FilledButton(
                  onPressed: () =>
                      ref.read(authControllerProvider.notifier).logout(),
                  child: const Text('Đăng xuất'),
                ),
              ],
            ),
          ),
        ),
      ),
      GoRoute(path: '/splash', builder: (_, __) => const _SplashScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/home', builder: (_, __) => const CustomerHomeScreen()),
      GoRoute(path: '/chat', builder: (_, __) => const ChatScreen()),
      GoRoute(
        path: '/live-chat',
        builder: (_, __) => const LiveChatScreen(),
      ),

      // Deep link target: micay://payment/return?code=...&order_id=...
      // Có thể truy cập trực tiếp khi backend redirect về app.
      GoRoute(
        path: '/payment/return',
        builder: (_, state) {
          final code = state.uri.queryParameters['code'] ?? '';
          final orderId =
              int.tryParse(state.uri.queryParameters['order_id'] ?? '');
          return VNPayReturnScreen(paymentCode: code, orderId: orderId);
        },
      ),

      GoRoute(
        path: '/menu/:slug',
        builder: (_, state) {
          return MenuDetailLoader(slug: state.pathParameters['slug']!);
        },
      ),
      GoRoute(path: '/cart', builder: (_, __) => const CartScreen()),
      GoRoute(path: '/account', builder: (_, __) => const AccountScreen()),
      GoRoute(path: '/checkout', builder: (_, __) => const CheckoutScreen()),
      GoRoute(path: '/orders', builder: (_, __) => const OrdersListScreen()),
      GoRoute(
        path: '/orders/:id/success',
        builder: (_, state) => OrderSuccessScreen(
          orderId: int.parse(state.pathParameters['id']!),
        ),
      ),
      GoRoute(
        path: '/orders/:id/payment',
        builder: (_, state) =>
            PaymentScreen(orderId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: '/orders/:id',
        builder: (_, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
          return OrderDetailScreen(orderId: id);
        },
      ),
      GoRoute(
        path: '/orders/:id/route',
        builder: (_, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
          return ShipperRouteMapScreen(orderId: id);
        },
      ),
      GoRoute(
        path: '/reservation',
        builder: (_, __) => const ReservationBookingScreen(),
      ),
      GoRoute(
        path: '/reservations/me',
        builder: (_, __) => const MyReservationsScreen(),
      ),
      GoRoute(path: '/shipper', builder: (_, __) => const ShipperHomeScreen()),
    ],
    errorBuilder: (_, state) => Scaffold(
      appBar: AppBar(title: const Text('Lỗi')),
      body: Center(child: Text(state.error?.toString() ?? 'Đã có lỗi')),
    ),
  );
});

String _homePathFor(UserRole role) {
  switch (role) {
    case UserRole.customer:
      return '/home';
    case UserRole.shipper:
      return '/shipper';
    default:
      return '/login';
  }
}

class _AuthRouterRefresh extends ChangeNotifier {
  _AuthRouterRefresh(this._ref) {
    _sub = _ref.listen<AuthState>(
      authControllerProvider,
      (_, __) => notifyListeners(),
    );
  }
  final Ref _ref;
  late final ProviderSubscription<AuthState> _sub;

  @override
  void dispose() {
    _sub.close();
    super.dispose();
  }
}

/// ============================================================
/// 🌉 DEEP LINK → ROUTER BRIDGE
/// ============================================================
/// Lắng nghe [DeepLinkService.stream] và convert URI thành route path.
/// Khi nhận link `micay://payment/return?code=...&order_id=...` → notify
/// router → redirect handler sẽ xử lý auth guard + điều hướng.
/// ============================================================
class _DeepLinkRouterBridge extends ChangeNotifier {
  _DeepLinkRouterBridge(this._ref) {
    _service = _ref.read(deepLinkServiceProvider);
    _sub = _service.stream.listen(_onIncomingUri);
    final initial = _service.initialUri;
    if (initial != null) _onIncomingUri(initial);
  }
  final Ref _ref;
  late final DeepLinkService _service;
  late final StreamSubscription<Uri> _sub;

  String? _pending;

  void _onIncomingUri(Uri uri) {
    if (uri.scheme != 'micay') return;
    final host = uri.host;
    final path = uri.pathSegments.join('/');
    String? location;
    if (host == 'payment' && path == 'return') {
      final code = uri.queryParameters['code'] ?? '';
      final orderId = uri.queryParameters['order_id'] ?? '';
      location =
          '/payment/return?code=${Uri.encodeComponent(code)}&order_id=$orderId';
    }
    if (location != null) {
      _pending = location;
      notifyListeners();
    }
  }

  String? consumePending() {
    final p = _pending;
    _pending = null;
    return p;
  }

  @override
  void dispose() {
    _sub.cancel();
    super.dispose();
  }
}
