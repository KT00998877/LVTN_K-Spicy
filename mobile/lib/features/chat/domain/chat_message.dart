/// ============================================================
/// 💬 CHAT MODELS — kiểu dữ liệu cho hội thoại với trợ lý
/// ============================================================
///
/// Trước đây màn chat dùng `Map<String, dynamic>` thô cho mọi thứ, nên mỗi
/// chỗ đọc tin nhắn phải tự ép kiểu và dễ vỡ khi backend đổi. Các model ở
/// đây giữ đúng hợp đồng của API và tập trung việc parse một lần.
library;

/// Vai trò của một tin nhắn trong hội thoại.
enum ChatRole { user, assistant }

/// Một tin nhắn trong hội thoại.
class ChatMessage {
  const ChatMessage({
    required this.role,
    required this.content,
    this.id,
    this.createdAt,
    this.menuSuggestions = const [],
    this.quickAction,
    this.animate = false,
  });

  final int? id;
  final ChatRole role;
  final String content;
  final DateTime? createdAt;

  /// Các món trợ lý gợi ý — hiển thị dưới câu trả lời.
  final List<MenuSuggestion> menuSuggestions;

  /// Hành động nhanh (ví dụ: mở form đặt bàn).
  final QuickAction? quickAction;

  /// Tin nhắn vừa tới nên hiệu ứng gõ chữ từ từ.
  final bool animate;

  bool get isUser => role == ChatRole.user;

  /// Tin nhắn của trợ lý có kèm gợi ý món ăn hay không.
  bool get hasSuggestions => menuSuggestions.isNotEmpty;

  /// Đọc tin nhắn từ API (GET /chatbot/sessions/{id}) hoặc từ response
  /// gửi tin (POST .../messages).
  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    final metadata = json['metadata'];
    final meta = metadata is Map
        ? Map<String, dynamic>.from(metadata)
        : const <String, dynamic>{};

    return ChatMessage(
      id: json['id'] as int?,
      role: json['role'] == 'user' ? ChatRole.user : ChatRole.assistant,
      content: (json['content'] ?? '').toString(),
      createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()),
      menuSuggestions: _parseSuggestions(meta['menu_suggestions']),
      quickAction: QuickAction.tryParse(meta['quick_action']),
    );
  }

  static List<MenuSuggestion> _parseSuggestions(Object? raw) {
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((e) => MenuSuggestion.fromJson(Map<String, dynamic>.from(e)))
        .where((s) => s.slug.isNotEmpty)
        .toList();
  }
}

/// Một món ăn được trợ lý gợi ý.
class MenuSuggestion {
  const MenuSuggestion({
    required this.name,
    required this.slug,
    this.imageUrl,
    this.description,
  });

  final String name;
  final String slug;
  final String? imageUrl;
  final String? description;

  bool get hasImage => imageUrl != null && imageUrl!.isNotEmpty;

  factory MenuSuggestion.fromJson(Map<String, dynamic> json) => MenuSuggestion(
        name: (json['name'] ?? '').toString(),
        slug: (json['slug'] ?? '').toString(),
        imageUrl: json['image_url'] as String?,
        description: json['description'] as String?,
      );
}

/// Hành động nhanh trợ lý đề nghị.
class QuickAction {
  const QuickAction({required this.type, required this.label, this.title});

  final String type;
  final String label;
  final String? title;

  /// Đặt bàn — mở form đặt bàn của ứng dụng.
  static const String reservationForm = 'reservation_form';

  bool get isReservation => type == reservationForm;

  static QuickAction? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final json = Map<String, dynamic>.from(raw);
    final type = (json['type'] ?? '').toString();
    if (type.isEmpty) return null;
    return QuickAction(
      type: type,
      label: (json['label'] ?? '').toString(),
      title: json['title'] as String?,
    );
  }
}

/// Một thao tác đang chờ khách xác nhận.
class PendingAction {
  const PendingAction({
    required this.id,
    required this.actionType,
    required this.status,
    required this.summary,
  });

  final int id;
  final String actionType;
  final String status;
  final ConfirmationSummary summary;

  bool get isPending => status == 'pending';

  factory PendingAction.fromJson(Map<String, dynamic> json) => PendingAction(
        id: json['id'] as int,
        actionType: (json['action_type'] ?? '').toString(),
        status: (json['status'] ?? 'pending').toString(),
        summary: ConfirmationSummary.fromJson(
          Map<String, dynamic>.from(json['summary'] as Map? ?? const {}),
        ),
      );
}

/// Tóm tắt thao tác để khách đọc trước khi xác nhận.
class ConfirmationSummary {
  const ConfirmationSummary({
    required this.title,
    this.icon = '🍜',
    this.items = const [],
    this.priceBreakdown,
    this.toppings = const [],
  });

  final String title;
  final String icon;
  final List<SummaryItem> items;
  final PriceBreakdown? priceBreakdown;
  final List<String> toppings;

  factory ConfirmationSummary.fromJson(Map<String, dynamic> json) {
    final rawItems = json['items'];
    final items = rawItems is List
        ? rawItems
            .whereType<Map>()
            .map((e) => SummaryItem(
                  label: (e['label'] ?? '').toString(),
                  value: (e['value'] ?? '').toString(),
                ))
            .toList()
        : const <SummaryItem>[];

    final rawPrice = json['price_breakdown'];
    final rawToppings = json['toppings'];

    return ConfirmationSummary(
      title: (json['title'] ?? 'Xác nhận thao tác').toString(),
      icon: (json['icon'] ?? '🍜').toString(),
      items: items,
      priceBreakdown: rawPrice is Map
          ? PriceBreakdown.fromJson(Map<String, dynamic>.from(rawPrice))
          : null,
      toppings: rawToppings is List
          ? rawToppings.map((e) => e.toString()).toList()
          : const <String>[],
    );
  }
}

/// Một dòng "nhãn: giá trị" trong thẻ xác nhận.
class SummaryItem {
  const SummaryItem({required this.label, required this.value});

  final String label;
  final String value;
}

/// Chi tiết giá của thao tác chờ xác nhận.
class PriceBreakdown {
  const PriceBreakdown({
    required this.unitPrice,
    required this.toppingPrice,
    required this.quantity,
    required this.subtotal,
  });

  final double unitPrice;
  final double toppingPrice;
  final int quantity;
  final double subtotal;

  factory PriceBreakdown.fromJson(Map<String, dynamic> json) => PriceBreakdown(
        unitPrice: _toDouble(json['unit_price']),
        toppingPrice: _toDouble(json['topping_price']),
        quantity: (json['quantity'] as num?)?.toInt() ?? 1,
        subtotal: _toDouble(json['subtotal']),
      );

  static double _toDouble(Object? value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
}
