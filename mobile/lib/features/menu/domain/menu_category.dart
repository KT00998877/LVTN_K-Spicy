// =============================================================
// 🍜 MENU CATEGORY MODEL — phản ánh response từ /api/home/categories
// Đồng bộ với HomeService::getCategoriesWithCount()
// =============================================================
//
// Domain layer KHÔNG phụ thuộc vào Flutter widgets.
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

}
