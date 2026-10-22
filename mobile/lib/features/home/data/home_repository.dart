import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/dio_client.dart';
import '../domain/home_models.dart';

/// ============================================================
/// 🏠 HOME REPOSITORY — giao tiếp /api/home/*
/// ============================================================
/// Tất cả endpoint home là PUBLIC (theo HomeController routes),
/// không cần Bearer token → dùng [publicGet].
/// ============================================================

class HomeRepository {
  HomeRepository(this._dio);
  final Dio _dio;

  Future<RestaurantInfo> getRestaurant() async {
    final res = await _dio.publicGet<Map<String, dynamic>>('/home/restaurant');
    final data = (res.data?['data'] as Map?)?.cast<String, dynamic>();
    if (data == null) {
      throw const FormatException('Restaurant info rỗng');
    }
    return RestaurantInfo.fromJson(data);
  }

  Future<HomeStats> getStats() async {
    final res = await _dio.publicGet<Map<String, dynamic>>('/home/stats');
    final data = (res.data?['data'] as Map?)?.cast<String, dynamic>();
    if (data == null) throw const FormatException('Stats rỗng');
    return HomeStats.fromJson(data);
  }

  Future<List<HomeCategory>> getCategories() async {
    final res = await _dio.publicGet<Map<String, dynamic>>('/home/categories');
    final list = (res.data?['data'] as List?) ?? [];
    return list
        .map((e) => HomeCategory.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<List<HomeMenuItem>> getFeatured() async {
    final res = await _dio.publicGet<Map<String, dynamic>>('/home/featured');
    final list = (res.data?['data'] as List?) ?? [];
    return list
        .map((e) => HomeMenuItem.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<List<HomeMenuItem>> getBestSellers() async {
    final res =
        await _dio.publicGet<Map<String, dynamic>>('/home/best-sellers');
    final list = (res.data?['data'] as List?) ?? [];
    return list
        .map((e) => HomeMenuItem.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }
}

final homeRepositoryProvider = Provider<HomeRepository>((ref) {
  return HomeRepository(ref.watch(dioProvider));
});
