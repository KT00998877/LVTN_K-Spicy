// =============================================================
// 🍜 MENU CATEGORY MODEL — phản ánh response từ /api/home/categories
// Đồng bộ với HomeService::getCategoriesWithCount()
// =============================================================
//
// Domain layer KHÔNG phụ thuộc vào Flutter widgets.
// Trả về chỉ một `emoji` cho UI; nếu cần IconData thì presentation
// sẽ mapping riêng qua extension.
// =============================================================

class MenuCategory {
  const MenuCategory({
    required this.id,
    required this.name,
    required this.slug,
    required this.description,
    required this.itemsCount,
  });

  final int id;
  final String name;
  final String slug;
  final String? description;
  final int itemsCount;

  factory MenuCategory.fromJson(Map<String, dynamic> json) {
    return MenuCategory(
      id: (json['id'] as num).toInt(),
      name: (json['name'] ?? '').toString(),
      slug: (json['slug'] ?? '').toString(),
      description: json['description']?.toString(),
      itemsCount: (json['items_count'] as num?)?.toInt() ?? 0,
    );
  }

  /// Emoji đại diện category (heuristic theo slug/name).
  /// UI layer sẽ tự chọn size/color.
  String emojiFor() {
    final s = '$slug $name'.toLowerCase();
    if (s.contains('mì') || s.contains('mi ') || s.contains('noodle')) {
      return '🍜';
    }
    if (s.contains('tokbokki') || s.contains('topokki')) {
      return '🌶';
    }
    if (s.contains('kimbap') || s.contains('gimbap')) {
      return '🍙';
    }
    if (s.contains('gà') || s.contains('ga ') || s.contains('chicken')) {
      return '🍗';
    }
    if (s.contains('combo')) {
      return '🎁';
    }
    if (s.contains('uống') || s.contains('drink') || s.contains('uong')) {
      return '🥤';
    }
    if (s.contains('tráng miệng') ||
        s.contains('dessert') ||
        s.contains('trang mieng')) {
      return '🍡';
    }
    if (s.contains('phụ') || s.contains('side')) {
      return '🍢';
    }
    return '🍽';
  }
}
