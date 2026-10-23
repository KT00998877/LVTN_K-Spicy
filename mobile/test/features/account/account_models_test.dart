import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/account/domain/loyalty_models.dart';
import 'package:micay_app/features/account/domain/review_models.dart';

void main() {
  group('LoyaltyOverview.fromJson', () {
    test('đọc hạng hiện tại, hạng kế và tiến trình', () {
      final overview = LoyaltyOverview.fromJson({
        'user': {'id': 7, 'loyalty_points': 340},
        'total_spent': 125000,
        'current': {
          'id': 2,
          'code': 'GOLD',
          'name': 'Vàng',
          'min_spent': 100000,
          'color': '#D4AF37',
          'icon': '🥇',
          'description': 'Hạng thành viên vàng',
        },
        'next': {
          'id': 3,
          'code': 'PLATINUM',
          'name': 'Bạch kim',
          'min_spent': 200000,
        },
        'progress': {
          'current_spent': 125000,
          'next_min_spent': 200000,
          'amount_to_next': 75000,
          'percent': 25.0,
        },
      });

      expect(overview.loyaltyPoints, 340);
      expect(overview.totalSpent, 125000);
      expect(overview.current?.name, 'Vàng');
      expect(overview.current?.color, '#D4AF37');
      expect(overview.next?.name, 'Bạch kim');
      expect(overview.progress?.amountToNext, 75000);
      expect(overview.isMaxTier, isFalse);
    });

    test('đạt hạng cao nhất khi next == null', () {
      final overview = LoyaltyOverview.fromJson({
        'user': {'id': 1, 'loyalty_points': 900},
        'total_spent': 500000,
        'current': {'id': 4, 'code': 'DIAMOND', 'name': 'Kim cương'},
        'next': null,
        'progress': null,
      });

      expect(overview.isMaxTier, isTrue);
      expect(overview.progress, isNull);
    });

    test('thiếu user/next/progress vẫn parse an toàn', () {
      final overview = LoyaltyOverview.fromJson({});

      expect(overview.loyaltyPoints, 0);
      expect(overview.totalSpent, 0);
      expect(overview.current, isNull);
      expect(overview.next, isNull);
      expect(overview.isMaxTier, isTrue);
    });
  });

  group('TierPerks', () {
    test('đọc benefits từ JSON của backend', () {
      final perks = TierPerks.fromJson({
        'point_multiplier': 1.5,
        'free_ship_threshold': 50000,
        'extra_discount_pct': 5,
      });

      expect(perks.hasMultiplier, isTrue);
      expect(perks.pointMultiplier, 1.5);
      expect(perks.hasFreeShip, isTrue);
      expect(perks.freeShipThreshold, 50000);
      expect(perks.hasDiscount, isTrue);
      expect(perks.extraDiscountPct, 5);
    });

    test('free_ship_threshold = 0 nghĩa là miễn phí ship mọi đơn', () {
      final perks = TierPerks.fromJson({'free_ship_threshold': 0});

      expect(perks.hasFreeShip, isTrue);
      expect(perks.freeShipThreshold, 0);
    });

    test('mặc định không có quyền lợi nào khi benefits null', () {
      final perks = TierPerks.fromJson(null);

      expect(perks.hasMultiplier, isFalse);
      expect(perks.hasFreeShip, isFalse);
      expect(perks.hasDiscount, isFalse);
      expect(perks.pointMultiplierOrNull, isNull);
      expect(perks.extraDiscountPctOrNull, isNull);
    });
  });

  group('LoyaltyTier', () {
    test('parse benefits lồng vào perks', () {
      final tier = LoyaltyTier.fromJson({
        'id': 3,
        'code': 'PLATINUM',
        'name': 'Bạch kim',
        'min_spent': 200000,
        'color': '#9CA3AF',
        'icon': '💎',
        'description': 'Ưu đãi cao nhất',
        'benefits': {
          'point_multiplier': 2.0,
          'free_ship_threshold': null,
          'extra_discount_pct': 10,
        },
      });

      expect(tier.minSpent, 200000);
      expect(tier.perks.pointMultiplier, 2.0);
      expect(tier.perks.hasFreeShip, isFalse);
      expect(tier.perks.extraDiscountPct, 10);
    });
  });

  group('LoyaltyEntry', () {
    test('phân biệt cộng điểm và dùng điểm', () {
      final earn = LoyaltyEntry.fromJson({
        'id': 1,
        'points': 120,
        'balance_after': 120,
        'reason': 'Xác nhận nhận đơn #A1',
        'created_at': '2026-10-01T10:00:00+00:00',
      });
      final redeem = LoyaltyEntry.fromJson({
        'id': 2,
        'points': -50,
        'balance_after': 70,
        'reason': 'Dùng điểm thanh toán',
      });

      expect(earn.isEarn, isTrue);
      expect(earn.points, 120);
      expect(redeem.isEarn, isFalse);
      expect(redeem.points, -50);
      expect(redeem.createdAt, isNull);
    });
  });

  group('MyReview', () {
    test('parse review kèm món ăn và ảnh', () {
      final review = MyReview.fromJson({
        'id': 55,
        'rating': 5,
        'comment': 'Mì cay ngon, cay đúng ý!',
        'is_hidden': false,
        'created_at': '2026-10-20T09:30:00+00:00',
        'photos': [
          {'id': 1, 'url': '/storage/review-photos/a.jpg'},
        ],
        'menu_item': {
          'id': 3,
          'name': 'Mì Cay Tôm',
          'slug': 'mi-cay-tom',
          'image_url': '/storage/menu/mi-cay-tom.jpg',
        },
      });

      expect(review.rating, 5);
      expect(review.isHidden, isFalse);
      expect(review.photos.length, 1);
      expect(review.menuItem?.name, 'Mì Cay Tôm');
      expect(review.menuItem?.slug, 'mi-cay-tom');
      expect(review.createdAt, isNotNull);
    });

    test('review bị ẩn vẫn parse được', () {
      final review = MyReview.fromJson({
        'id': 56,
        'rating': 1,
        'is_hidden': true,
        'comment': 'spam',
      });

      expect(review.isHidden, isTrue);
      expect(review.photos, isEmpty);
      expect(review.menuItem, isNull);
    });
  });

  group('PageMeta', () {
    test('hasMore chỉ đúng khi chưa tới trang cuối', () {
      expect(PageMeta.fromJson({
        'current_page': 1,
        'last_page': 3,
        'total': 25,
      }).hasMore, isTrue);

      expect(PageMeta.fromJson({
        'current_page': 3,
        'last_page': 3,
        'total': 25,
      }).hasMore, isFalse);

      expect(PageMeta.empty.hasMore, isFalse);
    });
  });
}