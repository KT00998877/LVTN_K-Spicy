// =============================================================
// 🏠 HOME MODELS — phản ánh response từ /api/home/*
// Đồng bộ với HomeService.php + front-end types/home.ts
// =============================================================

/// Thông tin nhà hàng
class RestaurantInfo {
  RestaurantInfo({
    required this.name,
    required this.slogan,
    required this.description,
    required this.phone,
    required this.address,
    required this.openHours,
    this.social = const RestaurantSocial(),
  });

  final String name;
  final String slogan;
  final String description;
  final String phone;
  final String address;
  final String openHours;
  final RestaurantSocial social;

  factory RestaurantInfo.fromJson(Map<String, dynamic> json) {
    final social = (json['social'] as Map?)?.cast<String, dynamic>() ?? {};
    return RestaurantInfo(
      name: (json['name'] ?? '').toString(),
      slogan: (json['slogan'] ?? '').toString(),
      description: (json['description'] ?? '').toString(),
      phone: (json['phone'] ?? '').toString(),
      address: (json['address'] ?? '').toString(),
      openHours: (json['open_hours'] ?? '').toString(),
      social: RestaurantSocial.fromJson(social),
    );
  }
}

class RestaurantSocial {
  const RestaurantSocial({
    this.facebook = '',
    this.instagram = '',
    this.tiktok = '',
  });

  final String facebook;
  final String instagram;
  final String tiktok;

  factory RestaurantSocial.fromJson(Map<String, dynamic> json) {
    return RestaurantSocial(
      facebook: (json['facebook'] ?? '').toString(),
      instagram: (json['instagram'] ?? '').toString(),
      tiktok: (json['tiktok'] ?? '').toString(),
    );
  }
}

/// Thống kê hero
class HomeStats {
  HomeStats({
    required this.menuItems,
    required this.categories,
    required this.tables,
    required this.tablesAvailable,
    required this.happyCustomers,
    required this.spicyLevels,
  });

  final int menuItems;
  final int categories;
  final int tables;
  final int tablesAvailable;
  final int happyCustomers;
  final int spicyLevels;

  factory HomeStats.fromJson(Map<String, dynamic> json) {
    return HomeStats(
      menuItems: (json['menu_items'] as num?)?.toInt() ?? 0,
      categories: (json['categories'] as num?)?.toInt() ?? 0,
      tables: (json['tables'] as num?)?.toInt() ?? 0,
      tablesAvailable: (json['tables_available'] as num?)?.toInt() ?? 0,
      happyCustomers: (json['happy_customers'] as num?)?.toInt() ?? 0,
      spicyLevels: (json['spicy_levels'] as num?)?.toInt() ?? 6,
    );
  }
}

/// Danh mục kèm số món
class HomeCategory {
  HomeCategory({
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

  factory HomeCategory.fromJson(Map<String, dynamic> json) {
    return HomeCategory(
      id: (json['id'] as num).toInt(),
      name: (json['name'] ?? '').toString(),
      slug: (json['slug'] ?? '').toString(),
      description: json['description']?.toString(),
      itemsCount: (json['items_count'] as num?)?.toInt() ?? 0,
    );
  }
}

/// Món trong best_sellers / featured (dạng card nhẹ)
class HomeMenuItem {
  HomeMenuItem({
    required this.id,
    required this.name,
    required this.slug,
    required this.description,
    required this.imageUrl,
    required this.price,
    required this.priceFormatted,
    required this.spicyLevel,
    required this.categoryName,
    required this.categorySlug,
    this.size,
    this.preparationMinutes,
    this.soldCount,
  });

  final int id;
  final String name;
  final String slug;
  final String? description;
  final String imageUrl;
  final double price;
  final String priceFormatted;
  final int spicyLevel;
  final String categoryName;
  final String categorySlug;
  final String? size;
  final int? preparationMinutes;
  final int? soldCount;

  factory HomeMenuItem.fromJson(Map<String, dynamic> json) {
    return HomeMenuItem(
      id: (json['id'] as num).toInt(),
      name: (json['name'] ?? '').toString(),
      slug: (json['slug'] ?? '').toString(),
      description: json['description']?.toString(),
      imageUrl: (json['image_url'] ?? '').toString(),
      price: (json['price'] as num?)?.toDouble() ?? 0,
      priceFormatted: (json['price_formatted'] ?? '').toString(),
      spicyLevel: (json['spicy_level'] as num?)?.toInt() ?? 0,
      categoryName: (json['category_name'] ?? '').toString(),
      categorySlug: (json['category_slug'] ?? '').toString(),
      size: json['size']?.toString(),
      preparationMinutes:
          (json['preparation_minutes'] as num?)?.toInt(),
      soldCount: (json['sold_count'] as num?)?.toInt(),
    );
  }
}
