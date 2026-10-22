import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../domain/menu_models.dart';

/// ============================================================
/// 🍜 MENU REPOSITORY — giao tiếp /api/menu
/// ============================================================
class MenuRepository {
  MenuRepository(this._dio);
  final Dio _dio;

  /// GET /api/menu
  Future<List<MenuItem>> list({
    String? categorySlug,
    String? search,
    int? spicyLevel,
    String? sort,
  }) async {
    try {
      final res = await _dio.publicGet<Map<String, dynamic>>(
        '/menu',
        query: {
          if (categorySlug != null && categorySlug.isNotEmpty)
            'category': categorySlug,
          if (search != null && search.trim().isNotEmpty)
            'search': search.trim(),
          if (spicyLevel != null) 'spicy_level': spicyLevel,
          if (sort != null) 'sort': sort,
        },
      );
      final data = res.data?['data'] as List? ?? [];
      return data
          .map((e) => MenuItem.fromJson(e as Map<String, dynamic>))
          .toList();
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/menu/{slug}
  Future<MenuItem> getBySlug(String slug) async {
    try {
      final res = await _dio.publicGet<Map<String, dynamic>>('/menu/$slug');
      final data = res.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        throw const ApiException(message: 'Không tìm thấy món ăn');
      }
      return MenuItem.fromJson(data);
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }

  /// GET /api/cart/toppings
  Future<List<Topping>> getToppings() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('/cart/toppings');
      final data = res.data?['data'] as List? ?? [];
      return data
          .map((e) => Topping.fromJson(e as Map<String, dynamic>))
          .toList();
    } on DioException catch (e) {
      throw apiExceptionFromDio(e);
    }
  }
}

final menuRepositoryProvider = Provider<MenuRepository>((ref) {
  return MenuRepository(ref.watch(dioProvider));
});
