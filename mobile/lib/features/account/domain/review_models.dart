/// ============================================================
/// ⭐ REVIEW MODELS — phản ánh GET /api/reviews/me
/// ============================================================
library;

/// Món ăn mà đánh giá thuộc về (backend trả kèm ở /reviews/me).
class ReviewMenuItem {
  const ReviewMenuItem({
    required this.id,
    required this.name,
    this.slug,
    this.imageUrl,
  });

  final int id;
  final String name;
  final String? slug;
  final String? imageUrl;

  factory ReviewMenuItem.fromJson(Map<String, dynamic> json) => ReviewMenuItem(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: (json['name'] ?? '').toString(),
        slug: json['slug']?.toString(),
        imageUrl: json['image_url']?.toString(),
      );
}

class ReviewPhoto {
  const ReviewPhoto({required this.id, required this.url});

  final int id;
  final String url;

  factory ReviewPhoto.fromJson(Map<String, dynamic> json) => ReviewPhoto(
        id: (json['id'] as num?)?.toInt() ?? 0,
        url: (json['url'] ?? '').toString(),
      );
}

/// Một đánh giá của chính khách hàng đang đăng nhập.
class MyReview {
  const MyReview({
    required this.id,
    required this.rating,
    required this.createdAt,
    this.comment,
    this.isHidden = false,
    this.photos = const [],
    this.menuItem,
  });

  final int id;

  /// 1–5 sao.
  final int rating;
  final String? comment;

  /// Backend giấu review vi phạm → hiển thị badge thay vì nội dung.
  final bool isHidden;
  final DateTime? createdAt;
  final List<ReviewPhoto> photos;
  final ReviewMenuItem? menuItem;

  factory MyReview.fromJson(Map<String, dynamic> json) => MyReview(
        id: (json['id'] as num?)?.toInt() ?? 0,
        rating: (json['rating'] as num?)?.toInt() ?? 0,
        comment: json['comment']?.toString(),
        isHidden: json['is_hidden'] == true,
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
        photos: json['photos'] is List
            ? (json['photos'] as List)
                .whereType<Map>()
                .map((p) => ReviewPhoto.fromJson(Map<String, dynamic>.from(p)))
                .toList(growable: false)
            : const [],
        menuItem: json['menu_item'] is Map
            ? ReviewMenuItem.fromJson(
                Map<String, dynamic>.from(json['menu_item'] as Map))
            : null,
      );
}

/// Phân trang chuẩn của Laravel.
class PageMeta {
  const PageMeta({
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;

  factory PageMeta.fromJson(Map<String, dynamic>? json) => PageMeta(
        currentPage: (json?['current_page'] as num?)?.toInt() ?? 1,
        lastPage: (json?['last_page'] as num?)?.toInt() ?? 1,
        total: (json?['total'] as num?)?.toInt() ?? 0,
      );

  static const empty = PageMeta(currentPage: 1, lastPage: 1, total: 0);
}