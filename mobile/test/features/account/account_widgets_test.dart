import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/account/data/account_repository.dart';
import 'package:micay_app/features/account/domain/loyalty_models.dart';
import 'package:micay_app/features/account/domain/review_models.dart';
import 'package:micay_app/features/account/presentation/account_controller.dart';
import 'package:micay_app/app/theme.dart' show AppTheme;
import 'package:micay_app/features/auth/data/auth_repository.dart';
import 'package:micay_app/features/auth/presentation/auth_controller.dart';
import 'package:micay_app/features/account/presentation/security_tab.dart';
import 'package:micay_app/features/account/presentation/widgets/account_widgets.dart';

/// Fake repository trả dữ liệu cố định — không chạm network.
class _FakeAccountRepository extends AccountRepository {
  _FakeAccountRepository() : super(Dio());

  @override
  Future<List<LoyaltyTier>> fetchTiers() async => const [
        LoyaltyTier(
          id: 1,
          code: 'BRONZE',
          name: 'Đồng',
          minSpent: 0,
          color: '#CD7F32',
          icon: '🥉',
          description: 'Hạng khởi đầu',
          perks: TierPerks(pointMultiplier: 1.0),
        ),
        LoyaltyTier(
          id: 2,
          code: 'GOLD',
          name: 'Vàng',
          minSpent: 100000,
          color: '#D4AF37',
          icon: '🥇',
          description: 'Tích điểm nhanh hơn',
          perks: TierPerks(pointMultiplier: 1.5, extraDiscountPct: 5),
        ),
      ];

  @override
  Future<LoyaltyOverview> fetchMyTier() async => const LoyaltyOverview(
        loyaltyPoints: 340,
        totalSpent: 125000,
        current: LoyaltyTier(
          id: 1,
          code: 'BRONZE',
          name: 'Đồng',
          color: '#CD7F32',
          icon: '🥉',
        ),
        next: LoyaltyTier(id: 2, code: 'GOLD', name: 'Vàng', minSpent: 100000),
        progress: TierProgress(
          currentSpent: 125000,
          nextMinSpent: 100000,
          amountToNext: 0,
          percent: 125,
        ),
      );

  @override
  Future<({List<LoyaltyEntry> items, PageMeta meta})> fetchPointLedger({
    int page = 1,
    int perPage = 20,
  }) async =>
      (items: const <LoyaltyEntry>[], meta: PageMeta.empty);

  @override
  Future<({List<MyReview> items, PageMeta meta})> fetchMyReviews({
    int page = 1,
    int perPage = 10,
  }) async =>
      (items: const <MyReview>[], meta: PageMeta.empty);
}

/// AuthController giả cho test: không đọc secure storage nên khởi tạo
/// đồng bộ, tránh NotInitializedError trong widget test.
class _NoopAuthController extends AuthController {
  _NoopAuthController() : super(AuthRepository(Dio()), bootstrap: false);
}

/// Controller cục bộ cho SecurityTab: không chạm secure storage nên test
/// không phụ thuộc phiên đăng nhập thật.
class _TestPasswordController extends PasswordController {
  _TestPasswordController() : super(_NoopAuthController());
}

/// Dùng viewport iPhone SE (375×667) — hẹp nhất trong danh sách thiết bị
/// rule §22 yêu cầu. ListView build lazy nên phần dưới form không có sẵn;
/// vì vậy chiều cao dùng rộng (2000) để toàn bộ field được build, còn
/// chiều ngang giữ đúng 375 để bắt lỗi tràn bố cục.
Future<void> _pumpOnPhone(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(375, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(_wrap(child));
  await tester.pumpAndSettle();
}

/// SecurityTab / các tab con KHÔNG tự bọc Scaffold — chúng được nhúng vào
/// TabBarView bên trong `AccountScreen`. Test phải bọc Scaffold để có
/// Material ancestor cho TextFormField.
Widget _wrap(Widget child) => ProviderScope(
      overrides: [
        accountRepositoryProvider.overrideWithValue(_FakeAccountRepository()),
        passwordControllerProvider.overrideWith(
          (ref) => _TestPasswordController(),
        ),
      ],
      child: MaterialApp(
        theme: AppTheme.light(),
        home: Scaffold(body: child),
      ),
    );

void main() {
  group('TierBadge', () {
    testWidgets('hiện tên hạng và màu lấy từ backend', (tester) async {
      await tester.pumpWidget(_wrap(const TierBadge(
        name: 'Vàng',
        colorHex: '#D4AF37',
        icon: '🥇',
      )));

      expect(find.text('Vàng'), findsOneWidget);
      expect(find.text('🥇'), findsOneWidget);
    });

    testWidgets('color dạng #RRGGBB được parse thành ARGB', (tester) async {
      expect(TierBadge.resolveColor('#D4AF37').toARGB32(), 0xFFD4AF37);
      // Không có dấu # vẫn phải hợp lệ.
      expect(TierBadge.resolveColor('D4AF37').toARGB32(), 0xFFD4AF37);
    });

    testWidgets('màu rác thì fallback về màu mặc định', (tester) async {
      expect(TierBadge.resolveColor(null).toARGB32(), 0xFFB08D57);
      expect(TierBadge.resolveColor('không-hợp-lệ').toARGB32(), 0xFFB08D57);
      expect(TierBadge.resolveColor('').toARGB32(), 0xFFB08D57);
    });

    testWidgets('không có tier thì không render gì', (tester) async {
      await tester.pumpWidget(_wrap(const TierBadge()));
      expect(find.byType(TierBadge), findsOneWidget);
      expect(find.byType(Text), findsNothing);
    });
  });

  group('SecurityTab', () {
    testWidgets('render đủ 3 trường mật khẩu + nút đăng xuất', (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      expect(find.text('Đổi mật khẩu'), findsOneWidget);
      expect(find.text('Mật khẩu hiện tại'), findsOneWidget);
      expect(find.text('Mật khẩu mới'), findsOneWidget);
      expect(find.text('Xác nhận mật khẩu mới'), findsOneWidget);
      expect(find.text('Lưu mật khẩu mới'), findsOneWidget);
      expect(find.text('Đăng xuất khỏi tài khoản'), findsOneWidget);
    });

    testWidgets('checklist chỉ hiện sau khi nhập mật khẩu mới',
        (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      expect(find.text('Ít nhất 8 ký tự'), findsNothing);

      await tester.enterText(
        find.byType(TextFormField).at(1),
        'a',
      );
      await tester.pump();

      expect(find.text('Ít nhất 8 ký tự'), findsOneWidget);
      expect(find.text('Có ký hiệu đặc biệt'), findsOneWidget);
    });

    testWidgets('mật khẩu mạnh tick hết luật', (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      await tester.enterText(
        find.byType(TextFormField).at(1),
        'Spicy@2026',
      );
      await tester.pump();

      expect(find.byIcon(Icons.check_circle_rounded), findsNWidgets(4));
      expect(find.text('Mạnh'), findsOneWidget);
    });

    testWidgets('báo lỗi khi xác nhận không khớp', (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      await tester.enterText(
        find.byType(TextFormField).at(0),
        'Old@1234',
      );
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'Spicy@2026',
      );
      await tester.enterText(
        find.byType(TextFormField).at(2),
        'Khac@2026',
      );
      await tester.tap(find.text('Lưu mật khẩu mới'));
      await tester.pump();

      expect(find.text('Mật khẩu xác nhận không khớp.'), findsOneWidget);
    });

    testWidgets('báo lỗi khi mật khẩu mới chưa đạt luật', (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      await tester.enterText(
        find.byType(TextFormField).at(0),
        'Old@1234',
      );
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'weak',
      );
      await tester.enterText(
        find.byType(TextFormField).at(2),
        'weak',
      );
      await tester.tap(find.text('Lưu mật khẩu mới'));
      await tester.pump();

      expect(find.text('Mật khẩu phải có ít nhất 8 ký tự.'), findsOneWidget);
    });

    testWidgets('nút ẩn/hiện mật khẩu đổi icon', (tester) async {
      await _pumpOnPhone(tester, const SecurityTab());

      // Cả 3 trường đều đang ẩn mật khẩu.
      expect(find.byIcon(Icons.visibility_outlined), findsNWidgets(3));

      await tester.tap(find.byIcon(Icons.visibility_outlined).first);
      await tester.pump();

      // Trường vừa bấm chuyển sang hiện; 2 trường còn lại vẫn ẩn.
      expect(find.byIcon(Icons.visibility_off_outlined), findsOneWidget);
      expect(find.byIcon(Icons.visibility_outlined), findsNWidgets(2));
    });
  });

  group('UserAvatar', () {
    testWidgets('chưa có ảnh thì hiện chữ cái đầu của full_name',
        (tester) async {
      await tester.pumpWidget(
        _wrap(const UserAvatar(name: 'Nguyễn Văn An', size: 96)),
      );

      expect(find.text('N'), findsOneWidget);
      expect(find.byIcon(Icons.photo_camera_rounded), findsNothing);
    });

    testWidgets('tên rỗng thì dùng "?" thay vì crash', (tester) async {
      await tester.pumpWidget(
        _wrap(const UserAvatar(name: '', size: 96)),
      );

      expect(find.text('?'), findsOneWidget);
    });

    testWidgets('showCameraBadge bật nút máy ảnh', (tester) async {
      await tester.pumpWidget(_wrap(const UserAvatar(
          name: 'Nguyễn Văn An',
          size: 96,
          showCameraBadge: true,
        )));

      expect(find.byIcon(Icons.photo_camera_rounded), findsOneWidget);
    });
  });
}
