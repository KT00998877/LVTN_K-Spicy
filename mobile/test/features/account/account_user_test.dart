import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/account/presentation/security_tab.dart';
import 'package:micay_app/features/auth/domain/app_user.dart';

void main() {
  group('AppUser.fromJson', () {
    test('đọc avatar_url, tier và tổng chi tiêu từ /api/auth/me', () {
      final user = AppUser.fromJson({
        'id': 12,
        'name': 'Nguyễn Văn A',
        'full_name': 'Nguyễn Văn An',
        'email': 'a@example.com',
        'phone': '0901234567',
        'avatar_url': '/storage/avatars/12-abcd.jpg',
        'loyalty_points': 340,
        'total_spent': 125000,
        'is_active': true,
        'created_at': '2026-01-15T08:00:00+00:00',
        'tier': {
          'id': 2,
          'code': 'GOLD',
          'name': 'Vàng',
          'color': '#D4AF37',
          'icon': '🥇',
          'point_multiplier': 1.5,
        },
      });

      expect(user.name, 'Nguyễn Văn A');
      expect(user.fullName, 'Nguyễn Văn An');
      expect(user.avatarUrl, '/storage/avatars/12-abcd.jpg');
      expect(user.tier?.name, 'Vàng');
      expect(user.tier?.pointMultiplier, 1.5);
      expect(user.totalSpent, 125000);
      expect(user.loyaltyPoints, 340);
      expect(user.isActive, isTrue);
      expect(user.createdAt, isNotNull);
    });

    test('user chưa upload avatar thì avatarUrl null, không phải chuỗi rỗng', () {
      final user = AppUser.fromJson({
        'id': 13,
        'name': 'Trần B',
        'email': 'b@example.com',
        'avatar_url': null,
      });

      expect(user.avatarUrl, isNull);
      expect(user.fullName, isNull);
      expect(user.isActive, isTrue);
      expect(user.tier, isNull);
    });

    test('role CUSTOMER mặc định khi thiếu roles', () {
      final user = AppUser.fromJson({
        'id': 14,
        'name': 'Lê C',
        'email': 'c@example.com',
      });

      expect(user.role, UserRole.customer);
      expect(user.role.isCustomer, isTrue);
    });
  });

  group('AppUser.toJson → fromJson round-trip', () {
    test('giữ nguyên avatar và tier sau khi lưu cache', () {
      final original = AppUser.fromJson({
        'id': 15,
        'name': 'Phạm D',
        'full_name': 'Phạm Dung',
        'email': 'd@example.com',
        'avatar_url': '/storage/avatars/15-ef01.webp',
        'loyalty_points': 90,
        'total_spent': 45000,
        'tier': {
          'id': 1,
          'code': 'SILVER',
          'name': 'Bạc',
          'color': '#C0C0C0',
        },
      });

      final restored = AppUser.fromJson(original.toJson());

      expect(restored.id, original.id);
      expect(restored.fullName, original.fullName);
      expect(restored.avatarUrl, original.avatarUrl);
      expect(restored.tier?.code, 'SILVER');
      expect(restored.totalSpent, 45000);
      expect(restored.loyaltyPoints, 90);
    });
  });

  group('validateNewPassword', () {
    test('chấp nhận mật khẩu đúng luật', () {
      expect(validateNewPassword('Spicy@2026'), isNull);
      expect(validateNewPassword('aB3\$xyzw'), isNull);
    });

    test('bắt lỗi từng luật thiếu', () {
      expect(validateNewPassword('short1!'), contains('8 ký tự'));
      expect(validateNewPassword('spicy@2026'), contains('chữ hoa'));
      expect(validateNewPassword('SPICY@2026'), contains('chữ thường'));
      expect(validateNewPassword('Spicy@abcd'), contains('chữ số'));
      expect(validateNewPassword('Spicy2026'), contains('ký hiệu đặc biệt'));
      expect(validateNewPassword(''), contains('Vui lòng nhập'));
    });
  });
}