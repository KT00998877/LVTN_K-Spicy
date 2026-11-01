// ============================================================
// 🍜 MENU MODELS — phản ánh response từ /api/menu
// ============================================================
//
// Backend trả về:
//   { id, name, slug, description, image_url, price, spicy_level,
//     size, preparation_minutes, category_name, category_slug,
//     is_available? }
//
// ============================================================

import '../../../core/api/json_number.dart';

class MenuItem {
  MenuItem({
    required this.id,
    required this.name,
    required this.slug,
    this.description,
    required this.imageUrl,
    required this.price,
    this.priceFormatted,
    this.spicyLevel = 0,
    this.size,
    this.preparationMinutes,
    this.categoryName,
    this.categorySlug,
    this.isAvailable = true,
    this.suggestions = const [],
  });

  final int id;
  final String name;
  final String slug;
  final String? description;
  final String imageUrl;
  final double price;
  final String? priceFormatted;
  final int spicyLevel;
  final String? size; // SMALL | LARGE | null
  final int? preparationMinutes;
  final String? categoryName;
  final String? categorySlug;
  final bool isAvailable;
  final List<MenuSuggestion> suggestions;

  factory MenuItem.fromJson(Map<String, dynamic> json) {
    return MenuItem(
      id: json['id'] as int,
      name: (json['name'] ?? '').toString(),
      slug: (json['slug'] ?? '').toString(),
      description: json['description']?.toString(),
      imageUrl: (json['image_url'] ?? '').toString(),
      price: jsonPrice(json['price'], 'menu.price'),
      priceFormatted: json['price_formatted']?.toString(),
      spicyLevel: (json['spicy_level'] as num?)?.toInt() ?? 0,
      size: json['size']?.toString(),
      preparationMinutes: (json['preparation_minutes'] as num?)?.toInt(),
      categoryName: json['category_name']?.toString(),
      categorySlug: json['category_slug']?.toString(),
      isAvailable: json['is_available'] as bool? ?? true,
      suggestions: (json['suggestions'] as List? ?? const [])
          .whereType<Map>()
          .map((item) => MenuSuggestion.fromJson(Map<String, dynamic>.from(item)))
          .toList(),
    );
  }
}

class MenuSuggestion {
  const MenuSuggestion({
    required this.id,
    required this.title,
    required this.comboPrice,
    required this.suggestedItemName,
    required this.suggestedImageUrl,
  });

  final int id;
  final String title;
  final String comboPrice;
  final String suggestedItemName;
  final String suggestedImageUrl;

  factory MenuSuggestion.fromJson(Map<String, dynamic> json) {
    final suggested = json['suggested_item'] as Map? ?? const {};
    return MenuSuggestion(
      id: (json['id'] as num).toInt(),
      title: (json['title'] ?? '').toString(),
      comboPrice: (json['combo_price_formatted'] ?? '').toString(),
      suggestedItemName: (suggested['name'] ?? '').toString(),
      suggestedImageUrl: (suggested['image_url'] ?? '').toString(),
    );
  }
}

/// Topping (cũng là menu_item với category = 'topping-them')
class Topping {
  Topping({
    required this.id,
    required this.name,
    required this.price,
    this.priceFormatted,
  });

  final int id;
  final String name;
  final double price;
  final String? priceFormatted;

  factory Topping.fromJson(Map<String, dynamic> json) => Topping(
    id: json['id'] as int,
    name: (json['name'] ?? '').toString(),
    price: jsonPrice(json['price'], 'topping.price'),
    priceFormatted: json['price_formatted']?.toString(),
  );
}

/// Cấp độ cay (UI selector dùng)
class SpicyLevel {
  const SpicyLevel({required this.value, required this.label});
  final int value;
  final String label;

  static final List<SpicyLevel> levels = const <SpicyLevel>[
    SpicyLevel(value: 0, label: 'Không cay'),
    SpicyLevel(value: 1, label: 'Cấp 1'),
    SpicyLevel(value: 2, label: 'Cấp 2'),
    SpicyLevel(value: 3, label: 'Cấp 3'),
    SpicyLevel(value: 4, label: 'Cấp 4'),
    SpicyLevel(value: 5, label: 'Cấp 5'),
  ];

  /// Số icon ớt tương ứng với cấp độ
  static String iconFor(int level) => '🌶' * level;
}
