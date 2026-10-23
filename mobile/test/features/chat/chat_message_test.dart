import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/chat/domain/chat_message.dart';

void main() {
  group('ChatMessage.fromJson', () {
    test('đọc tin nhắn người dùng', () {
      final message = ChatMessage.fromJson({
        'id': 12,
        'role': 'user',
        'content': 'Mì cay giá bao nhiêu?',
        'created_at': '2026-10-22T08:00:00+00:00',
      });

      expect(message.id, 12);
      expect(message.role, ChatRole.user);
      expect(message.isUser, isTrue);
      expect(message.content, 'Mì cay giá bao nhiêu?');
      expect(message.createdAt, isNotNull);
    });

    test('đọc tin nhắn trợ lý kèm gợi ý món và hành động nhanh', () {
      final message = ChatMessage.fromJson({
        'id': 13,
        'role': 'assistant',
        'content': 'Mình gợi ý mì cay bò nè.',
        'metadata': {
          'menu_suggestions': [
            {
              'name': 'Mì Cay Bò',
              'slug': 'mi-cay-bo',
              'image_url': '/storage/menu/mi-cay-bo.jpg',
              'description': 'Bò hầm cay với nấm.',
            },
          ],
          'quick_action': {'type': 'reservation_form', 'label': 'Đặt bàn'},
        },
      });

      expect(message.role, ChatRole.assistant);
      expect(message.isUser, isFalse);
      expect(message.hasSuggestions, isTrue);
      expect(message.menuSuggestions.single.slug, 'mi-cay-bo');
      expect(message.menuSuggestions.single.hasImage, isTrue);
      expect(message.quickAction?.isReservation, isTrue);
      expect(message.quickAction?.label, 'Đặt bàn');
    });

    test('bỏ qua gợi ý món thiếu slug', () {
      final message = ChatMessage.fromJson({
        'role': 'assistant',
        'content': 'Xin chào',
        'metadata': {
          'menu_suggestions': [
            {'name': 'Không có slug'},
            {'name': 'Hợp lệ', 'slug': 'mi-cay-bo'},
          ],
        },
      });

      expect(message.menuSuggestions.length, 1);
      expect(message.menuSuggestions.single.slug, 'mi-cay-bo');
    });

    test('metadata rỗng vẫn parse được', () {
      final message = ChatMessage.fromJson({
        'role': 'assistant',
        'content': 'Chào bạn',
      });

      expect(message.content, 'Chào bạn');
      expect(message.hasSuggestions, isFalse);
      expect(message.quickAction, isNull);
    });
  });

  group('QuickAction.tryParse', () {
    test('bỏ qua payload không hợp lệ', () {
      expect(QuickAction.tryParse(null), isNull);
      expect(QuickAction.tryParse('abc'), isNull);
      expect(QuickAction.tryParse(<String, dynamic>{}), isNull);
    });

    test('đọc type và label', () {
      final action = QuickAction.tryParse({
        'type': 'reservation_form',
        'label': 'Đặt bàn',
        'title': 'Đặt bàn tại K-Spicy',
      });

      expect(action, isNotNull);
      expect(action!.type, 'reservation_form');
      expect(action.isReservation, isTrue);
      expect(action.title, 'Đặt bàn tại K-Spicy');
    });
  });

  group('PendingAction', () {
    test('đọc thẻ xác nhận kèm giá', () {
      final action = PendingAction.fromJson({
        'id': 7,
        'action_type': 'cart_add_item',
        'status': 'pending',
        'summary': {
          'type': 'cart_add_item',
          'icon': '🍜',
          'title': 'Thêm vào giỏ hàng',
          'items': [
            {'label': 'Món', 'value': 'Mì Cay Bò'},
            {'label': 'Số lượng', 'value': '2 phần'},
          ],
          'price_breakdown': {
            'unit_price': 45000,
            'topping_price': 5000,
            'quantity': 2,
            'subtotal': 100000,
          },
          'toppings': ['Trứng'],
        },
      });

      expect(action.id, 7);
      expect(action.isPending, isTrue);
      expect(action.summary.title, 'Thêm vào giỏ hàng');
      expect(action.summary.items.length, 2);
      expect(action.summary.items.first.label, 'Món');
      expect(action.summary.priceBreakdown?.subtotal, 100000);
      expect(action.summary.priceBreakdown?.quantity, 2);
      expect(action.summary.toppings, ['Trứng']);
    });

    test('summary thiếu dữ liệu vẫn parse được', () {
      final action = PendingAction.fromJson({
        'id': 9,
        'action_type': 'cart_add_item',
        'status': 'pending',
        'summary': <String, dynamic>{},
      });

      expect(action.summary.title, 'Xác nhận thao tác');
      expect(action.summary.items, isEmpty);
      expect(action.summary.priceBreakdown, isNull);
    });
  });
}
