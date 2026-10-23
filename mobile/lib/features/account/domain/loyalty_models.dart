/// ============================================================
/// 🏆 LOYALTY MODELS — phản ánh /api/loyalty/me + /api/loyalty/tiers
/// ============================================================
library;

/// Quyền lợi của một hạng, đọc từ cột `benefits` (JSON) phía backend.
class TierPerks {
  const TierPerks({
    this.pointMultiplier = 1.0,
    this.freeShipThreshold,
    this.extraDiscountPct = 0.0,
  });

  /// Hệ số nhân điểm (vd: 1.5 = cộng gấp 1.5 lần).
  final double pointMultiplier;

  /// Ngưỡng miễn phí giao hàng. `null` = không có quyền lợi này.
  /// `0` = miễn phí mọi đơn.
  final int? freeShipThreshold;

  /// Giảm thêm %, trọn đời. `0` = không giảm.
  final double extraDiscountPct;

  bool get hasMultiplier => pointMultiplier != 1.0;
  bool get hasFreeShip => freeShipThreshold != null;
  bool get hasDiscount => extraDiscountPct > 0;

  /// `null` khi backend không có quyền lợi này (ẩn dòng trong UI).
  double? get pointMultiplierOrNull => hasMultiplier ? pointMultiplier : null;
  double? get extraDiscountPctOrNull => hasDiscount ? extraDiscountPct : null;

  factory TierPerks.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const TierPerks();
    double asDouble(dynamic v) =>
        v is num ? v.toDouble() : double.tryParse('$v') ?? 0;

    int? asInt(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toInt();
      return int.tryParse('$v');
    }

    return TierPerks(
      pointMultiplier: asDouble(json['point_multiplier'] ?? 1),
      freeShipThreshold: asInt(json['free_ship_threshold']),
      extraDiscountPct: asDouble(json['extra_discount_pct'] ?? 0),
    );
  }
}

/// Một hạng thành viên (Đồng / Bạc / Vàng / Kim cương).
class LoyaltyTier {
  const LoyaltyTier({
    required this.id,
    required this.code,
    required this.name,
    this.minSpent = 0,
    this.color,
    this.icon,
    this.description,
    this.perks = const TierPerks(),
  });

  final int id;
  final String code;
  final String name;

  /// Ngưỡng tổng chi tiêu tối thiểu để đạt hạng này.
  final int minSpent;

  /// Màu ấn định theo hạng (backend trả về, vd "#D4AF37").
  final String? color;

  /// Emoji trang trí do backend cấu hình.
  final String? icon;

  final String? description;
  final TierPerks perks;

  factory LoyaltyTier.fromJson(Map<String, dynamic> json) => LoyaltyTier(
        id: (json['id'] as num?)?.toInt() ?? 0,
        code: (json['code'] ?? '').toString(),
        name: (json['name'] ?? '').toString(),
        minSpent: (json['min_spent'] as num?)?.toInt() ?? 0,
        color: json['color']?.toString(),
        icon: json['icon']?.toString(),
        description: json['description']?.toString(),
        perks: TierPerks.fromJson(
          json['benefits'] is Map
              ? Map<String, dynamic>.from(json['benefits'] as Map)
              : null,
        ),
      );
}

/// Tiến trình tích lũy để lên hạng kế tiếp.
class TierProgress {
  const TierProgress({
    required this.currentSpent,
    required this.nextMinSpent,
    required this.amountToNext,
    required this.percent,
  });

  final int currentSpent;
  final int nextMinSpent;
  final int amountToNext;

  /// 0–100.
  final double percent;

  factory TierProgress.fromJson(Map<String, dynamic> json) => TierProgress(
        currentSpent: (json['current_spent'] as num?)?.toInt() ?? 0,
        nextMinSpent: (json['next_min_spent'] as num?)?.toInt() ?? 0,
        amountToNext: (json['amount_to_next'] as num?)?.toInt() ?? 0,
        percent: (json['percent'] as num?)?.toDouble() ?? 0,
      );
}

/// Response của GET /api/loyalty/me.
class LoyaltyOverview {
  const LoyaltyOverview({
    this.loyaltyPoints = 0,
    this.totalSpent = 0,
    this.current,
    this.next,
    this.progress,
  });

  final int loyaltyPoints;
  final int totalSpent;

  /// Hạng hiện tại. `null` = chưa đạt hạng nào.
  final LoyaltyTier? current;

  /// Hạng kế tiếp. `null` = đã đạt hạng cao nhất.
  final LoyaltyTier? next;
  final TierProgress? progress;

  bool get isMaxTier => next == null;

  factory LoyaltyOverview.fromJson(Map<String, dynamic> json) {
    final user = json['user'];
    return LoyaltyOverview(
      loyaltyPoints: user is Map
          ? ((user['loyalty_points'] as num?)?.toInt() ?? 0)
          : 0,
      totalSpent: (json['total_spent'] as num?)?.toInt() ?? 0,
      current: json['current'] is Map
          ? LoyaltyTier.fromJson(Map<String, dynamic>.from(json['current'] as Map))
          : null,
      next: json['next'] is Map
          ? LoyaltyTier.fromJson(Map<String, dynamic>.from(json['next'] as Map))
          : null,
      progress: json['progress'] is Map
          ? TierProgress.fromJson(
              Map<String, dynamic>.from(json['progress'] as Map))
          : null,
    );
  }

  static const empty = LoyaltyOverview();
}

/// Một dòng trong sổ cái điểm (GET /api/vouchers/loyalty).
class LoyaltyEntry {
  const LoyaltyEntry({
    required this.id,
    required this.points,
    required this.balanceAfter,
    this.reason,
    this.createdAt,
  });

  final int id;

  /// Dương = cộng điểm, âm = dùng điểm.
  final int points;
  final int balanceAfter;
  final String? reason;
  final DateTime? createdAt;

  bool get isEarn => points >= 0;

  factory LoyaltyEntry.fromJson(Map<String, dynamic> json) => LoyaltyEntry(
        id: (json['id'] as num?)?.toInt() ?? 0,
        points: (json['points'] as num?)?.toInt() ?? 0,
        balanceAfter: (json['balance_after'] as num?)?.toInt() ?? 0,
        reason: json['reason']?.toString(),
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      );
}