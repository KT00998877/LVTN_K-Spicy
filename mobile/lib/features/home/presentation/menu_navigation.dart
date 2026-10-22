import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ============================================================
/// 🧭 PENDING MENU FILTERS — chuyển filter từ Home → Menu tab
/// ============================================================
/// Các section ở trang chủ (Hero, Categories, Spicy Level) bấm vào CTA
/// có dạng "Xem món Cấp 3" / "Mì cay". Khi user bấm, ta muốn chuyển sang
/// tab Menu và apply filter luôn — nhưng tab Menu đã có sẵn trong
/// IndexedStack, nên ta push filter vào provider này. UI ở CustomerHomeScreen
/// sẽ đọc và rebuild MenuScreen với filter mới.
/// ============================================================

@immutable
class MenuFilter {
  const MenuFilter({this.categorySlug, this.spicyLevel});

  final String? categorySlug;
  final int? spicyLevel;

  MenuFilter copyWith({Object? categorySlug = _kSentinel, int? spicyLevel}) {
    return MenuFilter(
      categorySlug: identical(categorySlug, _kSentinel)
          ? this.categorySlug
          : categorySlug as String?,
      spicyLevel: spicyLevel ?? this.spicyLevel,
    );
  }

  static const _kSentinel = Object();
}

final pendingMenuFilterProvider =
    StateProvider<MenuFilter?>((ref) => null);
